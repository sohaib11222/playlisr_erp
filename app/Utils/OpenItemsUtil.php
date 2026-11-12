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

    /** All sections, in the order they should be worked. */
    public static function sections(int $business_id): array
    {
        $out = [];
        foreach ([
            'customers' => fn () => self::customersWaiting($business_id),
            'unhappy'   => fn () => self::unhappyCustomers($business_id),
            'orders'    => fn () => self::stuckOrders(),
            'contact'   => fn () => self::contactForms(),
            'bookings'  => fn () => self::unpaidBookings(),
            'buys'      => fn () => self::draftBuys($business_id),
            'events'    => fn () => self::eventPrep($business_id),
        ] as $key => $fn) {
            try {
                $out[$key] = $fn();
            } catch (\Throwable $e) {
                \Log::warning('open-items ' . $key . ' failed: ' . $e->getMessage());
                $names = ['customers' => 'Customers waiting', 'unhappy' => 'Unhappy customers', 'orders' => 'Website orders stuck', 'contact' => 'Contact form messages', 'bookings' => 'Venue booking requests', 'buys' => 'Buys left as drafts', 'events' => 'Listening party prep'];
                $out[$key] = self::section($names[$key] ?? $key, '', '', [], 'Could not load this right now.');
            }
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

    protected static function row($who, $what, $since, string $link): array
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
            $out[] = self::row($r->customer_name ?: $r->contact_info, (string) $r->message, $r->created_at, url('/communications'));
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
            $out[] = self::row($r->customer_name ?: $r->contact_info, (string) $r->message, $r->created_at, url('/communications'));
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
                $out[] = self::row($name, "Can't find: decide refund or replacement", $o['createdAt'] ?? null, url('/website-orders?order=' . $id));
            } elseif ($created < $cut && WebsiteOrdersController::inTab($o, 'needs_action')) {
                $how = ($o['fulfillment_method'] ?? '') === 'pickup' ? 'Pickup order not ready' : 'Not shipped';
                $out[] = self::row($name, $how . ' after ' . intdiv(time() - $created, 86400) . ' days', $o['createdAt'] ?? null, url('/website-orders?order=' . $id));
            }
        }
        return self::section('Website orders stuck (last 60 days)', 'Text the person on shift with the order link. No answer in 2 hours, call them, then the manager.', url('/website-orders?tab=needs_action'), self::oldestFirst($out));
    }

    /** Website contact form messages nobody has replied to. */
    public static function contactForms(): array
    {
        $resp = \Cache::remember('open_items_contact', 300, fn () => WebsiteBridge::admin('GET', '/customer/messages'));
        if (empty($resp['success'])) {
            \Cache::forget('open_items_contact');
            return self::section('Contact form messages', '', url('/website-inbox/messages'), [], 'Could not reach the website right now.');
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
            $what = trim(($m['subject'] ?? '') . ': ' . ($m['message'] ?? ''), ': ');
            $out[] = self::row((string) ($m['name'] ?? $m['email'] ?? ''), $what, $m['createdAt'] ?? null, url('/website-inbox/messages'));
        }
        return self::section('Contact form messages not answered (last 30 days)', 'Answer it yourself from the Website Inbox. Sellers: get a time booked to bring the collection in.', url('/website-inbox/messages'), self::oldestFirst($out));
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
            $out[] = self::row($b['customerName'] ?: $b['customerEmail'], trim($b['venueName'] . ' ' . $b['title']), $b['createdAt'] ?: null, url('/website-carts?tab=bookings'));
        }
        return self::section('Venue booking requests not paid', 'Call or email them to finish the booking, or find out why not.', url('/website-carts?tab=bookings'), self::oldestFirst($out));
    }

    /** Buys left as drafts for over a day (last 30 days). */
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
            $out[] = self::row($r->seller_name ?: $r->seller_phone, $what, $r->created_at, url('/buy-from-customer/' . $r->id . '/edit'));
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
                $r = self::row($name, $what, null, url('/events/' . $eid . '/edit'));
                $r['since'] = $evDay->format('m/d/y');
                $r['age'] = $evDay->isSameDay($today) ? 'today' : ($today->diffInDays($evDay) === 1 ? 'tomorrow' : 'in ' . $today->diffInDays($evDay) . ' days');
                $r['hours'] = -$today->diffInDays($evDay);
                $out[] = $r;
            }
        }
        return self::section('Listening party prep steps due', 'Tick each step on the event page when it is done.', url('/events'), self::oldestFirst($out));
    }
}
