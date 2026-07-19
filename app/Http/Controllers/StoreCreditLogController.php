<?php

namespace App\Http\Controllers;

use App\Contact;
use App\StoreCreditLog;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-only audit report of store credit issued by employees.
 *
 * Store-credit history is recorded two ways in this codebase:
 *   1. contacts.balance_notes — a free-text audit line appended on EVERY
 *      credit event (manual add, manual adjust, and buy-from-customer
 *      payout). This is the complete historical record, so the report
 *      parses it as the source of truth for the event list and totals.
 *   2. store_credit_logs — a structured row written going forward, with a
 *      real user_id and (for buy-from-customer payouts) the linking offer
 *      id. Used here only to ENRICH matching events with the employee's
 *      full name and a confirmed purchase-form link.
 *
 * A credit is flagged "no purchase form" when its reason is not a
 * buy-from-customer payout — i.e. someone used the "Add Store Credit"
 * button or a manual adjustment instead of an accepted purchase offer.
 */
class StoreCreditLogController extends Controller
{
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

        // Filters
        $employee   = trim((string) $request->query('employee', ''));
        $customer   = trim((string) $request->query('customer', ''));
        $from       = trim((string) $request->query('from', ''));
        $to         = trim((string) $request->query('to', ''));
        $onlyNoForm = (int) $request->query('only_no_form', 0) === 1;

        $enrich = $this->buildStructuredEnrichmentMap($business_id);

        // Credit ISSUED (positive) — parsed from the full balance_notes history.
        $events = [];
        Contact::where('business_id', $business_id)
            ->whereNotNull('balance_notes')
            ->where('balance_notes', '!=', '')
            ->select(['id', 'name', 'email', 'balance_notes'])
            ->chunkById(500, function ($contacts) use (&$events, $enrich) {
                foreach ($contacts as $c) {
                    foreach ($this->parseBalanceNotes((string) $c->balance_notes) as $ev) {
                        $key = $c->id . '|' . $ev['ts'] . '|' . number_format($ev['amount'], 2, '.', '');
                        $match = $enrich[$key] ?? null;
                        $events[] = [
                            'type'         => 'add',
                            'ts'           => $ev['ts'],
                            'contact_id'   => (int) $c->id,
                            'contact_name' => $c->name,
                            'email'        => $c->email,
                            'employee'     => $match['employee'] ?? $ev['who'], // full name if structured row exists
                            'employee_id'  => $match['user_id'] ?? null,
                            'amount'       => $ev['signed'],                    // signed
                            'balance_after' => $ev['balance'],
                            'reason'       => $ev['reason'],
                            'has_form'     => $ev['has_form'],
                            'offer_id'     => $match['offer_id'] ?? null,
                            'sale_id'      => null,
                            'invoice_no'   => null,
                        ];
                    }
                }
            });

        // Credit USED (negative) — store credit spent on sales.
        foreach ($this->collectRedemptions($business_id) as $r) {
            $events[] = $r;
        }

        // Credit REWARDED (positive) — automatic cumulative spend rewards, read
        // straight from the structured log. Their balance_notes lines don't
        // match the parser above (system-generated, no "by <employee>"), so
        // without this they'd be invisible in this report.
        foreach ($this->collectSpendRewards($business_id) as $r) {
            $events[] = $r;
        }

        // Apply filters
        $events = array_filter($events, function ($e) use ($employee, $customer, $from, $to, $onlyNoForm) {
            if ($employee !== '' && stripos($e['employee'], $employee) === false) {
                return false;
            }
            if ($customer !== '' && stripos((string) $e['contact_name'], $customer) === false) {
                return false;
            }
            if ($from !== '' && substr($e['ts'], 0, 10) < $from) {
                return false;
            }
            if ($to !== '' && substr($e['ts'], 0, 10) > $to) {
                return false;
            }
            // "No purchase form" only applies to issued credit; hide redemptions.
            if ($onlyNoForm && ($e['type'] !== 'add' || $e['has_form'])) {
                return false;
            }
            return true;
        });

        // Newest first
        usort($events, function ($a, $b) {
            return strcmp($b['ts'], $a['ts']);
        });

        // Per-employee rollup
        $byEmployee = [];
        foreach ($events as $e) {
            if ($e['type'] === 'reward') {
                continue; // system-granted, not employee-issued — keep it out of the employee rollup
            }
            $name = $e['employee'] !== '' ? $e['employee'] : 'unknown';
            if (!isset($byEmployee[$name])) {
                $byEmployee[$name] = [
                    'employee' => $name,
                    'events' => 0,
                    'total_issued' => 0.0,   // sum of positive credit added
                    'no_form_issued' => 0.0, // positive credit added without a purchase form
                    'no_form_events' => 0,
                    'total_used' => 0.0,     // store credit spent on sales
                ];
            }
            $byEmployee[$name]['events']++;
            if ($e['type'] === 'add' && $e['amount'] > 0) {
                $byEmployee[$name]['total_issued'] += $e['amount'];
                if (!$e['has_form']) {
                    $byEmployee[$name]['no_form_issued'] += $e['amount'];
                    $byEmployee[$name]['no_form_events']++;
                }
            } elseif ($e['type'] === 'redeem') {
                $byEmployee[$name]['total_used'] += abs($e['amount']);
            }
        }
        $byEmployee = array_values($byEmployee);
        usort($byEmployee, function ($a, $b) {
            return $b['no_form_issued'] <=> $a['no_form_issued'];
        });

