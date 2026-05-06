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

        //Check if there is a open register, if yes then redirect to POS screen.
        if ($this->cashRegisterUtil->countOpenedRegister() != 0) {
            return redirect()->action('SellPosController@create', ['sub_type' => $sub_type]);
        }
        $business_id = request()->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id);

        return view('cash_register.create')->with(compact('business_locations', 'sub_type'));
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
            $initial_amount = 0;
            if (!empty($request->input('amount'))) {
                $initial_amount = $this->cashRegisterUtil->num_uf($request->input('amount'));
            }
            $user_id = $request->session()->get('user.id');
            $business_id = $request->session()->get('user.business_id');

            $register = CashRegister::create([
                        'business_id' => $business_id,
                        'user_id' => $user_id,
                        'status' => 'open',
                        'location_id' => $request->input('location_id'),
                        'created_at' => \Carbon::now()->format('Y-m-d H:i:00')
                    ]);
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
                if ($td > 600) continue;
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
