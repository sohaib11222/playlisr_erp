<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\CashRegister;
use App\Utils\CashRegisterUtil;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $cashRegisterUtil;
    protected $moduleUtil;

    /**
     * Constructor
     *
     * @param CashRegisterUtil $cashRegisterUtil
     * @return void
     */
    public function __construct(CashRegisterUtil $cashRegisterUtil, ModuleUtil $moduleUtil)
    {
        $this->cashRegisterUtil = $cashRegisterUtil;
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('cash_register.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //like:repair
        $sub_type = request()->get('sub_type');

        $business_id = request()->session()->get('user.business_id');

        // Sarah 2026-05-13: sweep abandoned (>12h open) shifts before the
        // gate runs, so yesterday's leftover open doesn't block today's
        // opening. No human closes another human's register (theft
        // surface); only the system does, when the shift has clearly
        // been abandoned. closing_amount stays NULL on purpose so the
        // reconciliation banner can flag "count missing".
        $this->cashRegisterUtil->autoCloseStaleOpenRegisters($business_id, 12);

        //Check if there is a open register, if yes then redirect to POS screen.
        if ($this->cashRegisterUtil->countOpenedRegister() != 0) {
            return redirect()->action('SellPosController@create', ['sub_type' => $sub_type]);
        }
        $business_locations = BusinessLocation::forDropdown($business_id);

        // Sarah 2026-05-13: surface other cashiers' open registers as a
        // FYI banner on the open form. The new cashier still proceeds
        // (the handover-close flow handles it), but giving them a
        // heads-up means they can ask the prior cashier to close
        // properly first instead of triggering the locked-amount confirm
        // screen for the other person. Soft warning, not a block.
        $other_open_cashiers = [];
        try {
            $others = CashRegister::where('business_id', $business_id)
                ->where('status', 'open')
                ->where('user_id', '!=', auth()->user()->id)
                ->orderBy('created_at', 'asc')
                ->get();
            foreach ($others as $o) {
                $u = \App\User::find($o->user_id);
                $name = $u
                    ? trim(($u->surname ?? '') . ' ' . ($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))
                    : ('User #' . $o->user_id);
                $name = preg_replace('/\s+/', ' ', $name) ?: ('User #' . $o->user_id);
                $loc = \DB::table('business_locations')->where('id', $o->location_id)->value('name');
                $other_open_cashiers[] = [
                    'name'     => $name,
                    'location' => $loc ?: 'Unknown store',
                    'opened'   => \Carbon::parse($o->created_at)
                        ->setTimezone('America/Los_Angeles')->format('g:i A'),
                ];
            }
        } catch (\Throwable $e) {
            \Log::warning('other-open-cashiers fetch failed: ' . $e->getMessage());
        }

        // Sarah 2026-05-14: if this cashier has a recent auto-closed
        // register (system swept it past 12h because they forgot to
        // close), require them to type why before they can open a
        // new shift. Reason goes into the prior register's
        // closing_note so /admin/admin-action-history + the recon
        // page can see it. Skips registers that already carry a
        // reason — one prompt per missed close.
        $prior_unclosed = null;
        try {
            $userId = (int) auth()->user()->id;
            $sevenDaysAgo = \Carbon::now()->subDays(7)->format('Y-m-d H:i:s');
            $candidate = CashRegister::where('business_id', $business_id)
                ->where('user_id', $userId)
                ->where('status', 'close')
                ->where('closed_at', '>=', $sevenDaysAgo)
                ->where('closing_note', 'like', '%Auto-closed by system%')
                ->where(function ($q) {
                    $q->whereNull('closing_note')
                      ->orWhere('closing_note', 'not like', '%Cashier reason:%');
                })
                ->orderBy('closed_at', 'desc')
                ->first();
            if ($candidate) {
                $opened = \Carbon::parse($candidate->created_at)
                    ->setTimezone('America/Los_Angeles');
                $closed = \Carbon::parse($candidate->closed_at)
                    ->setTimezone('America/Los_Angeles');
                $loc = \DB::table('business_locations')
                    ->where('id', $candidate->location_id)
                    ->value('name');
                $prior_unclosed = [
                    'register_id' => (int) $candidate->id,
                    'location'    => $loc ?: 'Unknown store',
                    'opened_at'   => $opened->format('M j, g:i A'),
                    'closed_at'   => $closed->format('M j, g:i A'),
                ];
            }
        } catch (\Throwable $e) {
            \Log::warning('prior-unclosed lookup failed: ' . $e->getMessage());
        }

        return view('cash_register.create')->with(compact('business_locations', 'sub_type', 'other_open_cashiers', 'prior_unclosed'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //like:repair
        $sub_type = request()->get('sub_type');
            
        try {
            // What the cashier counted in the drawer.
            $counted_amount = !empty($request->input('amount'))
                ? (float) $this->cashRegisterUtil->num_uf($request->input('amount'))
                : 0.0;

            // What they ACTUALLY moved to the safe at open (Sarah 2026-05-08).
            // Empty / blank = nothing was moved. Trusted as-is — never auto-
            // filled from the suggestion, because pre-fill risks recording
            // a phantom drop when the cashier didn't actually move anything.
            $rawOpenDrop = $request->input('safe_drop_amount');
            $open_safe_drop = ($rawOpenDrop === null || $rawOpenDrop === '')
                ? 0.0
                : (float) $this->cashRegisterUtil->num_uf($rawOpenDrop);

            // Opening balance recorded for reconciliation = what's left in
            // the drawer after the safe drop. Clamp to 0 if the cashier
            // somehow typed a drop > count (data-entry mistake).
            $initial_amount = max(0.0, $counted_amount - $open_safe_drop);

            $user_id = $request->session()->get('user.id');
            $business_id = $request->session()->get('user.business_id');
            $location_id = $request->input('location_id');

            // Sarah 2026-05-13: sweep >12h abandoned shifts BEFORE the gate
            // so they don't block the next cashier. System-only close —
            // no human closes another human's register.
            $this->cashRegisterUtil->autoCloseStaleOpenRegisters($business_id, 12);

            // Sarah 2026-05-14: if this cashier has a recent auto-closed
            // register with no written reason, require the reason here
            // before letting them open a new shift. Mirrors the lookup
            // in create() above. Writing it back to the prior register's
            // closing_note keeps the audit trail on the row it belongs
            // to (not on the new register, which is unrelated).
            $sevenDaysAgo = \Carbon::now()->subDays(7)->format('Y-m-d H:i:s');
            $priorUnclosed = CashRegister::where('business_id', $business_id)
                ->where('user_id', $user_id)
                ->where('status', 'close')
                ->where('closed_at', '>=', $sevenDaysAgo)
                ->where('closing_note', 'like', '%Auto-closed by system%')
                ->where(function ($q) {
                    $q->whereNull('closing_note')
                      ->orWhere('closing_note', 'not like', '%Cashier reason:%');
                })
                ->orderBy('closed_at', 'desc')
                ->first();
            if ($priorUnclosed) {
                $reason = trim((string) $request->input('prev_close_reason', ''));
                if ($reason === '') {
                    return redirect()->back()
                        ->with('status', [
                            'success' => 0,
                            'msg' => 'You left your previous register open. Please type why you didn\'t close it before opening a new one.',
                        ])
                        ->withInput();
                }
                $nowLa = \Carbon::now()->setTimezone('America/Los_Angeles')->format('M j, g:i A');
                $stamped = "Cashier reason: {$reason} (typed at {$nowLa})";
                $priorUnclosed->closing_note = $priorUnclosed->closing_note
                    ? trim($priorUnclosed->closing_note) . "\n" . $stamped
                    : $stamped;
                $priorUnclosed->save();
            }

            // Sarah 2026-05-13: only block the SAME cashier from opening
            // twice (one register per shift policy). DIFFERENT cashiers at
            // the same store are now allowed to open — we don't have
            // managers onsite to clear a stuck register, and blocking the
            // next cashier means the floor stops working. The prior
            // cashier (who left without closing) gets force-routed to
            // their close form on their next login via the gate in
            // SellPosController@create — so "make them close it" is
            // enforced at their next session, not at the next cashier's
            // expense.
            $existing_open_self = CashRegister::where('business_id', $business_id)
                ->where('status', 'open')
                ->where('user_id', $user_id)
                ->latest('id')
                ->first();
            if ($existing_open_self) {
                $opened = \Carbon::parse($existing_open_self->created_at)
                    ->setTimezone('America/Los_Angeles')->format('g:i A');
                $openedLoc = \DB::table('business_locations')
                    ->where('id', $existing_open_self->location_id)
                    ->value('name');
                return redirect()->action('SellPosController@create', ['sub_type' => $sub_type])
                    ->with('status', [
                        'success' => 1,
                        'msg' => 'Register is already open'
                            . ($openedLoc ? " at {$openedLoc}" : '')
                            . ' (since ' . $opened . '). One register per shift — keep ringing here.',
                    ]);
            }

            $registerData = [
                'business_id' => $business_id,
                'user_id' => $user_id,
                'status' => 'open',
                'location_id' => $location_id,
                'created_at' => \Carbon::now()->format('Y-m-d H:i:00'),
            ];
            // Only set safe_drop_amount when the column exists (the
            // /admin/install-safe-drop-column installer might not have run
            // on every environment) AND when the cashier actually dropped
            // something — leaving NULL/0 untouched on no-drop opens.
            if ($open_safe_drop > 0 && \Schema::hasColumn('cash_registers', 'safe_drop_amount')) {
                $registerData['safe_drop_amount'] = $open_safe_drop;
            }

            $register = CashRegister::create($registerData);

            // Sarah 2026-05-13: previously we auto-closed the prior cashier's
            // shift on handover (using the new cashier's count). Reverted
            // because Sarah didn't want logging in as a cashier to silently
            // close Luis's register out from under him. Multiple open
            // registers at the same store are now allowed — the FYI banner
            // on the open form tells the new cashier who else is open so
            // they can decide whether to ask them to close first.

            if (!empty($initial_amount)) {
                $register->cash_register_transactions()->create([
                            'amount' => $initial_amount,
                            'pay_method' => 'cash',
                            'type' => 'credit',
                            'transaction_type' => 'initial'
                        ]);
            }

        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
        }

        return redirect()->action('SellPosController@create', ['sub_type' => $sub_type]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\CashRegister  $cashRegister
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (!auth()->user()->can('view_cash_register')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $register_details =  $this->cashRegisterUtil->getRegisterDetails($id);
        $user_id = $register_details->user_id;
        $open_time = $register_details['open_time'];
        $close_time = !empty($register_details['closed_at']) ? $register_details['closed_at'] : \Carbon::now()->toDateTimeString();
        $details = $this->cashRegisterUtil->getRegisterTransactionDetails($user_id, $open_time, $close_time);

        $payment_types = $this->cashRegisterUtil->payment_types(null, false, $business_id);

        return view('cash_register.register_details')
                    ->with(compact('register_details', 'details', 'payment_types', 'close_time'));
    }

    /**
     * Shows register details modal.
     *
     * @param  void
     * @return \Illuminate\Http\Response
     */
    public function getRegisterDetails()
    {
        if (!auth()->user()->can('view_cash_register')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        
        $register_details =  $this->cashRegisterUtil->getRegisterDetails();

        $user_id = auth()->user()->id;
        $open_time = $register_details['open_time'];
        $close_time = \Carbon::now()->toDateTimeString();

        $is_types_of_service_enabled = $this->moduleUtil->isModuleEnabled('types_of_service');

        $details = $this->cashRegisterUtil->getRegisterTransactionDetails($user_id, $open_time, $close_time, $is_types_of_service_enabled);

        $payment_types = $this->cashRegisterUtil->payment_types($register_details->location_id, true, $business_id);
        
        return view('cash_register.register_details')
                ->with(compact('register_details', 'details', 'payment_types', 'close_time'));
    }

    /**
     * Shows close register form.
     *
     * @param  void
     * @return \Illuminate\Http\Response
     */
    public function getCloseRegister($id = null)
    {
        if (!auth()->user()->can('close_cash_register')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $register_details =  $this->cashRegisterUtil->getRegisterDetails($id);

        $user_id = $register_details->user_id;
        $open_time = $register_details['open_time'];
        $close_time = \Carbon::now()->toDateTimeString();

        $is_types_of_service_enabled = $this->moduleUtil->isModuleEnabled('types_of_service');

        $details = $this->cashRegisterUtil->getRegisterTransactionDetails($user_id, $open_time, $close_time, $is_types_of_service_enabled);

        $payment_types = $this->cashRegisterUtil->payment_types($register_details->location_id, true, $business_id);

        $pos_settings = !empty(request()->session()->get('business.pos_settings')) ? json_decode(request()->session()->get('business.pos_settings'), true) : [];

        // Sarah 2026-05-06: surface keying errors at close so the cashier
        // sees "you typed $6.59 on Clover but the sale was $6.71" before
        // leaving their shift. Wrapped in try/catch — POS close flow
        // MUST never break, so any DB hiccup just yields no warnings
        // rather than crashing the modal.
        $keying_errors = [];
        try {
            $keying_errors = $this->detectShiftKeyingErrors(
                $business_id, $user_id, $register_details->location_id, $open_time, $close_time
            );
        } catch (\Throwable $ex) {
            \Log::warning('detectShiftKeyingErrors failed: ' . $ex->getMessage());
        }

        return view('cash_register.close_register_modal')
                    ->with(compact('register_details', 'details', 'payment_types', 'pos_settings', 'keying_errors'));
    }

    /**
     * Find Clover swipes during this cashier's shift whose amount
     * matched an ERP sale within 25¢ + 10min but drifted by more than
     * 5¢. These are the "you typed the wrong amount" tells: same sale,
     * but Clover charged a different number than the POS recorded.
     *
     * Returns an array of ['ts', 'clover_amount', 'erp_amount', 'diff']
     * pairs. Negative diff = Clover undercharged.
     */
    private function detectShiftKeyingErrors($business_id, $user_id, $location_id, $open_time, $close_time): array
    {
        $cps = \DB::table('clover_payments as cp')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->where('cp.paid_at', '>=', $open_time)
            ->where('cp.paid_at', '<=', $close_time)
            ->when($location_id, function ($q) use ($location_id) {
                $q->where(function ($q2) use ($location_id) {
                    $q2->where('cp.location_id', $location_id)->orWhereNull('cp.location_id');
                });
            })
            ->orderBy('cp.paid_at')
            ->get(['cp.id', 'cp.paid_at as ts', 'cp.amount']);

        if ($cps->isEmpty()) return [];

        $sells = \DB::table('transactions as t')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.created_by', $user_id)
            ->where('t.transaction_date', '>=', $open_time)
            ->where('t.transaction_date', '<=', $close_time)
            ->when($location_id, fn($q) => $q->where('t.location_id', $location_id))
            ->get(['t.id', 't.transaction_date as ts', 't.final_total']);

        if ($sells->isEmpty()) return [];

        $toCents = function ($x) { return (int) round(((float) $x) * 100); };
        $claimed = [];
        $errors  = [];
        foreach ($cps as $cp) {
            $cpTs = strtotime((string) $cp->ts);
            $cpC  = $toCents($cp->amount);
            $bestId = null; $bestScore = PHP_INT_MAX; $bestAbs = 0; $bestERP = 0;
            foreach ($sells as $s) {
                if (isset($claimed[$s->id])) continue;
                $erpC = $toCents($s->final_total);
                $abs = abs($cpC - $erpC);
                if ($abs > 25) continue;
                $td = abs(strtotime((string) $s->ts) - $cpTs);
                if ($td > 1800) continue; // 30min window — slow typers
                $score = $abs * 1000 + $td;
                if ($score < $bestScore) {
                    $bestScore = $score; $bestId = $s->id; $bestAbs = $abs;
                    $bestERP = (float) $s->final_total;
                }
            }
            if ($bestId !== null && $bestAbs > 5) {
                $claimed[$bestId] = true;
                $errors[] = [
                    'ts' => $cp->ts,
                    'clover_amount' => round((float) $cp->amount, 2),
                    'erp_amount'    => round($bestERP, 2),
                    'diff'          => round(((float) $cp->amount) - $bestERP, 2),
                ];
            } elseif ($bestId !== null) {
                $claimed[$bestId] = true; // claim clean matches so they don't re-pair
            }
        }
        return $errors;
    }

    /**
     * Closes currently opened register.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function postCloseRegister(Request $request)
    {
        if (!auth()->user()->can('close_cash_register')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            //Disable in demo
            if (config('app.env') == 'demo') {
                $output = ['success' => 0,
                                'msg' => 'Feature disabled in demo!!'
                            ];
                return redirect()->action('HomeController@index')->with('status', $output);
            }
            
            $input = $request->only(['closing_amount', 'total_card_slips', 'total_cheques', 'closing_note']);
            $input['closing_amount'] = $this->cashRegisterUtil->num_uf($input['closing_amount']);
            $user_id = $request->input('user_id');
            $input['closed_at'] = \Carbon::now()->format('Y-m-d H:i:s');
            $input['status'] = 'close';
            $input['denominations'] = !empty(request()->input('denominations')) ? json_encode(request()->input('denominations')) : null;

            // Capture how much cash the cashier moved to the safe at close.
            // Additive against whatever was already dropped at open
            // (Sarah 2026-05-08) so the column tracks total drops for the
            // shift — important when the cashier drops both at open
            // (drawer started heavy) and again at close. A blank/zero
            // close drop preserves the open drop instead of clobbering it.
            if (\Schema::hasColumn('cash_registers', 'safe_drop_amount')) {
                $rawDrop = $request->input('safe_drop_amount');
                $closeDrop = ($rawDrop === null || $rawDrop === '')
                    ? 0.0
                    : (float) $this->cashRegisterUtil->num_uf($rawDrop);
                if ($closeDrop > 0) {
                    $input['safe_drop_amount'] = \DB::raw(
                        'COALESCE(safe_drop_amount, 0) + ' . (float) $closeDrop
                    );
                }
                // closeDrop == 0 → no change; leave whatever the open
                // drop wrote (or NULL/0 if there was no open drop).
            }

            CashRegister::where('user_id', $user_id)
                                ->where('status', 'open')
                                ->update($input);
            $output = ['success' => 1,
                            'msg' => __('cash_register.close_success')
                        ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            $output = ['success' => 0,
                            'msg' => __("messages.something_went_wrong")
                        ];
        }

        return redirect()->back()->with('status', $output);
    }
}
