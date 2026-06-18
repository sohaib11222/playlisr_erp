<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;

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
                    'max_tokens' => 700,
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
- Be brief and practical. Give numbered steps for a task, not essays.
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

        return $base . "\n\n=== STORE OPERATIONS NOTES (maintained by Nivessa managers) ===\n" . $notes;
    }
}
