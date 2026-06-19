<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Powers the floating "Ask the ERP" help widget. Employees type a question
 * ("how do I add a product?", "where do I see today's sales?") and get a
 * short, step-by-step answer grounded in the knowledge base below.
 *
 * No database, no migrations: conversation lives in the browser only and is
 * forwarded each turn. If ANTHROPIC_API_KEY is missing the endpoint returns a
 * graceful fallback so the widget never errors out.
 */
class HelpAssistantController extends Controller
{
    const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

    public function message(Request $request)
    {
        $fallback = "I can't reach the help assistant right now. Ask a manager, or check the sidebar menu for the section you need.";

        // Key comes from the in-ERP settings page first (storage/app, no SSH
        // needed), then falls back to .env for anyone who prefers that.
        $cfg = HelpAssistantSettingsController::readConfig();
        $apiKey = $cfg['api_key'] ?? null;
        $apiKey = $apiKey ?: config('services.anthropic.api_key') ?: env('ANTHROPIC_API_KEY') ?: getenv('ANTHROPIC_API_KEY');
        if (empty($apiKey)) {
            return response()->json(['reply' => "Setup needed: a manager needs to add the AI key at /admin/help-assistant before I can answer. Once it's saved there, I'll work right away."]);
        }
        $model = $cfg['model'] ?? config('services.anthropic.model', 'claude-haiku-4-5');

        $history = $request->input('messages', []);
        if (!is_array($history)) {
            $history = [];
        }

        // Keep only valid user/assistant turns, cap length and depth.
        $messages = [];
        foreach ($history as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = $m['role'] ?? '';
            $content = $m['content'] ?? '';
            if (($role === 'user' || $role === 'assistant') && is_string($content) && trim($content) !== '') {
                $messages[] = [
                    'role' => $role,
                    'content' => mb_substr($content, 0, 2000),
                ];
            }
        }
        $messages = array_slice($messages, -10);

        if (empty($messages) || end($messages)['role'] !== 'user') {
            return response()->json(['reply' => $fallback]);
        }

        try {
            $client = new Client(['timeout' => 30]);
            $response = $client->post(self::ANTHROPIC_URL, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'max_tokens' => 350,
                    'system' => [
                        [
                            'type' => 'text',
                            'text' => $this->knowledgeBase($cfg['store_knowledge'] ?? ''),
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                    ],
                    'messages' => array_values($messages),
                ],
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                $errBody = $response->getBody()->getContents();
                $errData = json_decode($errBody, true);
                $errType = $errData['error']['type'] ?? '';
                $errMsg = $errData['error']['message'] ?? '';
                \Log::warning('HelpAssistant Anthropic error', ['status' => $status, 'type' => $errType, 'message' => $errMsg]);

                if ($status === 401 || $errType === 'authentication_error') {
                    return response()->json(['reply' => "The AI key on the server was rejected (authentication error). The ANTHROPIC_API_KEY in .env is likely wrong or revoked."]);
                }
                if ($status === 400 && stripos($errMsg, 'credit') !== false) {
                    return response()->json(['reply' => "The AI account is out of credit. Top up the Anthropic account, then I'll work again."]);
                }
                return response()->json(['reply' => "The AI service returned an error (status {$status}" . ($errType ? ", {$errType}" : '') . "). " . ($errMsg ?: 'Try again shortly.')]);
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $reply = '';
            if (!empty($data['content']) && is_array($data['content'])) {
                foreach ($data['content'] as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $reply .= $block['text'];
                    }
                }
            }
            $reply = trim($reply);

            return response()->json(['reply' => $reply !== '' ? $reply : $fallback]);
        } catch (\Exception $e) {
            \Log::warning('HelpAssistant request failed', ['error' => $e->getMessage()]);
            return response()->json(['reply' => "Couldn't reach the AI service from the server (network/timeout). The server may be blocking outbound HTTPS to api.anthropic.com."]);
        }
    }

