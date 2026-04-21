<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\CustomerWant;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

class CustomerWantController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $status = $request->input('status', 'active');
        $priority = $request->input('priority');
        $location_id = $request->input('location_id');
        $q = trim($request->input('q', ''));

        $query = CustomerWant::with(['contact', 'location', 'creator'])
            ->where('business_id', $business_id);

        if (!empty($status)) {
            $query->where('status', $status);
        }
        if (!empty($priority)) {
            $query->where('priority', $priority);
        }
        if (!empty($location_id)) {
            $query->where('location_id', $location_id);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('artist', 'like', "%{$q}%")
                  ->orWhere('title', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%")
                  ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        $wants = $query
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")
            ->orderByDesc('created_at')
            ->paginate(50)
            ->appends($request->except('page'));

        $business_locations = BusinessLocation::forDropdown($business_id);

        return view('customer_wants.index', compact(
            'wants', 'business_locations', 'status', 'priority', 'location_id', 'q'
        ));
    }

    public function create(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id);
        return view('customer_wants.create', compact('business_locations'));
    }

    public function store(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $data = $request->validate([
            'contact_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'artist' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'format' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'priority' => 'required|in:low,normal,high',
        ]);
        $data['business_id'] = $business_id;
        $data['created_by'] = auth()->user()->id;
        $data['status'] = 'active';

        CustomerWant::create($data);

        return redirect(action('CustomerWantController@index'))
            ->with('status', ['success' => true, 'msg' => 'Want added.']);
    }

    public function edit($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);
        $business_locations = BusinessLocation::forDropdown($business_id);
        return view('customer_wants.edit', compact('want', 'business_locations'));
    }

    public function update($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);
        $data = $request->validate([
            'contact_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'artist' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'format' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'priority' => 'required|in:low,normal,high',
            'status' => 'required|in:active,fulfilled,cancelled',
        ]);
        $want->fill($data)->save();

        return redirect(action('CustomerWantController@index'))
            ->with('status', ['success' => true, 'msg' => 'Want updated.']);
    }

    public function fulfill($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);
        $want->status = 'fulfilled';
        $want->fulfilled_by = auth()->user()->id;
        $want->fulfilled_at = now();
        $want->fulfilled_note = $request->input('fulfilled_note');
        $want->save();

        return redirect(action('CustomerWantController@index'))
            ->with('status', ['success' => true, 'msg' => 'Want marked as fulfilled.']);
    }

    public function destroy($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);
        $want->delete();

        return redirect(action('CustomerWantController@index'))
            ->with('status', ['success' => true, 'msg' => 'Want deleted.']);
    }

    /**
     * JSON endpoint: active wants for a given contact. Hit from the POS
     * sidebar when a rewards account is loaded so the cashier sees the
     * customer's wish list instantly and can say "oh hey, we just got
     * that Green Day LP in last week".
     */
    public function forContact($contactId, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        // ---- Active wants ----
        $active = CustomerWant::with(['location'])
            ->where('business_id', $business_id)
            ->where('contact_id', $contactId)
            ->where('status', 'active')
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")
            ->orderByDesc('created_at')
            ->get();

        foreach ($active as $w) {
            $w->possible_matches = $this->findMatchingProducts($business_id, $w);
        }

        // ---- Past wants (fulfilled / cancelled) — show last 5 only.
        //      The POS preview doesn't need the full history; that lives
        //      on the customer profile page. --
        $past = CustomerWant::where('business_id', $business_id)
            ->where('contact_id', $contactId)
            ->whereIn('status', ['fulfilled', 'cancelled'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        // ---- Recent purchases — last 5 finalised sells for this contact.
        //      Aggregated per transaction with top items listed so the
        //      cashier can see "oh yeah, they bought Bowie last time". --
        $recent_purchases = \DB::table('transactions as t')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $business_id)
            ->where('t.contact_id', $contactId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->orderByDesc('t.transaction_date')
            ->limit(5)
            ->get([
                't.id', 't.invoice_no', 't.transaction_date',
                't.final_total', 'bl.name as location_name',
            ]);

        foreach ($recent_purchases as $tx) {
            $tx->items = \DB::table('transaction_sell_lines as tsl')
                ->join('products as p', 'tsl.product_id', '=', 'p.id')
                ->where('tsl.transaction_id', $tx->id)
                ->orderByDesc('tsl.quantity')
                ->limit(3)
                ->get(['p.name', 'p.artist', 'tsl.quantity', 'tsl.unit_price_inc_tax']);
            $tx->item_count = (int) \DB::table('transaction_sell_lines')
                ->where('transaction_id', $tx->id)
                ->sum('quantity');
        }

        return response()->json([
            'active'           => $active,
            'past'             => $past,
            'recent_purchases' => $recent_purchases,
            // Back-compat for any old widget code that reads `wants`.
            'wants'            => $active,
        ]);
    }

    /**
     * Search products that might match a want. Narrow, conservative: match
     * on title + (if we have one) artist. Exposes up to 5 candidates so the
     * cashier can verify visually before saying "yes we have it".
     */
    private function findMatchingProducts($business_id, CustomerWant $want)
    {
        $title = trim((string) $want->title);
        if ($title === '') return [];

        $q = \DB::table('products as p')
            ->leftJoin('variation_location_details as vld', function ($j) {
                $j->on('vld.product_id', '=', 'p.id');
            })
            ->where('p.business_id', $business_id)
            ->where('p.name', 'LIKE', '%' . $title . '%')
            ->select([
                'p.id', 'p.name', 'p.artist', 'p.sku',
                \DB::raw('COALESCE(SUM(vld.qty_available), 0) as total_stock'),
            ])
            ->groupBy('p.id', 'p.name', 'p.artist', 'p.sku')
            ->limit(5);

        if (!empty($want->artist)) {
            $q->where('p.artist', 'LIKE', '%' . $want->artist . '%');
        }

        return $q->get();
    }

    /**
     * Create a want directly from the POS (quick add). Same validation as
     * store() but returns JSON so the POS sidebar can add it to the list
     * without a page reload.
     */
    public function storeFromPos(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $data = $request->validate([
            'contact_id' => 'required|integer',
            'location_id' => 'nullable|integer',
            'artist' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'format' => 'nullable|string|max:64',
            'priority' => 'nullable|in:low,normal,high',
            'notes' => 'nullable|string',
        ]);
        $data['business_id'] = $business_id;
        $data['created_by'] = auth()->user()->id;
        $data['status'] = 'active';
        $data['priority'] = $data['priority'] ?? 'normal';

        $want = CustomerWant::create($data);

        return response()->json([
            'success' => true,
            'want' => $want->load('location'),
        ]);
    }

    /**
     * AJAX fulfill endpoint. Marks the want fulfilled + optionally sends
     * the customer an email saying "we got your item, it's at <store>."
     * Returns JSON so the POS sidebar can update without a reload.
     */
    public function fulfillAjax($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);

        $want->status = 'fulfilled';
        $want->fulfilled_by = auth()->user()->id;
        $want->fulfilled_at = now();
        $want->fulfilled_note = $request->input('fulfilled_note');
        $want->save();

        // Notify method: 'email' | 'sms' | 'both' | 'none'. Default to 'none'
        // if omitted. Each channel reports its own success/error so the POS
        // can show "Emailed + texted" or "Texted (email failed)" independently.
        $notifyMethod = strtolower((string) $request->input('notify_method', 'none'));
        $channels = [];
        if (in_array($notifyMethod, ['email', 'both'])) $channels[] = 'email';
        if (in_array($notifyMethod, ['sms', 'both']))   $channels[] = 'sms';

        $notifyResults = [];
        $contact = $want->contact_id ? Contact::find($want->contact_id) : null;
        foreach ($channels as $ch) {
            $notifyResults[$ch] = $this->sendWantNotification($ch, $want, $contact);
        }

        return response()->json([
            'success' => true,
            'want_id' => $want->id,
            'notifications' => $notifyResults,
        ]);
    }

    /**
     * Dispatch one notification channel for a fulfilled want.
     * Returns ['ok' => bool, 'msg' => string].
     */
    private function sendWantNotification(string $channel, CustomerWant $want, ?Contact $contact): array
    {
        if (!$contact) {
            return ['ok' => false, 'msg' => 'No contact linked to this want.'];
        }

        if ($channel === 'email') {
            if (empty($contact->email)) {
                return ['ok' => false, 'msg' => 'Customer has no email on file.'];
            }
            try {
                \Mail::to($contact->email)->send(new \App\Mail\CustomerWantMatched($want, $contact));
                return ['ok' => true, 'msg' => 'Emailed ' . $contact->email];
            } catch (\Throwable $e) {
                \Log::warning('CustomerWant email failed: ' . $e->getMessage());
                return ['ok' => false, 'msg' => 'Email failed: ' . $e->getMessage()];
            }
        }

        if ($channel === 'sms') {
            $phone = $contact->mobile ?: ($contact->alternate_number ?: null);
            if (empty($phone)) {
                return ['ok' => false, 'msg' => 'Customer has no phone on file.'];
            }
            $sms = app(\App\Services\OpenPhoneService::class);
            $first = trim((string) ($contact->first_name ?? ''));
            $hey = $first !== '' ? ('Hey ' . $first . ', ') : 'Hey, ';
            $artist = trim((string) $want->artist);
            $title = trim((string) $want->title);
            $label = trim(implode(' — ', array_filter([$artist, $title])));
            if (!empty($want->format)) $label .= ' (' . $want->format . ')';
            $storeName = optional($want->location)->name ?: 'Nivessa';
            $msg = $hey . "Nivessa here — we just got your {$label} in at {$storeName}. "
                 . "We'll hold it behind the counter. Stop by when you can.";

            $result = $sms->send($phone, $msg);
            return ['ok' => (bool) $result['success'], 'msg' => $result['msg'] ?? ''];
        }

        return ['ok' => false, 'msg' => 'Unknown notify channel: ' . $channel];
    }
}
