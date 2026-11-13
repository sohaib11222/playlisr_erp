<?php

namespace App\Utils;

use App\Services\WebsiteBridge;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\WebsiteOrdersController;
use App\Http\Controllers\WebsiteAdmin\WebsiteBookingsController;
use Carbon\Carbon;

/**
 * Open Items: one list of everything still waiting on someone, so nothing
 * falls through the cracks. Each section reads live data (ERP tables or the
 * website bridge) and returns rows with how long they've waited and a link
 * to the screen where they get closed out. Nothing here writes anything.
 */
class OpenItemsUtil
{
    const TZ = 'America/Los_Angeles';
    const RECENT_DAYS = 14;

    /** All sections, in the order they should be worked. */
    public static function sections(int $business_id): array
    {
        $out = [];
        foreach ([
            'unhappy'   => fn () => self::unhappyCustomers($business_id),
            'staff'     => fn () => self::staffNotReady($business_id),
            'sellers'   => fn () => self::sellerLeads(),
            'orders'    => fn () => self::stuckOrders(),
            'discogs'   => fn () => self::discogsOrders(),
            'customers' => fn () => self::customersWaiting($business_id),
            'contact'   => fn () => self::contactForms(),
            'events'    => fn () => self::eventPrep($business_id),
            'buys'      => fn () => self::draftBuys($business_id),
            'bookings'  => fn () => self::unpaidBookings(),
        ] as $key => $fn) {
            try {
                $out[$key] = $fn();
            } catch (\Throwable $e) {
                \Log::warning('open-items ' . $key . ' failed: ' . $e->getMessage());
                $names = ['sellers' => 'Seller leads', 'discogs' => 'Discogs orders', 'staff' => 'Staff not set up', 'customers' => 'Customers waiting', 'unhappy' => 'Unhappy customers', 'orders' => 'Website orders stuck', 'contact' => 'Contact form messages', 'bookings' => 'Venue booking requests', 'buys' => 'Buys left as drafts', 'events' => 'Listening party prep'];
                $out[$key] = self::section($names[$key] ?? $key, '', '', [], 'Could not load this right now.');
            }
            // Only the last 2 weeks; older ones are counted, not listed.
            // Staff and event prep are about upcoming dates, so they are never cut.
            $out[$key]['older'] = 0;
            if (!in_array($key, ['staff', 'events'], true)) {
                $recent = array_values(array_filter($out[$key]['rows'], fn ($r) => $r['hours'] <= self::RECENT_DAYS * 24));
                $out[$key]['older'] = count($out[$key]['rows']) - count($recent);
                $out[$key]['rows'] = $recent;
            }
            $out[$key]['title'] = trim(preg_replace('/\s*\(last \d+ days\)/', '', $out[$key]['title']));
        }
        return $out;
    }

    public static function total(array $sections): int
    {
        return array_sum(array_map(fn ($s) => count($s['rows']), $sections));
    }

    protected static function section(string $title, string $how, string $link, array $rows, ?string $error = null): array
    {
        return ['title' => $title, 'how' => $how, 'link' => $link, 'rows' => $rows, 'error' => $error];
    }

    protected static function row($who, $what, $since, string $link, string $key = ''): array
    {
        $who = (string) $who;
        // Quo mirror tags like <!--quo-msg-...--> are not part of the message.
        $what = trim(preg_replace('/<!--.*?-->/s', '', (string) $what));
        $ts = $since ? Carbon::parse($since)->setTimezone(self::TZ) : null;
        return [
            'who'   => trim($who) !== '' ? trim($who) : 'Unknown',
            'what'  => mb_strimwidth(trim(preg_replace('/\s+/', ' ', $what)), 0, 140, '...'),
            'since' => $ts ? $ts->format('m/d/y') : '',
            'age'   => $ts ? self::age($ts) : '',
            'hours' => $ts ? $ts->diffInHours(Carbon::now(self::TZ)) : 0,
            'link'  => $link,
            'key'   => $key,
        ];
    }

    protected static function age(Carbon $ts): string
    {
        $h = $ts->diffInHours(Carbon::now(self::TZ));
        if ($h < 48) {
            return $h . 'h';
        }
        return intdiv($h, 24) . ' days';
    }

    protected static function oldestFirst(array $rows): array
    {
        usort($rows, fn ($a, $b) => $b['hours'] <=> $a['hours']);
        return $rows;
    }