    /**
     * Cached system prompt: who the assistant is + a map of where things live
     * in this ERP. Grounded in the real sidebar menu so directions are accurate.
     */
    private function knowledgeBase($storeKnowledge = '')
    {
        $base = <<<'KB'
You are the in-app help assistant for Nivessa staff. You help employees figure out
two kinds of things: (1) HOW to do tasks in the Nivessa ERP (a point-of-sale and
inventory system at playlist.nivessa.com, built on the UltimatePOS framework), and
(2) how the store actually runs day to day — supplies, the printer, events, listening
parties, Spotify, etc. — using the STORE OPERATIONS notes at the end of this prompt.

Style rules:
- BE VERY BRIEF. Most answers are 1-3 short sentences or up to 5 short numbered steps.
  No title/heading, no intro, no recap of the question, no closing remark. Just the answer.
- Do not bold every other word or pad with extra detail. Plainest, shortest answer that works.
- For ERP tasks, name the exact sidebar menu path, e.g. "Sell > List Sales" or "Products > Add Product".
- For store-operations questions (supplies, printer, events, listening party, Spotify),
  answer from the STORE OPERATIONS notes below. If the relevant note still says
  "[FILL IN ...]" or isn't covered, say that detail hasn't been filled in yet and to
  ask a manager — do NOT guess an answer.
- If you are not sure where an ERP feature is, say so honestly and suggest the closest
  menu section rather than inventing a path or URL.
- Never invent menu items, buttons, URLs, locations, phone numbers, or schedules that
  aren't given to you here.
- Plain text only. No emojis.

How the ERP is organized (left sidebar menu):
- Home / Dashboard: today's sales, profit, and shortcuts.
- Sales:
  - "POS" / "Add Sale" → ring up a customer at the register (the POS Create screen).
  - "List POS" / "List Sales" → view, search, edit, print, or refund past sales.
  - "Add Draft", "List Drafts", "Quotations" → save a cart for later or send a quote.
  - "Shipments" → orders to be shipped/fulfilled.
- Products:
  - "List Products" → search inventory, edit price/stock, print labels.
  - "Add Product" → create a new item (name, SKU/barcode, category, brand, price, stock).
  - "Print Labels" → barcode label printing.
  - "Variations", "Categories", "Brands", "Units", "Selling Price Groups".
  - "Import Products" → bulk add via spreadsheet.
- Purchases:
  - "Add Purchase" → record stock bought from a supplier (increases inventory).
  - "List Purchases", "Purchase Return".
- Stock Transfers / Stock Adjustments → move stock between locations or correct counts.
- Contacts:
  - "Customers" and "Suppliers" → add/edit, view purchase history and loyalty points.
- Reports: Profit/Loss, Sales report, Purchase & Sale, Stock report, Trending products,
  Tax report, Register report, Expense report, Customer/Supplier reports, and others.
- Expenses: "Add Expense", "List Expenses".
- Cash Register: open/close the register, view the register summary for a shift.
- Settings (admin only): Business Settings, Tax Rates, Locations, Invoice Settings,
  Users & Roles, Reward Point / loyalty settings.

Common tasks and how to do them:
- Ring up a sale: Sales > POS (or "Add Sale"). Scan/search the item to add it to the
  cart, set quantity, choose the customer (or Walk-In), pick payment method
  (cash/card), then Finalize/Pay. Print or email the receipt.
- Apply a discount: discounts are manager-approved only. On the POS screen use the
  discount field; cashiers ring the sticker price as-is unless a manager authorizes it.
- Refund or return a sale: open Sell > List Sales (or List POS), find the sale, open
  its Actions menu and choose "Sell Return". Enter the quantity/amount to return and
  save. Refunds are manager-approved (a manager sign-off is required), and it prints a
  return receipt. Also check the STORE OPERATIONS notes for any Nivessa refund rules.
- Add a new product: Products > Add Product. Fill in name, category, brand, barcode/SKU,
  purchase price, selling price, and opening stock, then save.
- Receive new stock from a supplier: Purchases > Add Purchase. Pick the supplier and
  location, add the products and quantities/costs, and save — this raises stock.
- Correct a stock count: Stock Adjustment > add adjustment for that location.
- Look up a customer's loyalty points / history: Contacts > Customers, open the customer.
- See today's or this week's sales: Home dashboard, or Reports > Sales / Register report.
- Open or close the register for your shift: Cash Register section; each cashier closes
  their own register at the end of their shift.
- Print barcode labels: Products > Print Labels, pick the products and label sheet.

If someone asks something you genuinely don't know how to do in this ERP, tell them
which menu section to look under and suggest checking with a manager.
KB;

        $notes = trim($storeKnowledge);
        if ($notes === '') {
            $notes = "(No store-operations notes have been added yet. For questions about supplies, the printer, events, listening parties, or Spotify, tell the employee these haven't been filled in yet and to ask a manager.)";
        }

        $supplies = SuppliesController::formatForBot();
        $requests = SupplyRequestController::formatForBot();
        $handbook = $this->handbookForBot();

        return $base
            . "\n\n=== STAFF HANDBOOK INDEX (the articles staff see at Help & Handbook / playlist.nivessa.com/help) ===\n"
            . "Each line is one handbook article: title, section, a one-line summary, and the link to its full step-by-step guide. Answer 'how do I...' questions from the summary, and ALWAYS point staff to the matching 'full guide' link for the complete instructions:\n"
            . $handbook
            . "\n\n=== UPCOMING EVENTS (live, from the Nivessa events Google Calendar) ===\n"
            . "Use this to answer who is performing / playing and when, and what's coming up at the stores. Times are store-local (Los Angeles). If a question asks about an event not listed here, say it's not on the upcoming calendar and to check with a manager:\n"
            . $this->eventsForBot()
            . "\n\n=== LISTENING PARTY PREP (rules & procedures; from /admin/listening-party-prep) ===\n"
            . self::LISTENING_PARTY_RULES
            . "\n\n=== STORE OPERATIONS NOTES (maintained by Nivessa managers) ===\n" . $notes
            . "\n\n=== CURRENT SUPPLIES STATUS (live; managers update at /admin/supplies) ===\n"
            . "Use this to answer whether we're low/out of something and when the next restock is due:\n"
            . $supplies
            . "\n\n=== OPEN SUPPLY REQUESTS (live; staff submit at Supplies > Request a Supply) ===\n"
            . "Use this to answer 'did my request get ordered / when's it coming'. To request a new supply, tell staff to open Supplies > Request a Supply in the left sidebar:\n"
            . $requests;
    }

