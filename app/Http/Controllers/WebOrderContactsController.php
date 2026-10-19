<?php

namespace App\Http\Controllers;

use App\Contact;
use App\Transaction;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One-time move of old website sales off the throwaway "Website Customer"
 * contacts (email web-order-<orderId>@nivessa.com) onto the real buyer's
 * customer account, so staff can see what a web customer ordered and give
 * them store credit. New web orders already land on the buyer's own
 * account (server syncWebsiteSaleToPOS, 2026-10-18).
 *
 * Preview first; Apply snapshots every touched contact to
 * storage/app/web-order-contacts/ before changing anything. Merged
 * throwaway contacts are soft-deleted (restorable), never hard-deleted.
 */
class WebOrderContactsController extends Controller
{
    const FAKE_EMAIL_LIKE = 'web-order-%@nivessa.com';

    /** @var BusinessUtil */
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        $this->guardAdmin();
        $business_id = (int) $request->session()->get('user.business_id');
        $plan = $this->buildPlan($business_id, 300);

        return view('admin.web_order_contacts', [
            'rows' => $plan['rows'],
            'error' => $plan['error'],
            'remaining' => $this->fakeQuery($business_id)->count(),
        ]);
    }

    public function apply(Request $request)
    {
        $this->guardAdmin();
        $business_id = (int) $request->session()->get('user.business_id');
        $plan = $this->buildPlan($business_id, 300);
        if ($plan['error']) {
            return back()->with('status', ['type' => 'error', 'msg' => $plan['error']]);
        }

        $rows = array_filter($plan['rows'], function ($r) {
            return $r->action !== 'skip';
        });
        if (!$rows) {
            return back()->with('status', ['type' => 'success', 'msg' => 'Nothing to move.']);
        }

        // Snapshot before any write.
        $ids = array_map(function ($r) { return $r->fake_id; }, $rows);
        $snapshot = [
            'at' => now()->toDateTimeString(),
            'by' => auth()->user()->id,
            'contacts' => Contact::whereIn('id', $ids)->get()->toArray(),
            'transactions' => Transaction::whereIn('contact_id', $ids)->get(['id', 'contact_id'])->toArray(),
        ];
        Storage::put('web-order-contacts/' . now()->format('Ymd-His') . '.json', json_encode($snapshot));

        $renamed = 0;
        $merged = 0;
        $who = auth()->user()->first_name ?? 'admin';
        foreach ($rows as $r) {
            DB::transaction(function () use ($r, $business_id, $who, &$renamed, &$merged) {
                $fake = Contact::where('business_id', $business_id)->find($r->fake_id);
                if (!$fake) {
                    return;
                }
                // Re-check at write time: an earlier row in this run may have
                // just turned another throwaway contact into this buyer.
                $real = $this->findRealContact($business_id, $r->email, $fake->id);
                $stamp = now()->format('Y-m-d H:i');

                if ($real) {
                    $moved = Transaction::where('contact_id', $fake->id)->update(['contact_id' => $real->id]);
                    if (Schema::hasColumn('contacts', 'balance_notes')) {
                        $real->balance_notes = trim(($real->balance_notes ?? '') . "\n" . sprintf(
                            '[%s] web order %s: moved %d sale(s) from Website Customer contact #%d by %s',
                            $stamp, $r->order_id, $moved, $fake->id, $who
                        ));
                    }
                    $real->balance = (float) $real->balance + (float) $fake->balance;
                    $real->save();
                    $fake->balance = 0;
                    $fake->save();
                    $fake->delete(); // soft delete, restorable
                    $merged++;
                } else {
                    $fake->first_name = $r->first_name;
                    $fake->last_name = $r->last_name;
                    $fake->name = trim($r->first_name . ' ' . $r->last_name);
                    $fake->email = $r->email;
                    if ($r->phone !== '' && in_array((string) $fake->mobile, ['', '0', '0000000000'], true)) {
                        $fake->mobile = $r->phone;
                    }
                    if (Schema::hasColumn('contacts', 'balance_notes')) {
                        $fake->balance_notes = trim(($fake->balance_notes ?? '') . "\n" . sprintf(
                            '[%s] was Website Customer (web order %s), set to buyer by %s',
                            $stamp, $r->order_id, $who
                        ));
                    }
                    $fake->save();
                    $renamed++;
                }
            });
        }

        return back()->with('status', [
            'type' => 'success',
            'msg' => "Done: {$renamed} turned into the buyer's account, {$merged} merged into an existing customer. Run again if more are left.",
        ]);
    }

    protected function fakeQuery(int $business_id)
    {
        return Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->where('email', 'like', self::FAKE_EMAIL_LIKE);
    }

    protected function findRealContact(int $business_id, string $email, int $exceptId)
    {
        return Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->where('id', '!=', $exceptId)
            ->where('is_default', 0)
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->orderBy('id')
            ->first();
    }

    /**
     * Each throwaway contact, its web order, the buyer the website knows,
     * and what Apply would do (rename / merge / skip).
     */
    protected function buildPlan(int $business_id, int $limit): array
    {
        $fakes = $this->fakeQuery($business_id)->orderBy('id')->limit($limit)
            ->get(['id', 'name', 'email', 'mobile', 'balance']);
        if ($fakes->isEmpty()) {
            return ['rows' => [], 'error' => null];
        }

        $orderIds = [];
        foreach ($fakes as $c) {
            if (preg_match('/^web-order-([a-f0-9]{24})@nivessa\.com$/i', (string) $c->email, $m)) {
                $orderIds[$c->id] = strtolower($m[1]);
            }
        }

        $resp = app(WebsiteOrdersController::class)->bridgePost('/erp/orders/buyers', ['ids' => array_values(array_unique($orderIds))], 30);
        if (empty($resp['success'])) {
            return ['rows' => [], 'error' => 'Website lookup failed: ' . ($resp['message'] ?? 'unknown error')];
        }
        $buyers = [];
        foreach ((array) ($resp['data'] ?? []) as $b) {
            $buyers[strtolower((string) ($b['id'] ?? ''))] = $b;
        }

        $sales = Transaction::whereIn('contact_id', $fakes->pluck('id'))
            ->select('contact_id', DB::raw('COUNT(*) as n'))
            ->groupBy('contact_id')->pluck('n', 'contact_id');

        $rows = [];
        foreach ($fakes as $c) {
            $oid = $orderIds[$c->id] ?? null;
            $b = $oid ? ($buyers[$oid] ?? null) : null;
            $email = strtolower(trim((string) ($b['email'] ?? '')));
            $valid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
                && !preg_match('/^web-order-/', $email);
            $real = $valid ? $this->findRealContact($business_id, $email, $c->id) : null;

            $rows[] = (object) [
                'fake_id' => (int) $c->id,
                'order_id' => $oid,
                'order_number' => $b['orderNumber'] ?? null,
                'sales' => (int) ($sales[$c->id] ?? 0),
                'first_name' => trim((string) ($b['firstName'] ?? '')) ?: 'Website',
                'last_name' => trim((string) ($b['lastName'] ?? '')),
                'email' => $email,
                'phone' => trim((string) ($b['phone'] ?? '')),
                'real_id' => $real ? (int) $real->id : null,
                'real_name' => $real ? (string) $real->name : null,
                'action' => !$valid ? 'skip' : ($real ? 'merge' : 'rename'),
            ];
        }

        return ['rows' => $rows, 'error' => null];
    }

    protected function guardAdmin()
    {
        $user = auth()->user();
        if (!$user || !$this->businessUtil->is_admin($user)) {
            abort(403, 'Admins only.');
        }
    }
}