    /** Texts that never need a reply: login codes, opt-outs, automated notices. */
    protected static function isNoise(string $msg): bool
    {
        $m = strtolower(preg_replace('/<!--.*?-->/s', '', $msg));
        return (bool) preg_match('/verification code|two-factor|is your .{0,20}code|whatsapp business account|account confirmation request|\\bstop\\b|^\\s*(\\[[^\\]]*\\]\\s*)?(ok|ok\\.? thanks|thanks|thank you|liked .*)\\W*$/s', $m);
    }

    /** Quo texts and calls with no reply after 24 hours (last 30 days). */
    public static function customersWaiting(int $business_id): array
    {
        $rows = \DB::table('communications')
            ->where('business_id', $business_id)
            ->whereIn('channel', ['phone_1', 'phone_2'])
            ->where('status', 'pending')
            ->where('topic', '!=', 'unhappy_customer')
            ->where(function ($q) {
                $q->whereNull('resolution_notes')->orWhere('resolution_notes', '');
            })
            ->where('created_at', '<', now()->subDay())
            ->where('created_at', '>=', now()->subDays(30))
            ->where('message', '!=', '(no inbound message logged for this contact)')
            ->orderBy('created_at')
            ->limit(100)
            ->get(['id', 'customer_name', 'contact_info', 'message', 'created_at']);
        $out = [];
        foreach ($rows as $r) {
            // Toll-free 833/844/855 numbers calling us are robocalls.
            if (self::isNoise((string) $r->message) || preg_match('/^\+?1?8(33|44|55|66|77|88)/', (string) $r->contact_info) || stripos((string) $r->contact_info, 'anonymous') !== false || stripos((string) $r->customer_name, 'anonymous') !== false) {
                continue;
            }
            $out[] = self::row($r->customer_name ?: $r->contact_info, (string) $r->message, $r->created_at, url('/communications'), 'comm:' . $r->id);
        }
        return self::section('Customers waiting over 24 hours', 'Reply, or mark it resolved if it was handled another way.', url('/communications'), self::oldestFirst($out));
    }

