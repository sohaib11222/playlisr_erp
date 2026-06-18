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

        // Next deposit number to write on the post-it, per store. The open
        // form lets the cashier pick a location, so JS swaps the displayed
        // number to match the selected store's next number.
        $next_deposit_seqs = [];
        foreach ($business_locations as $loc_id => $loc_name) {
            $next_deposit_seqs[$loc_id] = $this->nextDepositSeq($business_id, $loc_id);
        }
        $cashier_name = $this->cashierDisplayName(auth()->user());

        return view('cash_register.create')->with(compact('business_locations', 'sub_type', 'other_open_cashiers', 'prior_unclosed', 'next_deposit_seqs', 'cashier_name'));
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
                            'msg' => 'Please add a quick note about your last shift before opening a new one.',
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

            // Log the open-time safe drop as its own deposit (assigns the
            // per-store deposit number the cashier wrote on the post-it).
            // Independent of safe_drop_amount on the register row.
            if ($open_safe_drop > 0) {
                $this->recordDeposit(
                    $business_id,
                    $location_id,
                    $register->id,
                    $user_id,
                    $this->cashierDisplayName(auth()->user()),
                    $open_safe_drop,
                    'open'
                );
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
    /**
     * Cashier's display name in the same surname-first format used across
     * the open-register banners. Safe on a null user.
     */
    private function cashierDisplayName($user): string
    {
        if (!$user) {
            return '';
        }
        $name = trim(($user->surname ?? '') . ' ' . ($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        return preg_replace('/\s+/', ' ', $name) ?: '';
    }

    /**
     * Next per-store deposit number to SHOW the cashier on the open/close
     * form so they can write "Deposit #N" on the post-it. Display-only —
     * the authoritative number is assigned in recordDeposit() at submit.
     * Returns 1 when the table isn't installed yet.
     */
    private function nextDepositSeq($business_id, $location_id): int
    {
        if (!\Schema::hasTable('cash_deposits')) {
            return 1;
        }
        $max = (int) \DB::table('cash_deposits')
            ->where('business_id', $business_id)
            ->where('location_id', $location_id)
            ->max('deposit_seq');
        return $max + 1;
    }

    /**
     * Persist one safe-drop deposit and return its assigned per-store
     * sequence number. Atomic max+1 under a row lock so two cashiers
     * dropping at the same store don't collide; the unique index is the
     * backstop. Never throws into the open/close flow — on any failure it
     * logs and returns 0 (the drop amount is still saved on the register).
     */
    private function recordDeposit($business_id, $location_id, $cash_register_id, $user_id, $cashier_name, $amount, $phase): int
    {
        if (!\Schema::hasTable('cash_deposits') || (float) $amount <= 0) {
            return 0;
        }
        try {
            return \DB::transaction(function () use ($business_id, $location_id, $cash_register_id, $user_id, $cashier_name, $amount, $phase) {
                $max = (int) \DB::table('cash_deposits')
                    ->where('business_id', $business_id)
                    ->where('location_id', $location_id)
                    ->lockForUpdate()
                    ->max('deposit_seq');
                $seq = $max + 1;
                $now = \Carbon::now()->format('Y-m-d H:i:s');
                \DB::table('cash_deposits')->insert([
                    'business_id'      => $business_id,
                    'location_id'      => $location_id,
                    'cash_register_id' => $cash_register_id,
                    'user_id'          => $user_id,
                    'cashier_name'     => $cashier_name,
                    'deposit_seq'      => $seq,
                    'amount'           => (float) $amount,
                    'phase'            => $phase,
                    'deposited_at'     => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
                return $seq;
            });
        } catch (\Throwable $e) {
            \Log::warning('recordDeposit failed: ' . $e->getMessage());
            return 0;
        }
    }

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

        // Next deposit number to write on the post-it for the safe drop at
        // close. Location is known here (this register's store).
        $next_deposit_seq = $this->nextDepositSeq($business_id, $register_details->location_id);
        $cashier_name = $this->cashierDisplayName(\App\User::find($register_details->user_id));

        // Auto shift-notes summary (Sarah): sales + items mass-added + items
        // purchased + labels printed/value/categories for this shift, shown
        // read-only in the close modal. Gated so it stays dark for cashiers
        // until the feature is flipped live; admins always see it (preview).
        // Wrapped in try/catch — close flow MUST never break.
        $shift_summary = null;
        if ($this->shiftNotesEnabled()) {
            try {
                $shift_summary = $this->buildShiftSummary(
                    $business_id, $user_id, $register_details->location_id, $open_time, $close_time
                );
            } catch (\Throwable $ex) {
                \Log::warning('buildShiftSummary failed: ' . $ex->getMessage());
            }
        }

        return view('cash_register.close_register_modal')
                    ->with(compact('register_details', 'details', 'payment_types', 'pos_settings', 'keying_errors', 'next_deposit_seq', 'cashier_name', 'shift_summary'));
    }

    /**
     * Auto shift-notes visible? On for everyone when the config flag is
     * flipped live; always on for admins/owners so the feature can be
     * verified in production before rollout. Never throws.
     */
    private function shiftNotesEnabled(): bool
    {
        if (config('nivessa.shift_notes_enabled')) {
            return true;
        }
        try {
            $u = auth()->user();
            if ($u && ($u->can('superadmin') || $u->hasAnyPermission('Admin#' . $u->business_id))) {
                return true;
            }
        } catch (\Throwable $e) {
            // permission backend hiccup — fall through to hidden
        }
        return false;
    }

    /**
     * Build the auto-populated shift summary for one cashier's shift:
     * sales rung, items added via the mass-add form, items entered on the
     * purchase form, and labels printed (count, total value, category mix).
     * Mirrors the per-user queries in the Employee Productivity report,
     * scoped to a single user + this shift's time window.
     */
    private function buildShiftSummary($business_id, $user_id, $location_id, $open_time, $close_time): array
    {
        $sales = (float) \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('created_by', $user_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereBetween('created_at', [$open_time, $close_time])
            ->sum('final_total');

        $mass_add_count = 0;
        if (\Schema::hasColumn('products', 'added_via')) {
            $mass_add_count = (int) \DB::table('products')
                ->where('business_id', $business_id)
                ->where('created_by', $user_id)
                ->where('added_via', 'mass_add')
                ->whereBetween('created_at', [$open_time, $close_time])
                ->count();
        }

        $purchase_add_count = (int) \DB::table('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.created_by', $user_id)
            ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
            ->whereBetween('t.transaction_date', [$open_time, $close_time])
            ->count();

        // Labels printed = products put out from the supplier this shift.
        // LabelsController@preview logs one activity_log row per print run
        // with qty + value + category mix in properties.
        $labels_printed_count = 0;
        $labels_value = 0.0;
        $labels_categories = [];
        $label_rows = \DB::table('activity_log')
            ->where('description', 'labels_printed')
            ->where('business_id', $business_id)
            ->where('causer_id', $user_id)
            ->whereBetween('created_at', [$open_time, $close_time])
            ->pluck('properties');
        foreach ($label_rows as $props) {
            $d = json_decode($props, true) ?: [];
            $labels_printed_count += (int) ($d['qty'] ?? 0);
            $labels_value += (float) ($d['value'] ?? 0);
            foreach (($d['categories'] ?? []) as $k => $c) {
                $labels_categories[$k] = ($labels_categories[$k] ?? 0) + (int) $c;
            }
        }
        arsort($labels_categories);

        return [
            'sales' => round($sales, 2),
            'mass_add_count' => $mass_add_count,
            'purchase_add_count' => $purchase_add_count,
            'labels_printed_count' => $labels_printed_count,
            'labels_value' => round($labels_value, 2),
            'labels_categories' => $labels_categories,
        ];
    }

    /**
     * Persist the shift note (auto summary + the cashier's free-text note)
     * to storage/app/shift-notes/ at close. JSON file per close — no
     * migration, no extra table. This is the durable record the eventual
     * Slack auto-poster will read; for now it just captures the data so
     * the feature can be validated before going live. Never throws into
     * the caller (caller wraps in try/catch as well).
     */
    private function persistShiftNote(Request $request, $register, $user_id, $note): void
    {
        $business_id = $request->session()->get('user.business_id');
        $open_time = (string) $register->created_at;
        $close_time = \Carbon::now()->toDateTimeString();

        $summary = $this->buildShiftSummary($business_id, $user_id, $register->location_id, $open_time, $close_time);
        $location = \App\BusinessLocation::find($register->location_id);

        $payload = [
            'register_id' => $register->id,
            'business_id' => $business_id,
            'employee' => $this->cashierDisplayName(\App\User::find($user_id)),
            'location' => $location->name ?? null,
            'shift_start' => $open_time,
            'shift_end' => $close_time,
            'summary' => $summary,
            'note' => $note,
            'posted_to_slack' => false,
            'created_at' => $close_time,
        ];

        $date = \Carbon::now()->format('Y-m-d');
        $dir = storage_path('app/shift-notes/' . $date);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/register-' . $register->id . '-' . \Carbon::now()->format('His') . '.json';
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
            $rawDrop = $request->input('safe_drop_amount');
            $closeDrop = ($rawDrop === null || $rawDrop === '')
                ? 0.0
                : (float) $this->cashRegisterUtil->num_uf($rawDrop);
            if (\Schema::hasColumn('cash_registers', 'safe_drop_amount') && $closeDrop > 0) {
                $input['safe_drop_amount'] = \DB::raw(
                    'COALESCE(safe_drop_amount, 0) + ' . (float) $closeDrop
                );
                // closeDrop == 0 → no change; leave whatever the open
                // drop wrote (or NULL/0 if there was no open drop).
            }

            // Capture the register before the update so the deposit log can
            // record its store + id (status flips to 'close' below).
            $openRegister = CashRegister::where('user_id', $user_id)
                                ->where('status', 'open')
                                ->latest('id')
                                ->first();

            CashRegister::where('user_id', $user_id)
                                ->where('status', 'open')
                                ->update($input);

            // Log the close-time safe drop as its own deposit (assigns the
            // per-store deposit number the cashier wrote on the post-it).
            if ($closeDrop > 0 && $openRegister) {
                $this->recordDeposit(
                    $request->session()->get('user.business_id'),
                    $openRegister->location_id,
                    $openRegister->id,
                    $user_id,
                    $this->cashierDisplayName(\App\User::find($user_id)),
                    $closeDrop,
                    'close'
                );
            }

            // Auto shift-notes: write the shift summary + the cashier's note
            // to storage/app/shift-notes/ (Sarah). Gated + isolated in its
            // own try/catch so a failure here can never break the close.
            if ($this->shiftNotesEnabled() && $openRegister) {
                try {
                    $this->persistShiftNote($request, $openRegister, $user_id, $input['closing_note'] ?? null);
                } catch (\Throwable $ex) {
                    \Log::warning('persistShiftNote failed: ' . $ex->getMessage());
                }
            }

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