        return view('admin.store_credit_log', [
            'events'       => $events,
            'byEmployee'   => $byEmployee,
            'employee'     => $employee,
            'customer'     => $customer,
            'from'         => $from,
            'to'           => $to,
            'only_no_form' => $onlyNoForm,
            'has_structured' => Schema::hasTable('store_credit_logs'),
        ]);
    }

    /**
     * Store credit GRANTED by the automatic cumulative spend reward
     * (SpendRewardService), as positive-amount events read straight from the
     * structured store_credit_logs rows (source='spend_reward'). Reading the
     * table directly — rather than the free-text balance_notes — means every
     * reward, past and future, shows up here with its real timestamp, amount,
     * running balance, and "reached $X cumulative" reason.
     */
    protected function collectSpendRewards($business_id)
    {
        $out = [];
        if (!Schema::hasTable('store_credit_logs')) {
            return $out;
        }

        StoreCreditLog::where('business_id', $business_id)
            ->where('source', 'spend_reward')
            ->with('contact:id,name,email')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$out) {
                foreach ($rows as $row) {
                    $out[] = [
                        'type'          => 'reward',
                        'ts'            => $row->created_at ? $row->created_at->format('Y-m-d H:i') : '',
                        'contact_id'    => (int) $row->contact_id,
                        'contact_name'  => optional($row->contact)->name,
                        'email'         => optional($row->contact)->email,
                        'employee'      => 'Rewards (system)',
                        'employee_id'   => null,
                        'amount'        => (float) $row->amount,   // positive
                        'balance_after' => $row->balance_after,
                        'reason'        => (string) $row->reason,
                        'has_form'      => true,                   // not a manual "no form" issuance
                        'offer_id'      => null,
                        'sale_id'       => $row->transaction_id ? (int) $row->transaction_id : null,
                        'invoice_no'    => null,
                    ];
                }
            });

        return $out;
    }

    /**
     * Store credit SPENT on sales, as negative-amount events. Store credit can
     * be redeemed two ways (see SellPosController): as an `advance` payment line
     * (method='advance'), or — when a cashier clicks "Use Store Credit" then
     * pays the rest with cash/card — as a direct balance deduction that carries
     * a "Store credit used: $X" note on the cash/card line and, going forward, a
     * structured store_credit_logs 'redeem' row. We collect all three, one row
     * per sale, so nothing is double-counted:
     *   advance line  >  "Store credit used:" note  >  redeem log row.
     */
    protected function collectRedemptions($business_id)
    {
        $byTx = [];

        // From transaction_payments: advance lines + "Store credit used:" notes.
        DB::table('transaction_payments as tp')
            ->join('transactions as t', 't.id', '=', 'tp.transaction_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'tp.created_by')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('tp.is_return', 0)
            ->where(function ($q) {
                $q->where('tp.method', 'advance')
                    ->orWhere('tp.note', 'like', 'Store credit used:%');
            })
            ->select(
                't.id as tx_id', 't.invoice_no', 't.transaction_date', 't.contact_id',
                'c.name as contact_name', 'tp.method', 'tp.amount', 'tp.note',
                'u.first_name', 'u.last_name', 'tp.created_by'
            )
            ->orderBy('t.id')
            ->chunk(1000, function ($rows) use (&$byTx) {
                foreach ($rows as $r) {
                    $tx = (int) $r->tx_id;
                    if (!isset($byTx[$tx])) {
                        $name = trim(((string) $r->first_name) . ' ' . ((string) $r->last_name));
                        $byTx[$tx] = [
                            'advance' => 0.0,
                            'note' => 0.0,
                            'ts' => $r->transaction_date ? substr((string) $r->transaction_date, 0, 16) : '',
                            'contact_id' => (int) $r->contact_id,
                            'contact_name' => $r->contact_name,
                            'invoice_no' => $r->invoice_no,
                            'employee' => $name,
                            'employee_id' => $r->created_by,
                        ];
                    }
                    if ($r->method === 'advance') {
                        $byTx[$tx]['advance'] += (float) $r->amount;
                    } elseif (stripos((string) $r->note, 'Store credit used:') !== false
                        && preg_match('/Store credit used:\s*[$€£]?\s*([0-9]+(?:\.[0-9]+)?)/i', (string) $r->note, $m)) {
                        $byTx[$tx]['note'] += (float) $m[1];
                    }
                }
            });

        // Structured redeem rows (case-2 spend recorded going forward).
        if (Schema::hasTable('store_credit_logs') && Schema::hasColumn('store_credit_logs', 'transaction_id')) {
            StoreCreditLog::where('business_id', $business_id)
                ->where('source', 'redeem')
                ->with('user:id,first_name,last_name', 'contact:id,name')
                ->get()
                ->each(function ($row) use (&$byTx) {
                    $tx = (int) $row->transaction_id;
                    // Only use the log row if this sale isn't already covered above.
                    if ($tx > 0 && isset($byTx[$tx]) && ($byTx[$tx]['advance'] > 0 || $byTx[$tx]['note'] > 0)) {
                        return;
                    }
                    $name = $row->user ? trim(((string) $row->user->first_name) . ' ' . ((string) $row->user->last_name)) : '';
                    $key = $tx > 0 ? $tx : ('log-' . $row->id);
                    $byTx[$key] = [
                        'advance' => 0.0,
                        'note' => abs((float) $row->amount),
                        'ts' => $row->created_at ? $row->created_at->format('Y-m-d H:i') : '',
                        'contact_id' => (int) $row->contact_id,
                        'contact_name' => $row->contact ? $row->contact->name : null,
                        'invoice_no' => null,
                        'employee' => $name,
                        'employee_id' => $row->user_id,
                    ];
                });
        }

        $events = [];
        foreach ($byTx as $tx => $d) {
            $amount = $d['advance'] > 0 ? $d['advance'] : $d['note'];
            if ($amount <= 0) {
                continue;
            }
            $events[] = [
                'type'         => 'redeem',
                'ts'           => $d['ts'],
                'contact_id'   => $d['contact_id'],
                'contact_name' => $d['contact_name'],
                'email'        => null,
                'employee'     => $d['employee'],
                'employee_id'  => $d['employee_id'],
                'amount'       => -1 * $amount,
                'balance_after' => null,
                'reason'       => 'store credit used on sale ' . ($d['invoice_no'] ?: ('#' . (is_int($tx) ? $tx : ''))),
                'has_form'     => false,
                'offer_id'     => null,
                'sale_id'      => is_int($tx) ? $tx : null,
                'invoice_no'   => $d['invoice_no'],
            ];
        }
        return $events;
    }

    /**
     * Parse the multi-line balance_notes column into individual credit events.
     * Matches the lines written by ContactController::updateStoreCredit /
     * adjustStoreCredit and BuyFromCustomerController::creditStoreCreditPayout,
     * e.g.  "[2026-07-08 14:30] store-credit +$50.00 by Clyde → new balance
     *        $500.00. Reason: buy-from-customer payout (offer 12, record BR-9)."
     */
    protected function parseBalanceNotes($notes)
    {
        $out = [];
        $lines = preg_split('/\r?\n/', $notes);
        $re = '/^\[(?<ts>\d{4}-\d{2}-\d{2} \d{2}:\d{2})\]\s*store-credit\s*(?<sign>[+\x{2212}\-])\s*\$(?<amt>[0-9,]+(?:\.[0-9]+)?)\s*by\s*(?<who>.+?)\s*(?:\x{2192}|->)\s*new balance\s*\$(?<bal>[0-9,]+(?:\.[0-9]+)?)\.?(?:\s*Reason:\s*(?<reason>.*))?$/u';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !preg_match($re, $line, $m)) {
                continue;
            }
            $amount = (float) str_replace(',', '', $m['amt']);
            $isNeg = in_array($m['sign'], ['-', "\u{2212}"], true);
            $reason = isset($m['reason']) ? trim($m['reason']) : '';
            $out[] = [
                'ts'       => $m['ts'],
                'amount'   => $amount,                       // magnitude
                'signed'   => $isNeg ? -$amount : $amount,
                'who'      => trim($m['who']),
                'balance'  => (float) str_replace(',', '', $m['bal']),
                'reason'   => $reason,
                'has_form' => stripos($reason, 'buy-from-customer payout') === 0,
            ];
        }
        return $out;
    }

    /**
     * Lookup of structured rows keyed by contact_id|minute|amount so we can
     * attach the reliable employee full name + offer id to a parsed event.
     */
    protected function buildStructuredEnrichmentMap($business_id)
    {
        $map = [];
        if (!Schema::hasTable('store_credit_logs')) {
            return $map;
        }

        StoreCreditLog::where('business_id', $business_id)
            ->where('source', '!=', 'redeem') // additions only; redemptions enriched elsewhere
            ->with('user:id,first_name,last_name')
            ->select(['id', 'contact_id', 'user_id', 'amount', 'created_at', 'buy_customer_offer_id'])
            ->chunkById(1000, function ($rows) use (&$map) {
                foreach ($rows as $r) {
                    $minute = $r->created_at ? $r->created_at->format('Y-m-d H:i') : '';
                    $key = $r->contact_id . '|' . $minute . '|' . number_format(abs((float) $r->amount), 2, '.', '');
                    $name = '';
                    if ($r->user) {
                        $name = trim(($r->user->first_name ?? '') . ' ' . ($r->user->last_name ?? ''));
                    }
                    $map[$key] = [
                        'employee' => $name !== '' ? $name : null,
                        'user_id'  => $r->user_id,
                        'offer_id' => $r->buy_customer_offer_id,
                    ];
                }
            });

        return $map;
    }

    protected function guardAdmin()
    {
        $user = auth()->user();
        if (!$user || !$this->businessUtil->is_admin($user)) {
            abort(403, 'Admins only.');
        }
    }
}