    /** Anything tagged "unhappy customer" that is still open. */
    public static function unhappyCustomers(int $business_id): array
    {
        $rows = \DB::table('communications')
            ->where('business_id', $business_id)
            ->where('topic', 'unhappy_customer')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDays(45))
            ->orderBy('created_at')
            ->limit(100)
            ->get(['id', 'customer_name', 'contact_info', 'message', 'created_at']);
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::row($r->customer_name ?: $r->contact_info, (string) $r->message, $r->created_at, url('/communications'), 'comm:' . $r->id);
        }
        return self::section('Unhappy customers not resolved (last 45 days)', 'Call them, fix it, then mark it resolved with what you did.', url('/communications'), self::oldestFirst($out));
    }

    /** Paid website orders still open after 3 days, plus every "can't find" order. */
    public static function stuckOrders(): array
    {
        $resp = \Cache::remember('open_items_web_orders', 300, function () {
            return app(WebsiteOrdersController::class)->bridgeGet('/erp/orders/console?limit=300&payment_status=completed', 25);
        });
        if (!is_array($resp) || !isset($resp['data'])) {
            \Cache::forget('open_items_web_orders');
            return self::section('Website orders stuck (last 60 days)', '', url('/website-orders'), [], 'Could not reach the website right now.');
        }
        $cut = now()->subDays(3)->timestamp;
        $out = [];
        foreach ((array) $resp['data'] as $o) {
            if (!is_array($o)) {
                continue;
            }
            $id = (string) ($o['_id'] ?? '');
            $created = strtotime((string) ($o['createdAt'] ?? '')) ?: 0;
            if (!$created || $created < now()->subDays(60)->timestamp) {
                continue;
            }
            $name = (string) (($o['display_name'] ?? '') ?: (($o['shippingAddress'][0]['name'] ?? '') ?: ($o['user_id']['name'] ?? '')));
            $name = trim((!empty($o['orderNumber']) ? '#' . $o['orderNumber'] . ' ' : '') . $name);
            if (WebsiteOrdersController::inTab($o, 'cant_find')) {
                $out[] = self::row($name, "Can't find: recheck the bins, offer a swap, refund only as a last step", $o['createdAt'] ?? null, url('/website-orders?order=' . $id), 'order:' . $id);
            } elseif ($created < $cut && WebsiteOrdersController::inTab($o, 'needs_action')) {
                $how = ($o['fulfillment_method'] ?? '') === 'pickup' ? 'Pickup order not ready' : 'Not shipped';
                $out[] = self::row($name, $how . ' after ' . intdiv(time() - $created, 86400) . ' days', $o['createdAt'] ?? null, url('/website-orders?order=' . $id), 'order:' . $id);
            }
        }
        return self::section('Website orders stuck (last 60 days)', 'Text the person on shift with the order link. No answer in 2 hours, call them, then the manager.', url('/website-orders?tab=needs_action'), self::oldestFirst($out));
    }

    const SELLER_RE = '/\b(for sale|sell(ing)?|collection|bulk|library|estate|records? to sell|lot of)\b/i';

    /** Contact form messages still "New" in the last 30 days, spam removed. Null if the website is down. */
    protected static function newContactMessages(): ?array
    {
        $resp = \Cache::remember('open_items_contact', 300, fn () => WebsiteBridge::admin('GET', '/customer/messages'));
        if (empty($resp['success'])) {
            \Cache::forget('open_items_contact');
            return null;
        }
        $out = [];
        foreach ((array) ($resp['data'] ?? []) as $m) {
            if (!is_array($m) || ($m['status'] ?? 'new') !== 'new') {
                continue;
            }
            $at = strtotime((string) ($m['createdAt'] ?? '')) ?: 0;
            if ($at < now()->subDays(30)->timestamp) {
                continue;
            }
            $blob = strtolower(($m['subject'] ?? '') . ' ' . ($m['message'] ?? ''));
            if (preg_match('/virtual assistant|explainer video|social media manager|seo|funding|leads for|streamlining your workflow|transparency certificate|homepage|free set of new ads/', $blob)) {
                continue;
            }
            $m['_seller'] = (bool) preg_match(self::SELLER_RE, $blob) && !preg_match('/\b(order|refund|ship|tracking)\b/', $blob);
            $out[] = $m;
        }
        return $out;
    }

    protected static function contactRows(array $msgs): array
    {
        $out = [];
        foreach ($msgs as $m) {
            $what = trim(($m['subject'] ?? '') . ': ' . ($m['message'] ?? ''), ': ');
            $out[] = self::row((string) ($m['name'] ?? $m['email'] ?? ''), $what, $m['createdAt'] ?? null, url('/website-inbox/messages'), 'contact:' . ($m['_id'] ?? md5($what)));
        }
        return self::oldestFirst($out);
    }

    /** People offering us records. The biggest money on the page. */
    public static function sellerLeads(): array
    {
        $msgs = self::newContactMessages();
        if ($msgs === null) {
            return self::section('Seller leads', '', url('/website-inbox/messages'), [], 'Could not reach the website right now.');
        }
        $rows = self::contactRows(array_filter($msgs, fn ($m) => $m['_seller']));
        return self::section('Seller leads', 'Call or email the same day, get photos and a rough count, and book a time for them to bring it in (or a pickup). Done = a date is booked or they said no.', url('/buy-from-customer'), $rows);
    }

    /** Website contact form messages nobody has replied to. */
    public static function contactForms(): array
    {
        $msgs = self::newContactMessages();
        if ($msgs === null) {
            return self::section('Contact form messages', '', url('/website-inbox/messages'), [], 'Could not reach the website right now.');
        }
        $rows = self::contactRows(array_filter($msgs, fn ($m) => !$m['_seller']));
        return self::section('Contact form messages not answered (last 30 days)', 'Answer it yourself from the Website Inbox with a real answer (stock, price, date), not a hand-off.', url('/website-inbox/messages'), $rows);
    }

    /** Discogs orders over 30 days old still not shipped, and buyers we owe an answer. */
    public static function discogsOrders(): array
    {
        $ctl = app(WebsiteOrdersController::class);
        $old = \Cache::remember('open_items_discogs_old', 300, fn () => $ctl->bridgeGet('/erp/discogs/to-ship?old=1', 20));
        $wait = \Cache::remember('open_items_discogs_wait', 300, fn () => $ctl->bridgeGet('/erp/discogs/waiting-on-buyer', 25));
        if (empty($old['success']) && empty($wait['success'])) {
            \Cache::forget('open_items_discogs_old');
            \Cache::forget('open_items_discogs_wait');
            return self::section('Discogs orders', '', url('/website-orders?tab=discogs_old'), [], 'Could not reach Discogs right now.');
        }
        $out = [];
        foreach ((array) ($old['data'] ?? []) as $d) {
            if (is_array($d)) {
                $out[] = self::row((string) ($d['buyer'] ?? ''), 'Paid ' . ($d['id'] ?? '') . ', not shipped after 30+ days', $d['order_date'] ?? $d['created'] ?? null, url('/website-orders?tab=discogs_old'), 'discogs:' . ($d['id'] ?? ''));
            }
        }
        foreach ((array) ($wait['data'] ?? []) as $d) {
            if (is_array($d)) {
                $r = self::row((string) ($d['buyer'] ?? ''), "We said we can't find it, buyer hasn't answered: refund or send a substitute " . ($d['id'] ?? ''), null, url('/website-orders?tab=discogs_old'), 'discogs:' . ($d['id'] ?? ''));
                $days = (int) ($d['days_waiting'] ?? 0);
                $r['age'] = $days . ' days';
                $r['hours'] = $days * 24;
                $out[] = $r;
            }
        }
        return self::section('Discogs orders', 'Ship it, or refund it the same day. Discogs buyers leave public feedback.', url('/website-orders?tab=discogs_old'), self::oldestFirst($out));
    }

    /** Venue booking requests that never got paid. */
    public static function unpaidBookings(): array
    {
        $rows = \Cache::remember('open_items_bookings', 300, function () {
            $err = null;
            $r = WebsiteBookingsController::unpaidRequests($err);
            return $err ? null : $r;
        });
        if ($rows === null) {
            \Cache::forget('open_items_bookings');
            return self::section('Venue booking requests not paid', '', url('/website-carts?tab=bookings'), [], 'Could not reach the website right now.');
        }
        $out = [];
        foreach ($rows as $b) {
            if (preg_match('/^(admin|test)/i', trim($b['customerName'])) || preg_match('/^test/i', trim($b['customerEmail']))) {
                continue;
            }
            $out[] = self::row($b['customerName'] ?: $b['customerEmail'], trim($b['venueName'] . ' ' . $b['title']), $b['createdAt'] ?: null, url('/website-carts?tab=bookings'), 'booking:' . $b['id']);
        }
        return self::section('Venue booking requests not paid', 'Call or email them to finish the booking, or find out why not.', url('/website-carts?tab=bookings'), self::oldestFirst($out));
    }

    /** Buys left as drafts for over a day (last 7 days). */
    public static function draftBuys(int $business_id): array
    {
        $rows = \DB::table('buy_customer_offers as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.created_by')
            ->leftJoin('business_locations as l', 'l.id', '=', 'b.location_id')
            ->where('b.business_id', $business_id)
            ->where('b.status', 'draft')
            ->where('b.created_at', '<', now()->subDay())
            ->where('b.created_at', '>=', now()->subDays(7))
            // Skip drafts for a seller who was bought from (or turned down) later.
            ->whereNotExists(function ($q) {
                $q->select(\DB::raw(1))->from('buy_customer_offers as d')
                    ->whereColumn('d.business_id', 'b.business_id')
                    ->whereIn('d.status', ['accepted', 'rejected'])
                    ->whereColumn('d.created_at', '>=', 'b.created_at')
                    ->where(function ($w) {
                        $w->where(function ($x) {
                            $x->whereNotNull('b.seller_phone')->where('b.seller_phone', '!=', '')->whereColumn('d.seller_phone', 'b.seller_phone');
                        })->orWhere(function ($x) {
                            $x->whereNotNull('b.contact_id')->whereColumn('d.contact_id', 'b.contact_id');
                        });
                    });
            })
            ->orderBy('b.created_at')
            ->limit(100)
            ->get(['b.id', 'b.seller_name', 'b.seller_phone', 'b.final_offer_cash', 'b.created_at', 'u.first_name', 'l.name as store']);
        $out = [];
        foreach ($rows as $r) {
            $what = 'Draft by ' . ($r->first_name ?: '?') . ($r->store ? ' at ' . $r->store : '');
            $cash = (float) $r->final_offer_cash;
            if ($cash > 0 && $cash < 20000) {
                $what .= ', $' . number_format($cash, 2) . ' cash';
            }
            $out[] = self::row($r->seller_name ?: $r->seller_phone, $what, $r->created_at, url('/buy-from-customer/' . $r->id . '/edit'), 'buy:' . $r->id);
        }
        return self::section('Buys left as drafts (last 7 days)', 'Ask the cashier: did we buy it? Then Accept or Reject so the drawer and stock are right.', url('/buy-from-customer/history'), self::oldestFirst($out));
    }

    /** Event prep steps past their due day for events in the next 2 weeks. */
    public static function eventPrep(int $business_id): array
    {
        $data = EventsController::load($business_id);
        $today = Carbon::now(self::TZ)->startOfDay();
        $out = [];
        foreach ((array) ($data['items'] ?? []) as $id => $ev) {
            // The prep checklist (giveaway box, playback link) is for listening parties.
            if (!is_array($ev) || ($ev['eventType'] ?? '') !== 'listening_party') {
                continue;
            }
            $date = (string) ($ev['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $evDay = Carbon::parse($date, self::TZ)->startOfDay();
            if ($evDay->lt($today) || $evDay->gt($today->copy()->addDays(14))) {
                continue;
            }
            $done = (array) ($ev['prepChecklist'] ?? []);
            $late = [];
            foreach (EventsController::prepItemsForEvent($ev) as $it) {
                if (!empty($done[$it['id']]['done'])) {
                    continue;
                }
                if ($evDay->copy()->subDays((int) $it['due'])->lte($today)) {
                    $late[] = $it['label'];
                }
            }
            if ($late) {
                $eid = (string) ($ev['id'] ?? $id);
                $name = (string) ($ev['name'] ?? $ev['title'] ?? 'Event');
                $what = count($late) . ' step' . (count($late) === 1 ? '' : 's') . ' due: ' . implode(' / ', $late);
                $r = self::row($name, $what, null, url('/events/' . $eid . '/edit'), 'prep:' . $eid . ':' . $today->format('Y-m-d'));
                $r['since'] = $evDay->format('m/d/y');
                $r['age'] = $evDay->isSameDay($today) ? 'today' : ($today->diffInDays($evDay) === 1 ? 'tomorrow' : 'in ' . $today->diffInDays($evDay) . ' days');
                $r['hours'] = -$today->diffInDays($evDay);
                $out[] = $r;
            }
        }
        return self::section('Listening party prep steps due', 'Tick each step on the event page when it is done.', url('/events'), self::oldestFirst($out));
    }

    /**
     * Anyone on the Sling schedule in the next 14 days who can't work their
     * shift yet: no ERP login, or no time clock PIN. Plus onboarding runs
     * logged in the last 60 days with steps still unticked.
     */
    public static function staffNotReady(int $business_id): array
    {
        $tz = self::TZ;
        $now = Carbon::now($tz);
        $shifts = \DB::table('sling_shifts')
            ->where('event_type', 'shift')
            ->whereBetween('dtstart', [$now->copy()->startOfDay()->format('Y-m-d H:i:s'), $now->copy()->addDays(14)->format('Y-m-d H:i:s')])
            ->orderBy('dtstart')
            ->get(['erp_user_id', 'user_email', 'user_name', 'dtstart', 'location_name']);
        $users = \DB::table('users')->where('business_id', $business_id)->whereNull('deleted_at')
            ->get(['id', 'email', 'first_name', 'last_name', 'allow_login']);
        $byId = [];
        $byEmail = [];
        foreach ($users as $u) {
            $byId[(int) $u->id] = $u;
            if ($u->email) {
                $byEmail[strtolower(trim($u->email))] = $u;
            }
        }
        $pins = array_flip(array_map('intval', \DB::table('time_clock_pins')->where('business_id', $business_id)->pluck('user_id')->all()));

        $seen = [];
        $out = [];
        foreach ($shifts as $s) {
            $key = $s->erp_user_id ?: strtolower(trim((string) ($s->user_email ?: $s->user_name)));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $u = $s->erp_user_id ? ($byId[(int) $s->erp_user_id] ?? null) : null;
            if (!$u && $s->user_email) {
                $u = $byEmail[strtolower(trim($s->user_email))] ?? null;
            }
            $start = Carbon::parse($s->dtstart)->setTimezone($tz);
            $when = 'next shift ' . $start->format('m/d/y g:i A') . ($s->location_name ? ' at ' . $s->location_name : '');
            $missing = [];
            if (!$u || !(int) $u->allow_login) {
                $missing[] = 'no ERP login';
            }
            if (!$u || !isset($pins[(int) $u->id])) {
                $missing[] = 'no time clock PIN';
            }
            if (!$missing) {
                continue;
            }
            $name = $u ? trim($u->first_name . ' ' . $u->last_name) : (string) ($s->user_name ?: $s->user_email);
            $r = self::row($name, ucfirst(implode(', ', $missing)) . ', ' . $when, null, url('/time-clock/settings'), 'staff:' . $key);
            $hrs = $now->diffInHours($start, false);
            $r['since'] = $start->format('m/d/y');
            $r['age'] = $hrs <= 24 ? 'within 24h' : 'in ' . (int) ceil($hrs / 24) . ' days';
            $r['hours'] = 1000 - $hrs; // soonest shift first, flagged red inside 24h
            $r['late'] = $hrs <= 24;
            $out[] = $r;
        }

        // Onboarding runs with steps left (last 60 days).
        $cut = $now->copy()->subDays(60)->format('Y-m-d');
        $labels = \App\Http\Controllers\EmployeeChecklistController::allItems('onboarding');
        foreach (\App\Http\Controllers\EmployeeChecklistController::readAll() as $run) {
            if (($run['type'] ?? '') !== 'onboarding' || ($run['date'] ?? '') < $cut || empty($run['missed'])) {
                continue;
            }
            $steps = array_map(fn ($k) => mb_strimwidth((string) ($labels[$k] ?? $k), 0, 50, '...'), (array) $run['missed']);
            $r = self::row((string) ($run['employee_name'] ?? ''), 'Onboarding not finished: ' . implode(' / ', $steps), $run['date'], url('/employee-checklist?type=onboarding'), 'onboard:' . ($run['id'] ?? ''));
            $out[] = $r;
        }

        usort($out, fn ($a, $b) => $b['hours'] <=> $a['hours']);
        return self::section('Staff not set up', 'Every new hire needs an ERP login and a time clock PIN before their first shift. Finish the onboarding checklist the day they are hired.', url('/employee-checklist'), $out);
    }

    /* ---------- Fixed it / ERP bug ---------- */

    const ACTIONS_FILE = 'open_items_actions.json';
    const RECHECK_HOURS = 48;

    public static function readActions(): array
    {
        $f = storage_path('app/' . self::ACTIONS_FILE);
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
        return is_array($d) ? $d : [];
    }

    public static function saveAction(string $key, string $action, string $note, string $who, string $label): void
    {
        $f = storage_path('app/' . self::ACTIONS_FILE);
        $fp = fopen($f, 'c+');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $d = json_decode($raw ?: '[]', true) ?: [];
        $d[$key] = ['action' => $action, 'note' => $note, 'by' => $who, 'label' => $label, 'at' => Carbon::now(self::TZ)->toIso8601String()];
        // Keep the file small: drop entries older than 90 days.
        $cut = Carbon::now(self::TZ)->subDays(90);
        foreach ($d as $k => $v) {
            if (empty($v['at']) || Carbon::parse($v['at'])->lt($cut)) {
                unset($d[$k]);
            }
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Applies what he already did. "Fixed it" hides a row for 48 hours; if the
     * problem is still open after that, it comes back showing his note, so a
     * fix only counts when the problem is actually gone. "ERP bug" files it in
     * the Bug Fixes queue and gets the same 48-hour recheck.
     * Returns [sections, doneToday].
     */
    public static function applyActions(array $sections): array
    {
        $acts = self::readActions();
        $now = Carbon::now(self::TZ);
        foreach ($sections as $sk => $s) {
            $keep = [];
            foreach ($s['rows'] as $r) {
                $a = ($r['key'] ?? '') !== '' ? ($acts[$r['key']] ?? null) : null;
                if ($a) {
                    $at = Carbon::parse($a['at'])->setTimezone(self::TZ);
                    if ($at->diffInHours($now) < self::RECHECK_HOURS) {
                        continue;
                    }
                    // An unpaid booking stays "unpaid" even after the person says no,
                    // so once he has dealt with it, it stays gone.
                    if ($a['action'] === 'fixed' && strpos($r['key'], 'booking:') === 0) {
                        continue;
                    }
                    $r['reopened'] = ($a['action'] === 'bug' ? 'Reported as an ERP bug ' : 'Marked fixed ')
                        . $at->format('m/d/y') . ' ("' . $a['note'] . '") but it is still open.';
                }
                $keep[] = $r;
            }
            $sections[$sk]['rows'] = $keep;
        }
        $done = [];
        foreach ($acts as $k => $a) {
            if (in_array($a['action'] ?? '', ['fixed', 'bug'], true) && Carbon::parse($a['at'])->setTimezone(self::TZ)->isSameDay($now)) {
                $done[] = $a;
            }
        }
        usort($done, fn ($x, $y) => strcmp($y['at'], $x['at']));
        return [$sections, $done];
    }
}


