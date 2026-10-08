<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Services\BuyOfferCalculatorService;
use App\Services\OpenPhoneService;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Sarah 2026-10-08: an over-limit buy (way above the calculator) can be
// approved from Luis's (Hollywood) or Zak's (Pico) phone. The cashier taps
// "Text for approval", the approver gets an SMS link showing the items, the
// photos and the amounts, taps Approve, and the cashier's screen unlocks.
//
// Each request is a JSON file under storage/app/buy-approvals (no migration,
// survives deploys/cache clears). The cashier only ever sees the request id;
// the secret in the SMS link is what lets someone approve, so the cashier
// can't approve their own buy.
class BuyApprovalController extends Controller
{
    const MAX_PHOTOS = 6;
    const VALID_HOURS = 3;

    public static function dir()
    {
        return storage_path('app/buy-approvals');
    }

    public static function load($rid)
    {
        if (!preg_match('/^[A-Za-z0-9]{16,40}$/', (string) $rid)) return null;
        $path = self::dir() . '/' . $rid . '.json';
        if (!is_file($path)) return null;
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    public static function save(array $rec)
    {
        if (!is_dir(self::dir())) @mkdir(self::dir(), 0775, true);
        file_put_contents(self::dir() . '/' . $rec['rid'] . '.json', json_encode($rec, JSON_PRETTY_PRINT), LOCK_EX);
    }

    // Who approves for a location: Zak at Pico, Luis everywhere else.
    public static function approverFor($business_id, $locationId)
    {
        $name = BuyFromCustomerController::overpayContactName($locationId);
        $firsts = $name === 'Zak' ? ['zak', 'zakary', 'zachary'] : ['luis'];
        return User::where('business_id', $business_id)
            ->where('status', 'active')
            ->whereIn(\DB::raw('LOWER(TRIM(first_name))'), $firsts)
            ->orderBy('id')
            ->first();
    }

    // ERP phone first, then the phone on their Sling profile.
    public static function phoneFor(User $user)
    {
        $phone = trim((string) $user->contact_number);
        if ($phone !== '') return $phone;
        try {
            $slingId = \App\SlingShift::where('erp_user_id', $user->id)->whereNotNull('sling_user_id')->latest('id')->value('sling_user_id');
            if ($slingId) {
                $phones = (new \App\Services\SlingClient())->userPhones();
                return $phones[(string) $slingId] ?? '';
            }
        } catch (\Throwable $e) {
            Log::info('BuyApproval: Sling phone lookup failed: ' . $e->getMessage());
        }
        return '';
    }

    // Cashier: send the approval text.
    public function requestApproval(Request $request)
    {
        if (!auth()->user()->can('purchase.create')) abort(403);

        $request->validate([
            'final_amount_paid' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash_in_store,store_credit,zelle_venmo',
            'lines' => 'required|array|min:1',
            'photos' => 'nullable|array|max:' . self::MAX_PHOTOS,
            'photos.*' => 'file|mimes:jpg,jpeg,png,gif,webp,heic,heif|max:12000',
        ], [
            'final_amount_paid.required' => 'Enter the final amount paid first.',
        ]);

        $business_id = $request->session()->get('user.business_id');
        $cashier = auth()->user();
        $locationId = $request->input('location_id') ?: null;

        $approver = self::approverFor($business_id, $locationId);
        $who = BuyFromCustomerController::overpayContactName($locationId);
        // Manager testing the cashier flow (?as_cashier=1): text themselves, not Luis/Zak.
        $isTest = filter_var($request->input('as_cashier'), FILTER_VALIDATE_BOOLEAN) && BuyFromCustomerController::canApproveOverpay($cashier);
        if ($isTest) {
            $approver = $cashier;
            $who = $cashier->first_name;
        }
        if (!$approver) {
            return response()->json(['ok' => false, 'msg' => "Couldn't find {$who}'s ERP account. Please call {$who}."], 422);
        }
        $phone = self::phoneFor($approver);
        if ($phone === '') {
            return response()->json(['ok' => false, 'msg' => "{$who} has no phone number on file. Please call {$who}."], 422);
        }

        $calculator = app(BuyOfferCalculatorService::class);
        $calc = $calculator->calculate($request->input('lines', []), []);
        $pm = $request->input('payment_method');
        $auto = $pm === 'store_credit' ? (float) $calc['final_offer_credit'] : (float) $calc['final_offer_cash'];
        $paid = (float) $request->input('final_amount_paid');

        $itemTypes = $calculator->getRules()['item_types'];
        $lines = [];
        foreach ((array) ($calc['lines'] ?? []) as $l) {
            $lines[] = [
                'qty' => (float) $l['quantity'],
                'type' => $itemTypes[$l['item_type']]['label'] ?? $l['item_type'],
                'title' => $l['title'] ?? null,
                'value' => $l['discogs_median_price'] ?? null,
                'grade' => $l['condition_grade'] ?? null,
                'line_cash' => (float) $l['line_cash_total'],
            ];
        }

        $rid = Str::random(24);
        $secret = Str::random(32);
        $photos = [];
        foreach ((array) $request->file('photos', []) as $i => $file) {
            if (!$file || !$file->isValid()) continue;
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'gif'], true)) $ext = 'jpg';
            $name = $rid . '-' . $i . '.' . $ext;
            $file->move(self::dir() . '/photos', $name);
            $photos[] = $name;
        }

        $locationName = $locationId ? (string) BusinessLocation::where('id', $locationId)->value('name') : '';
        $seller = trim($request->input('seller_first_name') . ' ' . $request->input('seller_last_name')) ?: (string) $request->input('seller_name');
        $pmLabel = ['cash_in_store' => 'cash', 'store_credit' => 'store credit', 'zelle_venmo' => 'Zelle/Venmo'][$pm];
        $cashierName = trim($cashier->first_name . ' ' . $cashier->last_name);

        $rec = [
            'rid' => $rid,
            'secret_hash' => hash('sha256', $secret),
            'business_id' => $business_id,
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashierName,
            'location_id' => $locationId,
            'location_name' => $locationName,
            'approver_id' => $approver->id,
            'approver_name' => trim($approver->first_name . ' ' . $approver->last_name),
            'payment_method' => $pm,
            'pm_label' => $pmLabel,
            'paid' => round($paid, 2),
            'auto' => round($auto, 2),
            'seller' => $seller,
            'lines' => $lines,
            'notes' => (string) $request->input('notes'),
            'photos' => $photos,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
        ];
        self::save($rec);

        $link = route('buy-approval.show', ['token' => $rid . '-' . $secret]);
        $msg = sprintf(
            ($isTest ? 'TEST ' : '') . 'Buy approval%s: %s wants to pay $%s %s for %d item%s. The system says $%s. Approve or deny: %s',
            $locationName ? ' (' . $locationName . ')' : '',
            $cashier->first_name,
            number_format($paid, 2),
            $pmLabel,
            (int) array_sum(array_column($lines, 'qty')),
            array_sum(array_column($lines, 'qty')) == 1 ? '' : 's',
            number_format($auto, 2),
            $link
        );
        $line = array_search(stripos($locationName, 'pico') !== false ? 'phone_1' : 'phone_2', \App\Communication::QUO_NUMBERS, true);
        $sms = app(OpenPhoneService::class);
        $result = $line ? $sms->sendFrom((string) $line, $phone, $msg) : $sms->send($phone, $msg);
        if (empty($result['success'])) {
            Log::warning('BuyApproval: SMS failed', ['rid' => $rid, 'msg' => $result['msg'] ?? '']);
            $rec['status'] = 'failed';
            self::save($rec);
            return response()->json(['ok' => false, 'msg' => "The text to {$who} didn't go through. Please call {$who}."], 422);
        }

        return response()->json(['ok' => true, 'rid' => $rid, 'who' => $approver->first_name]);
    }

    // Cashier: poll for the decision.
    public function status($rid)
    {
        $rec = self::load($rid);
        if (!$rec || (int) $rec['cashier_id'] !== (int) auth()->id()) {
            return response()->json(['status' => 'missing'], 404);
        }
        return response()->json([
            'status' => $rec['status'],
            'approver' => $rec['approver_name'],
            'paid' => $rec['paid'],
            'payment_method' => $rec['payment_method'],
        ]);
    }

    protected function fromToken($token)
    {
        $parts = explode('-', (string) $token, 2);
        if (count($parts) !== 2) abort(404);
        $rec = self::load($parts[0]);
        if (!$rec || !hash_equals($rec['secret_hash'], hash('sha256', $parts[1]))) abort(404);
        return $rec;
    }

    // Approver (no login): see the buy.
    public function show($token)
    {
        $rec = $this->fromToken($token);
        $expired = $rec['status'] === 'pending' && now()->diffInHours(\Carbon\Carbon::parse($rec['created_at'])) >= self::VALID_HOURS;
        return view('buy_from_customer.approval', compact('rec', 'token', 'expired'));
    }

    // Approver (no login): approve or deny.
    public function decide(Request $request, $token)
    {
        $rec = $this->fromToken($token);
        if ($rec['status'] === 'pending' && now()->diffInHours(\Carbon\Carbon::parse($rec['created_at'])) < self::VALID_HOURS) {
            $rec['status'] = $request->input('decision') === 'approve' ? 'approved' : 'denied';
            $rec['decided_at'] = now()->toDateTimeString();
            $rec['decided_ip'] = $request->ip();
            self::save($rec);
        }
        return redirect()->route('buy-approval.show', ['token' => $token]);
    }

    public function photo($token, $n)
    {
        $rec = $this->fromToken($token);
        $name = $rec['photos'][(int) $n] ?? null;
        $path = $name ? self::dir() . '/photos/' . $name : null;
        if (!$path || !is_file($path)) abort(404);
        return response()->file($path);
    }

    // Used by BuyFromCustomerController when accepting: an approved request
    // from this cashier, for this payment type, covering this amount.
    public static function approvedFor($rid, $cashierId, $pm, $paid)
    {
        $rec = self::load($rid);
        if (!$rec || $rec['status'] !== 'approved') return null;
        if ((int) $rec['cashier_id'] !== (int) $cashierId) return null;
        if ($rec['payment_method'] !== $pm) return null;
        if ((float) $paid > (float) $rec['paid'] + 0.009) return null;
        if (now()->diffInHours(\Carbon\Carbon::parse($rec['decided_at'] ?? $rec['created_at'])) >= self::VALID_HOURS) return null;
        return $rec;
    }

    public static function markUsed($rid, $offerId)
    {
        $rec = self::load($rid);
        if (!$rec || $rec['status'] !== 'approved') return;
        $rec['status'] = 'used';
        $rec['offer_id'] = $offerId;
        $rec['used_at'] = now()->toDateTimeString();
        self::save($rec);
    }
}