    /**
     * The staff handbook (App\Help\Catalog) as a compact title + summary index.
     * We deliberately drop the full article bodies: the cached system prompt has
     * to stay under the org's per-minute input-token rate limit, and the full
     * text of every article blows past it. The bot answers from the summary and
     * points staff to the matching article at /help for step-by-step detail.
     */
    private function handbookForBot()
    {
        if (!class_exists(\App\Help\Catalog::class)) {
            return "(Handbook unavailable.)";
        }

        $lines = [];
        foreach (\App\Help\Catalog::articles() as $a) {
            $title = $a['title'] ?? 'Article';
            $section = $a['section'] ?? '';
            $summary = trim((string) ($a['summary'] ?? ''));
            $slug = $a['slug'] ?? '';

            $line = "- {$title}" . ($section ? " [{$section}]" : '');
            if ($summary !== '') {
                $line .= ": {$summary}";
            }
            if ($slug !== '') {
                $line .= " (full guide: /help/{$slug})";
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    // Public ICS feed for the Nivessa events Google Calendar (the calendar Sarah
    // shares). Read-only and public — no auth needed.
    const EVENTS_ICS_URL = 'https://calendar.google.com/calendar/ical/f85396a19930116f27c80b16ca85e8e15ea90677053b565ddd390ba649851b74%40group.calendar.google.com/public/basic.ics';
    const EVENTS_TZ = 'America/Los_Angeles';

    /**
     * The next handful of upcoming events from the public Google Calendar, one
     * compact line each ("Mon Jun 23, 7:00 PM — Artist"). Cached for 30 min so
     * we don't fetch the (large) ICS on every chat turn, and fully wrapped so a
     * calendar/network failure never breaks the assistant.
     */
    private function eventsForBot()
    {
        try {
            return Cache::remember('help_assistant_events', 1800, function () {
                $ics = (new Client())->get(self::EVENTS_ICS_URL, ['timeout' => 6])
                    ->getBody()->getContents();

                return $this->formatEventsFromIcs($ics);
            });
        } catch (\Throwable $e) {
            \Log::warning('help assistant calendar fetch failed: ' . $e->getMessage());

            return "(Couldn't load the events calendar right now — check the calendar directly or ask a manager.)";
        }
    }

    /**
     * Parse an ICS string into the next ~15 upcoming, non-cancelled events.
     */
    private function formatEventsFromIcs($ics)
    {
        // RFC5545 line unfolding: a CRLF followed by a space/tab continues the
        // previous line.
        $unfolded = preg_replace("/\r?\n[ \t]/", '', (string) $ics);
        $lines = preg_split("/\r?\n/", $unfolded);

        $tz = new \DateTimeZone(self::EVENTS_TZ);
        $now = new \DateTime('now', $tz);
        $events = [];
        $cur = null;

        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $cur = ['summary' => '', 'start' => null, 'allDay' => false];
            } elseif ($line === 'END:VEVENT') {
                if ($cur && $cur['start'] && stripos($cur['summary'], 'canceled') !== 0
                    && stripos($cur['summary'], 'cancelled') !== 0) {
                    $events[] = $cur;
                }
                $cur = null;
            } elseif ($cur !== null) {
                if (strpos($line, 'SUMMARY') === 0 && ($p = strpos($line, ':')) !== false) {
                    $cur['summary'] = trim($this->icsUnescape(substr($line, $p + 1)));
                } elseif (strpos($line, 'DTSTART') === 0 && ($p = strpos($line, ':')) !== false) {
                    [$dt, $allDay] = $this->parseIcsDate(substr($line, 0, $p), substr($line, $p + 1), $tz);
                    if ($dt) {
                        $cur['start'] = $dt;
                        $cur['allDay'] = $allDay;
                    }
                }
            }
        }

        // Future only, soonest first.
        $events = array_filter($events, function ($e) use ($now) {
            return $e['start'] >= $now;
        });
        usort($events, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });
        $events = array_slice($events, 0, 15);

        if (empty($events)) {
            return "(No upcoming events on the calendar.)";
        }

        $out = [];
        foreach ($events as $e) {
            $when = $e['allDay']
                ? $e['start']->format('D M j') . ' (all day)'
                : $e['start']->format('D M j, g:i A');
            $out[] = "- {$when} — {$e['summary']}";
        }

        return implode("\n", $out);
    }

