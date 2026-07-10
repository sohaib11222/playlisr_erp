<?php

namespace App\Http\Controllers;

use App\Contact;
use App\StoreCreditLog;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
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
        $from       = trim((string) $request->query('from', ''));
        $to         = trim((string) $request->query('to', ''));
        $onlyNoForm = (int) $request->query('only_no_form', 0) === 1;

        $enrich = $this->buildStructuredEnrichmentMap($business_id);

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
                        ];
                    }
                }
            });

        // Apply filters
        $events = array_filter($events, function ($e) use ($employee, $from, $to, $onlyNoForm) {
            if ($employee !== '' && stripos($e['employee'], $employee) === false) {
                return false;
            }
            if ($from !== '' && substr($e['ts'], 0, 10) < $from) {
                return false;
            }
            if ($to !== '' && substr($e['ts'], 0, 10) > $to) {
                return false;
            }
            if ($onlyNoForm && $e['has_form']) {
                return false;
            }
            return true;
        });

        // Newest first
        usort($events, function ($a, $b) {
            return strcmp($b['ts'], $a['ts']);
        });

        // Per-employee rollup (only positive credit counts toward "given out")
        $byEmployee = [];
        foreach ($events as $e) {
            $name = $e['employee'] !== '' ? $e['employee'] : 'unknown';
            if (!isset($byEmployee[$name])) {
                $byEmployee[$name] = [
                    'employee' => $name,
                    'events' => 0,
                    'total_issued' => 0.0,   // sum of positive credit added
                    'no_form_issued' => 0.0, // positive credit added without a purchase form
                    'no_form_events' => 0,
                ];
            }
            $byEmployee[$name]['events']++;
            if ($e['amount'] > 0) {
                $byEmployee[$name]['total_issued'] += $e['amount'];
                if (!$e['has_form']) {
                    $byEmployee[$name]['no_form_issued'] += $e['amount'];
                    $byEmployee[$name]['no_form_events']++;
                }
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
            'from'         => $from,
            'to'           => $to,
            'only_no_form' => $onlyNoForm,
            'has_structured' => Schema::hasTable('store_credit_logs'),
        ]);
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