    /**
     * Parse an ICS DTSTART value. Handles "DTSTART;VALUE=DATE:20260623" (all
     * day), UTC "20260623T030000Z", and floating "20260623T190000" (treated as
     * already store-local). Returns [\DateTime|null, bool $allDay].
     */
    private function parseIcsDate($prop, $value, \DateTimeZone $tz)
    {
        $value = trim($value);

        if (stripos($prop, 'VALUE=DATE') !== false || preg_match('/^\d{8}$/', $value)) {
            $dt = \DateTime::createFromFormat('Ymd', substr($value, 0, 8), $tz);

            return [$dt ?: null, true];
        }

        if (substr($value, -1) === 'Z') {
            $dt = \DateTime::createFromFormat('Ymd\THis\Z', $value, new \DateTimeZone('UTC'));
            if ($dt) {
                $dt->setTimezone($tz);
            }

            return [$dt ?: null, false];
        }

        $dt = \DateTime::createFromFormat('Ymd\THis', $value, $tz);

        return [$dt ?: null, false];
    }

    private function icsUnescape($s)
    {
        return str_replace(['\\,', '\\;', '\\n', '\\N', '\\\\'], [',', ';', "\n", "\n", '\\'], $s);
    }

    // Canonical listening-party prep checklist, mirrored from the website's
    // /admin/listening-party-prep page (src/lib/listeningPartyPrep.js). Rules &
    // procedures only — per-event details (host, box location, playback link)
    // live on the website and are filled in per party there.
    const LISTENING_PARTY_RULES = <<<'LP'
Run through this before every listening party. The dates below are how far ahead each step is due (the website page shows exact dates per event). The giveaway box is stored in the employee room at each participating store.

- About 2 weeks before: Confirm both stores are approved to participate.
- About 1 week before: Post the event on social media once Carrie sends the assets. Timing matters — do NOT announce earlier than RSD does. Schedule posts in PST.
- About 5 days before: Confirm the giveaway box(es) have arrived. Verify with one employee at each participating store, and have them label each box with the event name and date.
- About 4 days before: Get a photo of the giveaway box in the employee room to confirm it's there before the event.
- About 3 days before: Review RSVPs and confirm enough staff are scheduled.
- About 2 days before: Review all event rules with the host/designated employee: giveaway procedures; preorder procedures (if applicable); purchase / release-day rules; any special instructions from the label or Carrie.
- About 1 day before: Share the playback link with the person hosting.
- Day of the event: The host must test the playback link at least 1 hour before the party — confirm the audio works. Do not wait until guests arrive to test it.

If someone asks for a specific party's details (who's hosting, where this party's box is, the playback link), those are filled in per event on the website's Listening Party Prep page — point them there or to a manager.
LP;
}
