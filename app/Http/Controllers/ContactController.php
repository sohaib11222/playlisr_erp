<?php

namespace App\Http\Controllers;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\CustomerGroup;
use App\Notifications\CustomerNotification;
use App\PurchaseLine;
use App\Transaction;
use App\User;
use App\Utils\ContactUtil;
use App\Utils\ModuleUtil;
use App\Utils\NotificationUtil;
use App\Utils\TransactionUtil;
use App\Utils\Util;
use DB;
use Excel;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use App\TransactionPayment;
use Spatie\Activitylog\Models\Activity;

class ContactController extends Controller
{
    protected $commonUtil;
    protected $contactUtil;
    protected $transactionUtil;
    protected $moduleUtil;
    protected $notificationUtil;

    /**
     * Constructor
     *
     * @param Util $commonUtil
     * @return void
     */
    public function __construct(
        Util $commonUtil,
        ModuleUtil $moduleUtil,
        TransactionUtil $transactionUtil,
        NotificationUtil $notificationUtil,
        ContactUtil $contactUtil
    ) {
        $this->commonUtil = $commonUtil;
        $this->contactUtil = $contactUtil;
        $this->moduleUtil = $moduleUtil;
        $this->transactionUtil = $transactionUtil;
        $this->notificationUtil = $notificationUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');

        $type = request()->get('type');

        $types = ['supplier', 'customer'];

        if (empty($type) || !in_array($type, $types)) {
            return redirect()->back();
        }

        if (request()->ajax()) {
            if ($type == 'supplier') {
                return $this->indexSupplier();
            } elseif ($type == 'customer') {
                return $this->indexCustomer();
            } else {
                die("Not Found");
            }
        }

        $reward_enabled = (request()->session()->get('business.enable_rp') == 1 && in_array($type, ['customer'])) ? true : false;

        $users = User::forDropdown($business_id);

        $customer_groups = [];
        if ($type == 'customer') {
            $customer_groups = CustomerGroup::forDropdown($business_id);
        }
        
        return view('contact.index')
            ->with(compact('type', 'reward_enabled', 'customer_groups', 'users'));
    }

    /**
     * Returns the database object for supplier
     *
     * @return \Illuminate\Http\Response
     */
    private function indexSupplier()
    {
        if (!auth()->user()->can('supplier.view') && !auth()->user()->can('supplier.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $contact = $this->contactUtil->getContactQuery($business_id, 'supplier');

        if (request()->has('has_purchase_due')) {
           $contact->havingRaw('(total_purchase - purchase_paid) > 0');
        }

        if (request()->has('has_purchase_return')) {
           $contact->havingRaw('total_purchase_return > 0');
        }

        if (request()->has('has_advance_balance')) {
           $contact->where('balance', '>', 0);
        }

        if (request()->has('has_opening_balance')) {
           $contact->havingRaw('opening_balance > 0');
        }

        if (!empty(request()->input('contact_status'))) {
            $contact->where('contacts.contact_status', request()->input('contact_status'));
        }

        if (!empty(request()->input('assigned_to'))) {
            $contact->join('user_contact_access AS uc', 'contacts.id', 'uc.contact_id')
                ->where('uc.user_id', request()->input('assigned_to'));
        }

        return Datatables::of($contact)
            ->addColumn('address', '{{implode(", ", array_filter([$address_line_1, $address_line_2, $city, $state, $country, $zip_code]))}}')
            ->addColumn(
                'due',
                '<span class="contact_due" data-orig-value="{{$total_purchase - $purchase_paid - $total_ledger_discount}}" data-highlight=false>@format_currency($total_purchase - $purchase_paid - $total_ledger_discount)</span>'
            )
            ->addColumn(
                'return_due',
                '<span class="return_due" data-orig-value="{{$total_purchase_return - $purchase_return_paid}}" data-highlight=false>@format_currency($total_purchase_return - $purchase_return_paid)'
            )
            ->addColumn(
                'action',
                function ($row) {
                    $html = '<div class="btn-group">
                    <button type="button" class="btn btn-info dropdown-toggle btn-xs" 
                        data-toggle="dropdown" aria-expanded="false">' .
                        __("messages.actions") .
                        '<span class="caret"></span><span class="sr-only">Toggle Dropdown
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-left" role="menu">';



                    if (auth()->user()->can('supplier.update')) {
                        $html .= '<li><a href="' . action('ContactController@edit', [$row->id]) . '" class="edit_contact_button"><i class="glyphicon glyphicon-edit"></i>' .  __("messages.edit") . '</a></li>';
                    }
                    if (auth()->user()->can('supplier.delete')) {
                        $html .= '<li><a href="' . action('ContactController@destroy', [$row->id]) . '" class="delete_contact_button"><i class="glyphicon glyphicon-trash"></i>' . __("messages.delete") . '</a></li>';
                    }



                    $html .= '<li class="divider"></li>';

                    $html .= '</ul></div>';

                    return $html;
                }
            )
            ->editColumn('opening_balance', function ($row) {
                $html = '<span data-orig-value="' . $row->opening_balance . '">' . $this->transactionUtil->num_f($row->opening_balance, true) . '</span>';

                return $html;
            })
            ->editColumn('balance', function ($row) {
                $html = '<span data-orig-value="' . $row->balance . '">' . $this->transactionUtil->num_f($row->balance, true) . '</span>';

                return $html;
            })
            ->editColumn('pay_term', '
                @if(!empty($pay_term_type) && !empty($pay_term_number))
                    {{$pay_term_number}}
                    @lang("lang_v1.".$pay_term_type)
                @endif
            ')
            ->editColumn('name', function ($row) {
                if ($row->contact_status == 'inactive') {
                    return $row->name . ' <small class="label pull-right bg-red no-print">' . __("lang_v1.inactive") . '</small>';
                } else {
                    return $row->name;
                }
            })
            ->editColumn('created_at', '{{@format_date($created_at)}}')
            ->removeColumn('opening_balance_paid')
            ->removeColumn('type')
            ->removeColumn('id')
            ->removeColumn('total_purchase')
            ->removeColumn('purchase_paid')
            ->removeColumn('total_purchase_return')
            ->removeColumn('purchase_return_paid')
            ->filterColumn('address', function ($query, $keyword) {
                $query->where( function($q) use ($keyword){
                    $q->where('address_line_1', 'like', "%{$keyword}%")
                    ->orWhere('address_line_2', 'like', "%{$keyword}%")
                    ->orWhere('city', 'like', "%{$keyword}%")
                    ->orWhere('state', 'like', "%{$keyword}%")
                    ->orWhere('country', 'like', "%{$keyword}%")
                    ->orWhere('zip_code', 'like', "%{$keyword}%")
                    ->orWhereRaw("CONCAT(COALESCE(address_line_1, ''), ', ', COALESCE(address_line_2, ''), ', ', COALESCE(city, ''), ', ', COALESCE(state, ''), ', ', COALESCE(country, '') ) like ?", ["%{$keyword}%"]);
                });
            })
            ->rawColumns(['action', 'opening_balance', 'pay_term', 'due', 'return_due', 'name', 'balance', 'mobile', 'preorders_count'])
            ->make(true);
    }

    /**
     * Returns the database object for customer
     *
     * @return \Illuminate\Http\Response
     */
    private function indexCustomer()
    {
        if (!auth()->user()->can('customer.view') && !auth()->user()->can('customer.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $is_admin = $this->contactUtil->is_admin(auth()->user());

        $query = $this->contactUtil->getContactQuery($business_id, 'customer');
        

        if (request()->has('has_sell_due')) {
           $query->havingRaw('(total_invoice - invoice_received) > 0');
        }

        if (request()->has('has_sell_return')) {
           $query->havingRaw('total_sell_return > 0');
        }

        if (request()->has('has_advance_balance')) {
           $query->where('balance', '>', 0);
        }

        if (request()->has('has_opening_balance')) {
           $query->havingRaw('opening_balance > 0');
        }

         if (!empty(request()->input('assigned_to'))) {
            $query->join('user_contact_access AS uc', 'contacts.id', 'uc.contact_id')
                ->where('uc.user_id', request()->input('assigned_to'));
        }

        $has_no_sell_from = request()->input('has_no_sell_from', null);

        if (
            (!$is_admin && auth()->user()->can('customer_with_no_sell_one_month')) || 
            ($has_no_sell_from == 'one_month' && (auth()->user()->can('customer_with_no_sell_one_month') || auth()->user()->can('customer_irrespective_of_sell')) ) 
            ) {
            $from_transaction_date = \Carbon::now()->subDays(30)->format('Y-m-d');
            $query->havingRaw("max_transaction_date < '{$from_transaction_date}'")
                     ->orHavingRaw('transaction_date IS NULL');
        }

        if (
            (!$is_admin && auth()->user()->can('customer_with_no_sell_three_month')) || 
            ($has_no_sell_from == 'three_months' && (auth()->user()->can('customer_with_no_sell_three_month') || auth()->user()->can('customer_irrespective_of_sell')) ) 
        ) {
            $from_transaction_date = \Carbon::now()->subMonths(3)->format('Y-m-d');
            $query->havingRaw("max_transaction_date < '{$from_transaction_date}'")
                     ->orHavingRaw('transaction_date IS NULL');
        }

        if (
            (!$is_admin && auth()->user()->can('customer_with_no_sell_six_month')) || 
            ($has_no_sell_from == 'six_months' && (auth()->user()->can('customer_with_no_sell_six_month') || auth()->user()->can('customer_irrespective_of_sell')) ) 
        ) {
            $from_transaction_date = \Carbon::now()->subMonths(6)->format('Y-m-d');
            $query->havingRaw("max_transaction_date < '{$from_transaction_date}'")
                     ->orHavingRaw('transaction_date IS NULL');
        }

        if ((!$is_admin && auth()->user()->can('customer_with_no_sell_one_year')) || 
            ($has_no_sell_from == 'one_year' && (auth()->user()->can('customer_with_no_sell_one_year') || auth()->user()->can('customer_irrespective_of_sell')) ) 
        ) {
            $from_transaction_date = \Carbon::now()->subYear()->format('Y-m-d');
            $query->havingRaw("max_transaction_date < '{$from_transaction_date}'")
                     ->orHavingRaw('transaction_date IS NULL');
        }

        if (!empty(request()->input('customer_group_id'))) {
            $query->where('contacts.customer_group_id', request()->input('customer_group_id'));
        }

        if (!empty(request()->input('contact_status'))) {
            $query->where('contacts.contact_status', request()->input('contact_status'));
        }

        $hasPreordersTable = false;
        try {
            $hasPreordersTable = \Illuminate\Support\Facades\Schema::hasTable('preorders');
        } catch (\Exception $e) {
            $hasPreordersTable = false;
        }

        if ($hasPreordersTable) {
            $query->leftJoin(
                DB::raw("(SELECT contact_id, COUNT(*) as pending_preorders_count FROM preorders WHERE status = 'pending' GROUP BY contact_id) as preorder_counts"),
                'preorder_counts.contact_id',
                '=',
                'contacts.id'
            )->addSelect(DB::raw('COALESCE(MAX(preorder_counts.pending_preorders_count), 0) as pending_preorders_count'));
        }

        $contacts = Datatables::of($query)
            ->addColumn('address', '{{implode(", ", array_filter([$address_line_1, $address_line_2, $city, $state, $country, $zip_code]))}}')
            ->addColumn(
                'due',
                '<span class="contact_due" data-orig-value="{{$total_invoice - $invoice_received - $total_ledger_discount}}" data-highlight=true>@format_currency($total_invoice - $invoice_received - $total_ledger_discount)</span>'
            )
            ->addColumn(
                'return_due',
                '<span class="return_due" data-orig-value="{{$total_sell_return - $sell_return_paid}}" data-highlight=false>@format_currency($total_sell_return - $sell_return_paid)</span>'
            )
            ->addColumn(
                'action',
                function ($row) {
                    // Use direct buttons instead of dropdown for better click reliability on customer list.
                    // 2026-04-22: Sarah reported the yellow Adjust button was missing —
                    // it was rendering but clipped by the narrow Action column
                    // (5 btn-xs buttons in a single-line .btn-group overflow). Switching
                    // to an inline-flex wrap so every button stays visible and clickable
                    // even on a narrow screen.
                    $html = '<div class="btn-group" style="display:inline-flex;flex-wrap:wrap;gap:4px;min-width:230px;">' .
                        '<a href="' . action('ContactController@show', [$row->id]) . '" class="btn btn-xs btn-info">' .
                        '<i class="fa fa-user"></i> View</a>';

                    if (auth()->user()->can('customer.update')) {
                        $html .= '<a href="' . action('ContactController@edit', [$row->id]) . '" class="btn btn-xs btn-primary edit_contact_button">' .
                            '<i class="glyphicon glyphicon-edit"></i> ' . __("messages.edit") . '</a>';
                        // Store-credit add/adjust moved to the customer detail
                        // page (Sarah 2026-04-22): keeping it here duplicated the
                        // workflow and encouraged "easy-add" clicks from the list
                        // without the full customer context. Cashiers now click
                        // View → Credits & Adjustments panel to add or adjust.
                    }
                    if (!$row->is_default && auth()->user()->can('customer.delete')) {
                        $html .= '<a href="' . action('ContactController@destroy', [$row->id]) . '" class="btn btn-xs btn-danger delete_contact_button">' .
                            '<i class="glyphicon glyphicon-trash"></i> ' . __("messages.delete") . '</a>';
                    }

                    $html .= '</div>';

                    return $html;
                }
            )
            ->editColumn('opening_balance', function ($row) {
                $html = '<span data-orig-value="' . $row->opening_balance . '">' . $this->transactionUtil->num_f($row->opening_balance, true) . '</span>';

                return $html;
            })
            ->editColumn('balance', function ($row) {
                $html = '<span data-orig-value="' . $row->balance . '">' . $this->transactionUtil->num_f($row->balance, true) . '</span>';

                return $html;
            })
            ->editColumn('credit_limit', function ($row) {
                $html = __('lang_v1.no_limit');
                if (!is_null($row->credit_limit)) {
                    $html = '<span data-orig-value="' . $row->credit_limit . '">' . $this->transactionUtil->num_f($row->credit_limit, true) . '</span>';
                }

                return $html;
            })
            ->editColumn('pay_term', '
                @if(!empty($pay_term_type) && !empty($pay_term_number))
                    {{$pay_term_number}}
                    @lang("lang_v1.".$pay_term_type)
                @endif
            ')
            ->editColumn('name', function ($row) {
                $name = $row->name;
                if ($row->contact_status == 'inactive') {
                    $name = $row->name . ' <small class="label pull-right bg-red no-print">' . __("lang_v1.inactive") . '</small>';
                }

                if (!empty($row->converted_by)) {
                    $name .= '<span class="label bg-info label-round no-print" data-toggle="tooltip" title="Converted from leads"><i class="fas fa-sync-alt"></i></span>';
                }
                
                // Make name clickable to open customer profile page
                $name = '<a href="' . action('ContactController@show', [$row->id]) . '" style="color: #3c8dbc; cursor: pointer;">' . $name . '</a>';
                
                return $name;
            })
            ->editColumn('mobile', function ($row) {
                return $row->mobile ?? '';
            })
            ->addColumn('store_credit', function ($row) {
                // Customer balance IS the store-credit pool. Surface it in the list
                // so Sarah can see at a glance who has credit on account without
                // having to click into each profile. Green for positive, muted
                // for zero.
                $bal = (float) ($row->balance ?? 0);
                $color = $bal > 0 ? '#166534' : '#9ca3af';
                $weight = $bal > 0 ? '600' : '400';
                return '<span style="color:' . $color . ';font-weight:' . $weight . ';font-variant-numeric:tabular-nums;">'
                    . $this->transactionUtil->num_f($bal, true) . '</span>';
            })
            ->addColumn('lifetime_purchases', function ($row) {
                $lifetime = $row->lifetime_purchases ?? 0;
                return $this->transactionUtil->num_f($lifetime, true);
            })
            ->addColumn('loyalty_points', function ($row) {
                $points = $row->loyalty_points ?? 0;
                // If loyalty_points column doesn't exist, try total_rp
                if ($points == 0 && isset($row->total_rp)) {
                    $points = $row->total_rp ?? 0;
                }
                return $points;
            })
            ->addColumn('loyalty_tier', function ($row) {
                $tier = $row->loyalty_tier ?? 'Bronze';
                return $tier;
            })
            ->addColumn('preorders_count', function ($row) {
                $count = (int) ($row->pending_preorders_count ?? 0);
                if ($count > 0) {
                    return '<span class="label label-warning">' . $count . '</span>';
                }
                return '0';
            })
            ->editColumn('total_rp', '{{$total_rp ?? 0}}')
            ->editColumn('created_at', '{{@format_date($created_at)}}')
            ->removeColumn('total_invoice')
            ->removeColumn('opening_balance_paid')
            ->removeColumn('invoice_received')
            ->removeColumn('state')
            ->removeColumn('country')
            ->removeColumn('city')
            ->removeColumn('type')
            ->removeColumn('id')
            ->removeColumn('is_default')
            ->removeColumn('total_sell_return')
            ->removeColumn('sell_return_paid')
            ->filterColumn('address', function ($query, $keyword) {
                $query->where( function($q) use ($keyword){
                    $q->where('address_line_1', 'like', "%{$keyword}%")
                    ->orWhere('address_line_2', 'like', "%{$keyword}%")
                    ->orWhere('city', 'like', "%{$keyword}%")
                    ->orWhere('state', 'like', "%{$keyword}%")
                    ->orWhere('country', 'like', "%{$keyword}%")
                    ->orWhere('zip_code', 'like', "%{$keyword}%")
                    ->orWhereRaw("CONCAT(COALESCE(address_line_1, ''), ', ', COALESCE(address_line_2, ''), ', ', COALESCE(city, ''), ', ', COALESCE(state, ''), ', ', COALESCE(country, '') ) like ?", ["%{$keyword}%"]);
                });
            });
        $reward_enabled = (request()->session()->get('business.enable_rp') == 1) ? true : false;
        if (!$reward_enabled) {
            $contacts->removeColumn('total_rp');
        }
        return $contacts->rawColumns(['action', 'opening_balance', 'credit_limit', 'pay_term', 'due', 'return_due', 'name', 'balance', 'store_credit', 'preorders_count'])
                        ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (!auth()->user()->can('supplier.create') && !auth()->user()->can('customer.create') && !auth()->user()->can('customer.view_own') && !auth()->user()->can('supplier.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }

        $types = [];
        if (auth()->user()->can('supplier.create') || auth()->user()->can('supplier.view_own')) {
            $types['supplier'] = __('report.supplier');
        }
        if (auth()->user()->can('customer.create') || auth()->user()->can('customer.view_own')) {
            $types['customer'] = __('report.customer');
        }
        if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create') || auth()->user()->can('supplier.view_own') || auth()->user()->can('customer.view_own')) {
            $types['both'] = __('lang_v1.both_supplier_customer');
        }

        $customer_groups = CustomerGroup::forDropdown($business_id);
        $selected_type = request()->type;

        $module_form_parts = $this->moduleUtil->getModuleData('contact_form_part');

        //Added check because $users is of no use if enable_contact_assign if false
        $users = config('constants.enable_contact_assign') ? User::forDropdown($business_id, false, false, false, true) : [];
        
        return view('contact.create')
            ->with(compact('types', 'customer_groups', 'selected_type', 'module_form_parts', 'users'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('supplier.create') && !auth()->user()->can('customer.create') && !auth()->user()->can('customer.view_own') && !auth()->user()->can('supplier.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');

            if (!$this->moduleUtil->isSubscribed($business_id)) {
                return $this->moduleUtil->expiredResponse();
            }

            // Validate that at least email OR mobile is provided
            $email = $request->input('email');
            $mobile = $request->input('mobile');
            if (empty($email) && empty($mobile)) {
                $output = ['success' => false,
                            'msg' => __('lang_v1.email_or_mobile_required')
                        ];
                return $output;
            }

            $input = $request->only(['type', 'supplier_business_name',
                'prefix', 'first_name', 'middle_name', 'last_name', 'tax_number', 'pay_term_number', 'pay_term_type', 'mobile', 'landline', 'alternate_number', 'city', 'state', 'country', 'address_line_1', 'address_line_2', 'customer_group_id', 'zip_code', 'contact_id', 'custom_field1', 'custom_field2', 'custom_field3', 'custom_field4', 'custom_field5', 'custom_field6', 'custom_field7', 'custom_field8', 'custom_field9', 'custom_field10', 'email', 'shipping_address', 'position', 'dob', 'shipping_custom_field_details', 'assigned_to_users', 'is_employee', 'opt_in_marketing']);
            $input['opt_in_marketing'] = !empty($request->input('opt_in_marketing')) ? 1 : 0;

            $name_array = [];

            if (!empty($input['prefix'])) {
                $name_array[] = $input['prefix'];
            }
            if (!empty($input['first_name'])) {
                $name_array[] = $input['first_name'];
            }
            // Middle name removed - no longer used
            if (!empty($input['last_name'])) {
                $name_array[] = $input['last_name'];
            }

            $input['name'] = trim(implode(' ', $name_array));

            if (!empty($request->input('is_export'))) {
                $input['is_export'] = true;
                $input['export_custom_field_1'] = $request->input('export_custom_field_1');
                $input['export_custom_field_2'] = $request->input('export_custom_field_2');
                $input['export_custom_field_3'] = $request->input('export_custom_field_3');
                $input['export_custom_field_4'] = $request->input('export_custom_field_4');
                $input['export_custom_field_5'] = $request->input('export_custom_field_5');
                $input['export_custom_field_6'] = $request->input('export_custom_field_6');
            }

            if (!empty($input['dob'])) {
                $input['dob'] = $this->commonUtil->uf_date($input['dob']);
            }

            $input['business_id'] = $business_id;
            $input['created_by'] = $request->session()->get('user.id');

            $input['credit_limit'] = $request->input('credit_limit') != '' ? $this->commonUtil->num_uf($request->input('credit_limit')) : null;
            $input['opening_balance'] = $this->commonUtil->num_uf($request->input('opening_balance'));

            DB::beginTransaction();
            $output = $this->contactUtil->createNewContact($input);

            $this->moduleUtil->getModuleData('after_contact_saved', ['contact' => $output['data'], 'input' => $request->input()]);

            $this->contactUtil->activityLog($output['data'], 'added');

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => false,
                            'msg' =>__("messages.something_went_wrong")
                        ];
        }

        return $output;
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (!auth()->user()->can('supplier.view') && !auth()->user()->can('customer.view') && !auth()->user()->can('customer.view_own') && !auth()->user()->can('supplier.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $contact = $this->contactUtil->getContactInfo($business_id, $id);

        $is_selected_contacts = User::isSelectedContacts(auth()->user()->id);
        $user_contacts = [];
        if ($is_selected_contacts) {
            $user_contacts = auth()->user()->contactAccess->pluck('id')->toArray();
        }

        if (!auth()->user()->can('supplier.view') && auth()->user()->can('supplier.view_own')) {
            if ($contact->created_by != auth()->user()->id & !in_array($contact->id, $user_contacts)) {
                abort(403, 'Unauthorized action.');
            }
        }
        if (!auth()->user()->can('customer.view') && auth()->user()->can('customer.view_own')) {
            if ($contact->created_by != auth()->user()->id & !in_array($contact->id, $user_contacts)) {
                abort(403, 'Unauthorized action.');
            }
        }

        $reward_enabled = (request()->session()->get('business.enable_rp') == 1 && in_array($contact->type, ['customer', 'both'])) ? true : false;

        $contact_dropdown = Contact::contactDropdown($business_id, false, false);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        //get contact view type : ledger, notes etc.
        $view_type = request()->get('view');
        if (is_null($view_type)) {
            $view_type = 'ledger';
        }

        $contact_view_tabs = $this->moduleUtil->getModuleData('get_contact_view_tabs');

        $activities = Activity::forSubject($contact)
           ->with(['causer', 'subject'])
           ->latest()
           ->get();
        
        // Get gift cards for the customer (if customer type and gift cards table exists)
        $gift_cards = collect([]);
        $total_gift_card_balance = 0;
        try {
            if (in_array($contact->type, ['customer', 'both']) && \Illuminate\Support\Facades\Schema::hasTable('gift_cards')) {
                $gift_cards = \App\GiftCard::where('business_id', $business_id)
                    ->where('contact_id', $contact->id)
                    ->where('status', 'active')
                    ->where('balance', '>', 0)
                    ->orderBy('created_at', 'desc')
                    ->get();
                
                $total_gift_card_balance = $gift_cards->sum('balance');
            }
        } catch (\Exception $e) {
            \Log::warning('Gift cards not available: ' . $e->getMessage());
        }

        // Profile data: recent purchases, stats, tier info
        $recent_purchases = collect([]);
        $purchase_history = collect([]);
        $sell_count = 0;
        $avg_order = 0;
        $visits_90d = 0;
        $current_tier = null;
        $next_tier = null;
        $tier_progress = 0;

        if (in_array($contact->type, ['customer', 'both'])) {
            // Historical-sales imports wrote the original artist/title into
            // legacy_artist / legacy_title on the sell line rather than
            // creating a Product row (so imports don't pollute inventory).
            // These columns come from the 2026_04_21_141217 migration —
            // guard with Schema::hasColumn so the page still renders on
            // environments where migrations haven't been run yet
            // (2026-04-22: unguarded select was 500'ing /contacts/{id}).
            $hasLegacyArtist = \Illuminate\Support\Facades\Schema::hasColumn('transaction_sell_lines', 'legacy_artist');
            $hasLegacyTitle  = \Illuminate\Support\Facades\Schema::hasColumn('transaction_sell_lines', 'legacy_title');

            $selectCols = [
                'transaction_sell_lines.id',
                'p.name as product_name',
                'p.artist',
                'p.image as product_image',
                't.transaction_date',
                't.invoice_no',
                'transaction_sell_lines.unit_price_inc_tax',
                'transaction_sell_lines.quantity',
                'bl.name as location_name',
                DB::raw("CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) as staff_name"),
            ];
            if ($hasLegacyArtist) {
                $selectCols[] = 'transaction_sell_lines.legacy_artist';
            } else {
                $selectCols[] = DB::raw("NULL as legacy_artist");
            }
            if ($hasLegacyTitle) {
                $selectCols[] = 'transaction_sell_lines.legacy_title';
            } else {
                $selectCols[] = DB::raw("NULL as legacy_title");
            }

            $sell_lines_query = \App\TransactionSellLine::join('transactions as t', 'transaction_sell_lines.transaction_id', '=', 't.id')
                ->leftJoin('products as p', 'transaction_sell_lines.product_id', '=', 'p.id')
                ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
                ->leftJoin('users as u', 't.created_by', '=', 'u.id')
                ->where('t.contact_id', $contact->id)
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereNull('t.return_parent_id')
                ->select($selectCols)
                ->orderBy('t.transaction_date', 'desc');

            $recent_purchases = (clone $sell_lines_query)->limit(4)->get();
            $purchase_history = (clone $sell_lines_query)->limit(50)->get();

            $sell_count = Transaction::where('contact_id', $contact->id)
                ->where('business_id', $business_id)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->count();

            $avg_order = $sell_count > 0 ? ($contact->total_invoice / $sell_count) : 0;

            $visits_90d = Transaction::where('contact_id', $contact->id)
                ->where('business_id', $business_id)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->where('transaction_date', '>=', \Carbon\Carbon::now()->subDays(90))
                ->count();

            // Loyalty tier info
            try {
                $all_tiers = \App\LoyaltyTier::getActiveTiers($business_id);
                if ($all_tiers->isNotEmpty()) {
                    $lifetime = $contact->total_invoice ?? 0;
                    foreach ($all_tiers as $tier) {
                        if ($lifetime >= $tier->min_lifetime_purchases) {
                            $current_tier = $tier;
                        } else {
                            $next_tier = $tier;
                            break;
                        }
                    }
                    if ($current_tier && $next_tier) {
                        $range = $next_tier->min_lifetime_purchases - $current_tier->min_lifetime_purchases;
                        $progress = $lifetime - $current_tier->min_lifetime_purchases;
                        $tier_progress = $range > 0 ? min(100, round(($progress / $range) * 100)) : 100;
                    } elseif ($current_tier && !$next_tier) {
                        $tier_progress = 100;
                    }
                }
            } catch (\Exception $e) {
                // Loyalty tiers may not be set up
            }
        }

        // Customer notes
        $customer_notes = collect([]);
        try {
            $customer_notes = \App\DocumentAndNote::where('notable_id', $contact->id)
                ->where('notable_type', 'App\Contact')
                ->with('createdBy')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            // Notes table may not exist
        }

        return view('contact.show')
             ->with(compact(
                 'contact', 'reward_enabled', 'contact_dropdown', 'business_locations',
                 'view_type', 'contact_view_tabs', 'activities', 'gift_cards', 'total_gift_card_balance',
                 'recent_purchases', 'purchase_history', 'sell_count', 'avg_order', 'visits_90d',
                 'current_tier', 'next_tier', 'tier_progress', 'customer_notes'
             ));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (!auth()->user()->can('supplier.update') && !auth()->user()->can('customer.update') && !auth()->user()->can('customer.view_own') && !auth()->user()->can('supplier.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $contact = Contact::where('business_id', $business_id)->find($id);

            if (!$this->moduleUtil->isSubscribed($business_id)) {
                return $this->moduleUtil->expiredResponse();
            }

            $types = [];
            if (auth()->user()->can('supplier.create')) {
                $types['supplier'] = __('report.supplier');
            }
            if (auth()->user()->can('customer.create')) {
                $types['customer'] = __('report.customer');
            }
            if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
                $types['both'] = __('lang_v1.both_supplier_customer');
            }

            $customer_groups = CustomerGroup::forDropdown($business_id);

            $ob_transaction =  Transaction::where('contact_id', $id)
                                            ->where('type', 'opening_balance')
                                            ->first();
            $opening_balance = !empty($ob_transaction->final_total) ? $ob_transaction->final_total : 0;

            //Deduct paid amount from opening balance.
            if (!empty($opening_balance)) {
                $opening_balance_paid = $this->transactionUtil->getTotalAmountPaid($ob_transaction->id);
                if (!empty($opening_balance_paid)) {
                    $opening_balance = $opening_balance - $opening_balance_paid;
                }

                $opening_balance = $this->commonUtil->num_f($opening_balance);
            }

            //Added check because $users is of no use if enable_contact_assign if false
            $users = config('constants.enable_contact_assign') ? User::forDropdown($business_id, false, false, false, true) : [];

            return view('contact.edit')
                ->with(compact('contact', 'types', 'customer_groups', 'opening_balance', 'users'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('supplier.update') && !auth()->user()->can('customer.update') && !auth()->user()->can('customer.view_own') && !auth()->user()->can('supplier.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                // Validate that at least email OR mobile is provided
                $email = $request->input('email');
                $mobile = $request->input('mobile');
                if (empty($email) && empty($mobile)) {
                    $output = ['success' => false,
                                'msg' => __('lang_v1.email_or_mobile_required')
                            ];
                    return $output;
                }

                $input = $request->only(['type', 'supplier_business_name', 'prefix', 'first_name', 'middle_name', 'last_name', 'tax_number', 'pay_term_number', 'pay_term_type', 'mobile', 'address_line_1', 'address_line_2', 'zip_code', 'dob', 'alternate_number', 'city', 'state', 'country', 'landline', 'customer_group_id', 'contact_id', 'custom_field1', 'custom_field2', 'custom_field3', 'custom_field4', 'custom_field5', 'custom_field6', 'custom_field7', 'custom_field8', 'custom_field9', 'custom_field10', 'email', 'shipping_address', 'position', 'shipping_custom_field_details', 'export_custom_field_1', 'export_custom_field_2', 'export_custom_field_3', 'export_custom_field_4', 'export_custom_field_5',
                    'export_custom_field_6', 'assigned_to_users', 'is_employee', 'opt_in_marketing']);
                $input['opt_in_marketing'] = !empty($request->input('opt_in_marketing')) ? 1 : 0;

                $name_array = [];

                if (!empty($input['prefix'])) {
                    $name_array[] = $input['prefix'];
                }
                if (!empty($input['first_name'])) {
                    $name_array[] = $input['first_name'];
                }
                // Middle name removed - no longer used
                if (!empty($input['last_name'])) {
                    $name_array[] = $input['last_name'];
                }

                $input['name'] = trim(implode(' ', $name_array));

                $input['is_export'] = !empty($request->input('is_export')) ? 1 : 0;

                if (!$input['is_export']) {
                    unset($input['export_custom_field_1'], $input['export_custom_field_2'], $input['export_custom_field_3'], $input['export_custom_field_4'], $input['export_custom_field_5'], $input['export_custom_field_6']);
                }

                if (!empty($input['dob'])) {
                    $input['dob'] = $this->commonUtil->uf_date($input['dob']);
                }

                $input['credit_limit'] = $request->input('credit_limit') != '' ? $this->commonUtil->num_uf($request->input('credit_limit')) : null;
                
                $business_id = $request->session()->get('user.business_id');

                $input['opening_balance'] = $this->commonUtil->num_uf($request->input('opening_balance'));

                if (!$this->moduleUtil->isSubscribed($business_id)) {
                    return $this->moduleUtil->expiredResponse();
                }

                $output = $this->contactUtil->updateContact($input, $id, $business_id);

                $this->contactUtil->activityLog($output['data'], 'edited');

            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
                $output = ['success' => false,
                            'msg' => __("messages.something_went_wrong")
                        ];
            }

            return $output;
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('supplier.delete') && !auth()->user()->can('customer.delete') && !auth()->user()->can('customer.view_own') && !auth()->user()->can('supplier.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->user()->business_id;

                //Check if any transaction related to this contact exists
                $count = Transaction::where('business_id', $business_id)
                                    ->where('contact_id', $id)
                                    ->count();
                if ($count == 0) {
                    $contact = Contact::where('business_id', $business_id)->findOrFail($id);
                    if (!$contact->is_default) {

                        $log_properities = [
                            'id' => $contact->id,
                            'name' => $contact->name,
                            'supplier_business_name' => $contact->supplier_business_name
                        ];
                        $this->contactUtil->activityLog($contact, 'contact_deleted', $log_properities);

                        //Disable login for associated users
                        User::where('crm_contact_id', $contact->id)
                            ->update(['allow_login' => 0]);

                        $contact->delete();
                    }
                    $output = ['success' => true,
                                'msg' => __("contact.deleted_success")
                                ];
                } else {
                    $output = ['success' => false,
                                'msg' => __("lang_v1.you_cannot_delete_this_contact")
                                ];
                }
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
                $output = ['success' => false,
                            'msg' => __("messages.something_went_wrong")
                        ];
            }

            return $output;
        }
    }

    /**
     * Retrieves list of customers, if filter is passed then filter it accordingly.
     *
     * @param  string  $q
     * @return JSON
     */
    public function getCustomers(){
        if (request()->ajax()) {
            $term = trim((string) request()->input('q', ''));
            $minSearchLength = 2;
            $defaultLimit = 50;
            $searchLimit = 100;

            $business_id = request()->session()->get('user.business_id');

            $contacts = Contact::where('contacts.business_id', $business_id)
                            ->leftjoin('customer_groups as cg', 'cg.id', '=', 'contacts.customer_group_id')
                            ->active();

            if (!request()->has('all_contact')) {
                // Business decision: allow all employees/admins to access the same customer usernames.
                $contacts->whereIn('contacts.type', ['customer', 'both']);
            }

            if ($term !== '' && mb_strlen($term) < $minSearchLength) {
                return json_encode([]);
            }

            if (!empty($term)) {
                $contacts->where(function ($query) use ($term) {
                    $query->where('contacts.name', 'like', '%' . $term .'%')
                            ->orWhere('supplier_business_name', 'like', '%' . $term .'%')
                            ->orWhere('mobile', 'like', '%' . $term .'%')
                            ->orWhere('contacts.contact_id', 'like', '%' . $term .'%');
                });
            }

            $contacts->select(
                'contacts.id',
                DB::raw("IF(contacts.contact_id IS NULL OR contacts.contact_id='', contacts.name, CONCAT(contacts.name, ' (', contacts.contact_id, ')')) AS text"),
                'mobile',
                'address_line_1',
                'address_line_2',
                'city',
                'state',
                'country',
                'zip_code',
                'shipping_address',
                'pay_term_number',
                'pay_term_type',
                'balance',
                'supplier_business_name',
                'cg.amount as discount_percent',
                'cg.price_calculation_type',
                'cg.selling_price_group_id',
                'shipping_custom_field_details',
                'is_export',
                'export_custom_field_1',
                'export_custom_field_2',
                'export_custom_field_3',
                'export_custom_field_4',
                'export_custom_field_5',
                'export_custom_field_6',
                'contacts.is_employee'
            );
                    
            if (request()->session()->get('business.enable_rp') == 1) {
                $contacts->addSelect('total_rp');
            }

            if (empty($term)) {
                $contacts->orderBy('contacts.name')->limit($defaultLimit);
            } else {
                $contacts->limit($searchLimit);
            }

            $contacts = $contacts->get();
            return json_encode($contacts);
        }
    }

    /**
     * Checks if the given contact id already exist for the current business.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function checkContactId(Request $request)
    {
        $contact_id = $request->input('contact_id');

        $valid = 'true';
        if (!empty($contact_id)) {
            $business_id = $request->session()->get('user.business_id');
            $hidden_id = $request->input('hidden_id');

            $query = Contact::where('business_id', $business_id)
                            ->where('contact_id', $contact_id);
            if (!empty($hidden_id)) {
                $query->where('id', '!=', $hidden_id);
            }
            $count = $query->count();
            if ($count > 0) {
                $valid = 'false';
            }
        }
        echo $valid;
        exit;
    }

    /**
     * Shows import option for contacts
     *
     * @param  \Illuminate\Http\Request
     * @return \Illuminate\Http\Response
     */
    public function getImportContacts()
    {
        if (!auth()->user()->can('supplier.create') && !auth()->user()->can('customer.create')) {
            abort(403, 'Unauthorized action.');
        }

        $zip_loaded = extension_loaded('zip') ? true : false;

        //Check if zip extension it loaded or not.
        if ($zip_loaded === false) {
            $output = ['success' => 0,
                            'msg' => 'Please install/enable PHP Zip archive for import'
                        ];

            return view('contact.import')
                ->with('notification', $output);
        } else {
            return view('contact.import');
        }
    }

    /**
     * Imports contacts
     *
     * @param  \Illuminate\Http\Request
     * @return \Illuminate\Http\Response
     */
    public function postImportContacts(Request $request)
    {
        if (!auth()->user()->can('supplier.create') && !auth()->user()->can('customer.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $notAllowed = $this->commonUtil->notAllowedInDemo();
            if (!empty($notAllowed)) {
                return $notAllowed;
            }
            
            //Set maximum php execution time
            ini_set('max_execution_time', 0);

            if ($request->hasFile('contacts_csv')) {
                $file = $request->file('contacts_csv');
                $parsed_array = Excel::toArray([], $file);
                //Remove header row
                $imported_data = array_splice($parsed_array[0], 1);
                
                $business_id = $request->session()->get('user.business_id');
                $user_id = $request->session()->get('user.id');

                $formated_data = [];

                $is_valid = true;
                $error_msg = '';
                
                DB::beginTransaction();
                foreach ($imported_data as $key => $value) {
                    //Check if 27 no. of columns exists
                    if (count($value) != 27) {
                        $is_valid =  false;
                        $error_msg = "Number of columns mismatch";
                        break;
                    }

                    $row_no = $key + 1;
                    $contact_array = [];

                    //Check contact type
                    $contact_type = '';
                    $contact_types = [
                        1 => 'customer',
                        2 => 'supplier',
                        3 => 'both'
                    ];
                    if (!empty($value[0])) {
                        $contact_type = strtolower(trim($value[0]));
                        if (in_array($contact_type, [1, 2, 3])) {
                            $contact_array['type'] = $contact_types[$contact_type];
                            $contact_type = $contact_types[$contact_type];
                        } else {
                            $is_valid =  false;
                            $error_msg = "Invalid contact type $contact_type in row no. $row_no";
                            break;
                        }
                    } else {
                        $is_valid =  false;
                        $error_msg = "Contact type is required in row no. $row_no";
                        break;
                    }

                    $contact_array['prefix'] = $value[1]; 
                    //Check contact name
                    if (!empty($value[2])) {
                        $contact_array['first_name'] = $value[2];
                    } else {
                        $is_valid =  false;
                        $error_msg = "First name is required in row no. $row_no";
                        break;
                    }
                    $contact_array['middle_name'] = $value[3];
                    $contact_array['last_name'] = $value[4];
                    $contact_array['name'] = implode(' ', [$contact_array['prefix'], $contact_array['first_name'], $contact_array['middle_name'], $contact_array['last_name']]);

                    //Check business name
                    if (!empty(trim($value[5]))) {
                        $contact_array['supplier_business_name'] = $value[5];
                    } 

                    //Check supplier fields
                    if (in_array($contact_type, ['supplier', 'both'])) {
                        //Check pay term
                        if (trim($value[9]) != '') {
                            $contact_array['pay_term_number'] = trim($value[9]);
                        } else {
                            $is_valid =  false;
                            $error_msg = "Pay term is required in row no. $row_no";
                            break;
                        }

                        //Check pay period
                        $pay_term_type = strtolower(trim($value[10]));
                        if (in_array($pay_term_type, ['days', 'months'])) {
                            $contact_array['pay_term_type'] = $pay_term_type;
                        } else {
                            $is_valid =  false;
                            $error_msg = "Pay term period is required in row no. $row_no";
                            break;
                        }
                    }

                    //Check contact ID
                    if (!empty(trim($value[6]))) {
                        $count = Contact::where('business_id', $business_id)
                                    ->where('contact_id', $value[6])
                                    ->count();
                

                        if ($count == 0) {
                            $contact_array['contact_id'] = $value[6];
                        } else {
                            $is_valid =  false;
                            $error_msg = "Contact ID already exists in row no. $row_no";
                            break;
                        }
                    }

                    //Tax number
                    if (!empty(trim($value[7]))) {
                        $contact_array['tax_number'] = $value[7];
                    }

                    //Check opening balance
                    if (!empty(trim($value[8])) && $value[8] != 0) {
                        $contact_array['opening_balance'] = trim($value[8]);
                    }

                    //Check credit limit
                    if (trim($value[11]) != '' && in_array($contact_type, ['customer', 'both'])) {
                        $contact_array['credit_limit'] = trim($value[11]);
                    }

                    //Check email
                    if (!empty(trim($value[12]))) {
                        if (filter_var(trim($value[12]), FILTER_VALIDATE_EMAIL)) {
                            $contact_array['email'] = $value[12];
                        } else {
                            $is_valid =  false;
                            $error_msg = "Invalid email id in row no. $row_no";
                            break;
                        }
                    }

                    //Mobile number
                    if (!empty(trim($value[13]))) {
                        $contact_array['mobile'] = $value[13];
                    } else {
                        $is_valid =  false;
                        $error_msg = "Mobile number is required in row no. $row_no";
                        break;
                    }

                    //Alt contact number
                    $contact_array['alternate_number'] = $value[14];

                    //Landline
                    $contact_array['landline'] = $value[15];

                    //City
                    $contact_array['city'] = $value[16];

                    //State
                    $contact_array['state'] = $value[17];

                    //Country
                    $contact_array['country'] = $value[18];

                    //address_line_1
                    $contact_array['address_line_1'] = $value[19];
                    //address_line_2
                    $contact_array['address_line_2'] = $value[20];
                    $contact_array['zip_code'] = $value[21];
                    $contact_array['dob'] = $value[22];

                    //Cust fields
                    $contact_array['custom_field1'] = $value[23];
                    $contact_array['custom_field2'] = $value[24];
                    $contact_array['custom_field3'] = $value[25];
                    $contact_array['custom_field4'] = $value[26];

                    $formated_data[] = $contact_array;
                }
                if (!$is_valid) {
                    throw new \Exception($error_msg);
                }

                if (!empty($formated_data)) {
                    foreach ($formated_data as $contact_data) {
                        $ref_count = $this->transactionUtil->setAndGetReferenceCount('contacts');
                        //Set contact id if empty
                        if (empty($contact_data['contact_id'])) {
                            $contact_data['contact_id'] = $this->commonUtil->generateReferenceNumber('contacts', $ref_count);
                        }

                        $opening_balance = 0;
                        if (isset($contact_data['opening_balance'])) {
                            $opening_balance = $contact_data['opening_balance'];
                            unset($contact_data['opening_balance']);
                        }

                        $contact_data['business_id'] = $business_id;
                        $contact_data['created_by'] = $user_id;

                        $contact = Contact::create($contact_data);

                        if (!empty($opening_balance)) {
                            $this->transactionUtil->createOpeningBalanceTransaction($business_id, $contact->id, $opening_balance, $user_id, false);
                        }

                        $this->transactionUtil->activityLog($contact, 'imported');
                    }
                }

                $output = ['success' => 1,
                            'msg' => __('product.file_imported_successfully')
                        ];

                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => $e->getMessage()
                        ];
            return redirect()->route('contacts.import')->with('notification', $output);
        }
        $type = !empty($contact->type) && $contact->type != 'both' ? $contact->type : 'supplier';
        return redirect()->action('ContactController@index', ['type' => $type])->with('status', $output);
    }

    /**
     * Shows ledger for contacts
     *
     * @param  \Illuminate\Http\Request
     * @return \Illuminate\Http\Response
     */
    public function getLedger()
    {
        if (!auth()->user()->can('supplier.view') && !auth()->user()->can('customer.view') && !auth()->user()->can('supplier.view_own') && !auth()->user()->can('customer.view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $contact_id = request()->input('contact_id');

        $is_admin = $this->contactUtil->is_admin(auth()->user());

        $start_date = request()->start_date;
        $end_date =  request()->end_date;
        $format =  request()->format;
        $location_id =  request()->location_id;

        $contact = Contact::find($contact_id);

        $is_selected_contacts = User::isSelectedContacts(auth()->user()->id);
        $user_contacts = [];
        if ($is_selected_contacts) {
            $user_contacts = auth()->user()->contactAccess->pluck('id')->toArray();
        }

        if (!auth()->user()->can('supplier.view') && auth()->user()->can('supplier.view_own')) {
            if ($contact->created_by != auth()->user()->id & !in_array($contact->id, $user_contacts)) {
                abort(403, 'Unauthorized action.');
            }
        }
        if (!auth()->user()->can('customer.view') && auth()->user()->can('customer.view_own')) {
            if ($contact->created_by != auth()->user()->id & !in_array($contact->id, $user_contacts)) {
                abort(403, 'Unauthorized action.');
            }
        }

        $ledger_details = $this->transactionUtil->getLedgerDetails($contact_id, $start_date, $end_date, $format, $location_id);

        $location = null;
        if (!empty($location_id)) {
            $location = BusinessLocation::where('business_id', $business_id)->find($location_id);
        }

        if (request()->input('action') == 'pdf') {

            $output_file_name = 'Ledger-' . str_replace(' ', '-', $contact->name) . '-' . $start_date . '-' . $end_date . '.pdf';
            $for_pdf = true;
            if ($format == 'format_2') {
                $html = view('contact.ledger_format_2')
                        ->with(compact('ledger_details', 'contact', 'for_pdf', 'location'))->render();
                        
            } else {
                $html = view('contact.ledger')
                    ->with(compact('ledger_details', 'contact', 'for_pdf', 'location'))->render();
            }
            
            $mpdf = $this->getMpdf();
            $mpdf->WriteHTML($html);
            $mpdf->Output($output_file_name, 'I');
        }

        if ($format == 'format_2') {
            return view('contact.ledger_format_2')
             ->with(compact('ledger_details', 'contact', 'location'));
        } else {
            return view('contact.ledger')
             ->with(compact('ledger_details', 'contact', 'location', 'is_admin'));
        }
        
    }

    public function postCustomersApi(Request $request)
    {
        try {
            $api_token = $request->header('API-TOKEN');

            $api_settings = $this->moduleUtil->getApiSettings($api_token);

            $business = Business::find($api_settings->business_id);

            $data = $request->only(['name', 'email']);

            $customer = Contact::where('business_id', $api_settings->business_id)
                                ->where('email', $data['email'])
                                ->whereIn('type', ['customer', 'both'])
                                ->first();

            if (empty($customer)) {
                $data['type'] = 'customer';
                $data['business_id'] = $api_settings->business_id;
                $data['created_by'] = $business->owner_id;
                $data['mobile'] = 0;

                $ref_count = $this->commonUtil->setAndGetReferenceCount('contacts', $business->id);

                $data['contact_id'] = $this->commonUtil->generateReferenceNumber('contacts', $ref_count, $business->id);

                $customer = Contact::create($data);
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            return $this->respondWentWrong($e);
        }

        return $this->respond($customer);
    }

    /**
     * Function to send ledger notification
     *
     */
    public function sendLedger(Request $request)
    {
        $notAllowed = $this->notificationUtil->notAllowedInDemo();
        if (!empty($notAllowed)) {
            return $notAllowed;
        }

        try {
            $data = $request->only(['to_email', 'subject', 'email_body', 'cc', 'bcc', 'ledger_format']);
            $emails_array = array_map('trim', explode(',', $data['to_email']));

            $contact_id = $request->input('contact_id');
            $business_id = request()->session()->get('business.id');

            $start_date = request()->input('start_date');
            $end_date =  request()->input('end_date');
            $location_id =  request()->input('location_id');

            $contact = Contact::find($contact_id);

            $ledger_details = $this->transactionUtil->getLedgerDetails($contact_id, $start_date, $end_date, $data['ledger_format'], $location_id);

            $orig_data = [
                'email_body' => $data['email_body'],
                'subject' => $data['subject']
            ];

            $tag_replaced_data = $this->notificationUtil->replaceTags($business_id, $orig_data, null, $contact);
            $data['email_body'] = $tag_replaced_data['email_body'];
            $data['subject'] = $tag_replaced_data['subject'];

            //replace balance_due
            $data['email_body'] = str_replace('{balance_due}', $this->notificationUtil->num_f($ledger_details['balance_due']), $data['email_body']);
            
            $data['email_settings'] = request()->session()->get('business.email_settings');


            $for_pdf = true;
            if ($data['ledger_format'] == 'format_2') {
                $html = view('contact.ledger_format_2')
                        ->with(compact('ledger_details', 'contact', 'for_pdf'))->render();
            } else {
                $html = view('contact.ledger')
                        ->with(compact('ledger_details', 'contact', 'for_pdf'))->render();
            }
            
            $mpdf = $this->getMpdf();
            $mpdf->WriteHTML($html);

            $path = config('constants.mpdf_temp_path');
            if (!file_exists($path)) {
                mkdir($path, 0777, true);
            }

            $file = $path . '/' . time() . '_ledger.pdf';
            $mpdf->Output($file, 'F');

            $data['attachment'] =  $file;
            $data['attachment_name'] =  'ledger.pdf';
            \Notification::route('mail', $emails_array)
                    ->notify(new CustomerNotification($data));

            if (file_exists($file)) {
                unlink($file);
            }

            $output = ['success' => 1, 'msg' => __('lang_v1.notification_sent_successfully')];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => "File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage()
                        ];
        }

        return $output;
    }

    /**
     * Function to get product stock details for a supplier
     *
     */
    public function getSupplierStockReport($supplier_id)
    {
        //TODO: current stock not calculating stock transferred from other location
        $pl_query_string = $this->commonUtil->get_pl_quantity_sum_string();
        $query = PurchaseLine::join('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
                        ->join('products as p', 'p.id', '=', 'purchase_lines.product_id')
                        ->join('variations as v', 'v.id', '=', 'purchase_lines.variation_id')
                        ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                        ->join('units as u', 'p.unit_id', '=', 'u.id')
                        ->whereIn('t.type', ['purchase', 'purchase_return'])
                        ->where('t.contact_id', $supplier_id)
                        ->select(
                            'p.name as product_name',
                            'v.name as variation_name',
                            'pv.name as product_variation_name',
                            'p.type as product_type',
                            'u.short_name as product_unit',
                            'v.sub_sku',
                            DB::raw('SUM(quantity) as purchase_quantity'),
                            DB::raw('SUM(quantity_returned) as total_quantity_returned'),
                            DB::raw("SUM((SELECT SUM(TSL.quantity - TSL.quantity_returned) FROM transaction_sell_lines_purchase_lines as TSLPL 
                              JOIN transaction_sell_lines AS TSL ON TSLPL.sell_line_id=TSL.id
                              JOIN transactions AS sell ON sell.id=TSL.transaction_id
                              WHERE sell.status='final' AND sell.type='sell'
                              AND TSLPL.purchase_line_id=purchase_lines.id)) as total_quantity_sold"),
                            DB::raw("SUM((SELECT SUM(TSL.quantity - TSL.quantity_returned) FROM transaction_sell_lines_purchase_lines as TSLPL 
                              JOIN transaction_sell_lines AS TSL ON TSLPL.sell_line_id=TSL.id
                              JOIN transactions AS sell ON sell.id=TSL.transaction_id
                              WHERE sell.status='final' AND sell.type='sell_transfer'
                              AND TSLPL.purchase_line_id=purchase_lines.id)) as total_quantity_transfered"),
                            DB::raw("SUM( COALESCE(quantity - ($pl_query_string), 0) * purchase_price_inc_tax) as stock_price"),
                            DB::raw("SUM( COALESCE(quantity - ($pl_query_string), 0)) as current_stock")
                        )->groupBy('purchase_lines.variation_id');

        if (!empty(request()->location_id)) {
            $query->where('t.location_id', request()->location_id);
        }

        $product_stocks =  Datatables::of($query)
                            ->editColumn('product_name', function ($row) {
                                $name = $row->product_name;
                                if ($row->product_type == 'variable') {
                                    $name .= ' - ' . $row->product_variation_name . '-' . $row->variation_name;
                                }
                                return $name . ' (' . $row->sub_sku . ')';
                            })
                            ->editColumn('purchase_quantity', function ($row) {
                                $purchase_quantity = 0;
                                if ($row->purchase_quantity) {
                                    $purchase_quantity =  (float)$row->purchase_quantity;
                                }

                                return '<span data-is_quantity="true" class="display_currency" data-currency_symbol=false  data-orig-value="' . $purchase_quantity . '" data-unit="' . $row->product_unit . '" >' . $purchase_quantity . '</span> ' . $row->product_unit;
                            })
                            ->editColumn('total_quantity_sold', function ($row) {
                                $total_quantity_sold = 0;
                                if ($row->total_quantity_sold) {
                                    $total_quantity_sold =  (float)$row->total_quantity_sold;
                                }

                                return '<span data-is_quantity="true" class="display_currency" data-currency_symbol=false  data-orig-value="' . $total_quantity_sold . '" data-unit="' . $row->product_unit . '" >' . $total_quantity_sold . '</span> ' . $row->product_unit;
                            })
                            ->editColumn('total_quantity_transfered', function ($row) {
                                $total_quantity_transfered = 0;
                                if ($row->total_quantity_transfered) {
                                    $total_quantity_transfered =  (float)$row->total_quantity_transfered;
                                }

                                return '<span data-is_quantity="true" class="display_currency" data-currency_symbol=false  data-orig-value="' . $total_quantity_transfered . '" data-unit="' . $row->product_unit . '" >' . $total_quantity_transfered . '</span> ' . $row->product_unit;
                            })
                            ->editColumn('stock_price', function ($row) {
                                $stock_price = 0;
                                if ($row->stock_price) {
                                    $stock_price =  (float)$row->stock_price;
                                }

                                return '<span class="display_currency" data-currency_symbol=true >' . $stock_price . '</span> ';
                            })
                            ->editColumn('current_stock', function ($row) {
                                $current_stock = 0;
                                if ($row->current_stock) {
                                    $current_stock =  (float)$row->current_stock;
                                }

                                return '<span data-is_quantity="true" class="display_currency" data-currency_symbol=false  data-orig-value="' . $current_stock . '" data-unit="' . $row->product_unit . '" >' . $current_stock . '</span> ' . $row->product_unit;
                            });

        return $product_stocks->rawColumns(['current_stock', 'stock_price', 'total_quantity_sold', 'purchase_quantity', 'total_quantity_transfered'])->make(true);
    }

    public function updateStatus($id)
    {
        if (!auth()->user()->can('supplier.update') && !auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $contact = Contact::where('business_id', $business_id)->find($id);
            $contact->contact_status = $contact->contact_status == 'active' ? 'inactive' : 'active';
            $contact->save();

            $output = ['success' => true,
                                'msg' => __("contact.updated_success")
                                ];
            return $output;
        }
    }

    /**
     * Display contact locations on map
     *
     */
    public function contactMap()
    {
        if (!auth()->user()->can('supplier.view') && !auth()->user()->can('customer.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $query = Contact::where('business_id', $business_id)
                        ->active()
                        ->whereNotNull('position');

        if (!empty(request()->input('contacts'))) {
            $query->whereIn('id', request()->input('contacts'));
        }
        $contacts = $query->get();

        $all_contacts = Contact::where('business_id', $business_id)
                        ->active()
                        ->get();

        return view('contact.contact_map')
             ->with(compact('contacts', 'all_contacts'));
    }

    public function getContactPayments($contact_id)
    {
        $business_id = request()->session()->get('user.business_id');
        if (request()->ajax()) {

            $payments = TransactionPayment::leftjoin('transactions as t', 'transaction_payments.transaction_id', '=', 't.id')
            ->leftjoin('transaction_payments as parent_payment', 'transaction_payments.parent_id', '=', 'parent_payment.id')
            ->where('transaction_payments.business_id', $business_id)
            ->whereNull('transaction_payments.parent_id')
            ->with(['child_payments', 'child_payments.transaction'])
            ->where('transaction_payments.payment_for', $contact_id)
                ->select(
                    'transaction_payments.id',
                    'transaction_payments.amount',
                    'transaction_payments.is_return',
                    'transaction_payments.method',
                    'transaction_payments.paid_on',
                    'transaction_payments.payment_ref_no',
                    'transaction_payments.parent_id',
                    'transaction_payments.transaction_no',
                    't.invoice_no',
                    't.ref_no',
                    't.type as transaction_type',
                    't.return_parent_id',
                    't.id as transaction_id',
                    'transaction_payments.cheque_number',
                    'transaction_payments.card_transaction_number',
                    'transaction_payments.bank_account_number',
                    'transaction_payments.id as DT_RowId',
                    'parent_payment.payment_ref_no as parent_payment_ref_no'
                )
                ->groupBy('transaction_payments.id')
                ->orderByDesc('transaction_payments.paid_on')
                ->paginate();

            $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);

            return view('contact.partials.contact_payments_tab')
                    ->with(compact('payments', 'payment_types'));
        }
    }

    public function getContactDue($contact_id)
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $due = $this->transactionUtil->getContactDue($contact_id, $business_id);

            $output = $due != 0 ? $this->transactionUtil->num_f($due, true) : '';
            return $output;
        }
    }

    public function checkMobile(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $mobile_number = $request->input('mobile_number');

        $query = Contact::where('business_id', $business_id)
                        ->where('mobile', 'like', "%{$mobile_number}");

        if (!empty($request->input('contact_id'))) {
            $query->where('id', '!=', $request->input('contact_id'));
        }

        $contacts = $query->pluck('name')->toArray();

        return [
            'is_mobile_exists' => !empty($contacts),
            'msg' => __('lang_v1.mobile_already_registered', ['contacts' => implode(', ', $contacts), 'mobile' => $mobile_number])
        ];
    }

    public function updateAvatar($id, Request $request)
    {
        if (!auth()->user()->can('customer.update') && !auth()->user()->can('supplier.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $contact = Contact::where('business_id', $business_id)->findOrFail($id);

            if ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
                $avatar = $request->file('avatar');
                $filename = 'contact_' . $contact->id . '_' . time() . '.' . $avatar->getClientOriginalExtension();

                $upload_path = public_path('uploads/contact_avatars');
                if (!file_exists($upload_path)) {
                    mkdir($upload_path, 0755, true);
                }

                // Remove old avatar
                if (!empty($contact->avatar) && file_exists($upload_path . '/' . $contact->avatar)) {
                    unlink($upload_path . '/' . $contact->avatar);
                }

                $avatar->move($upload_path, $filename);
                $contact->avatar = $filename;
                $contact->save();

                return response()->json([
                    'success' => true,
                    'avatar_url' => $contact->avatar_url,
                ]);
            }

            return response()->json(['success' => false, 'msg' => 'No valid file uploaded.']);
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return response()->json(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function updateGenres($id, Request $request)
    {
        if (!auth()->user()->can('customer.update') && !auth()->user()->can('supplier.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $contact = Contact::where('business_id', $business_id)->findOrFail($id);

            $genres = $request->input('genres', []);
            if (is_string($genres)) {
                $genres = array_map('trim', explode(',', $genres));
            }
            $genres = array_filter($genres);

            $contact->favorite_genres = array_values($genres);
            $contact->save();

            return response()->json([
                'success' => true,
                'genres' => $contact->favorite_genres,
            ]);
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return response()->json(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function updateStoreCredit($id, Request $request)
    {
        if (!auth()->user()->can('customer.update') && !auth()->user()->can('supplier.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $contact = Contact::where('business_id', $business_id)->findOrFail($id);

            $amount = (float) $request->input('amount', 0);
            if ($amount <= 0) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Store credit amount must be greater than 0.'
                ]);
            }

            $newBalance = (float) $contact->balance + $amount;
            $contact->balance = $newBalance;

            // Audit trail — Sarah 2026-04-22: the green "Add Store Credit"
            // button previously wrote the new balance with no history, so
            // when a balance shows up later there's no way to tell which
            // cashier added it or why. Match the adjustStoreCredit() audit
            // format so the balance_notes column tells one coherent story.
            if (\Illuminate\Support\Facades\Schema::hasColumn('contacts', 'balance_notes')) {
                $stamp = now()->format('Y-m-d H:i');
                $who = auth()->user()->first_name ?? 'unknown';
                $reason = trim((string) $request->input('reason', ''));
                $line = sprintf(
                    '[%s] store-credit +$%s by %s → new balance $%s.%s',
                    $stamp, number_format($amount, 2),
                    $who, number_format($newBalance, 2),
                    $reason !== '' ? ' Reason: ' . $reason : ''
                );
                $contact->balance_notes = trim(($contact->balance_notes ?? '') . "\n" . $line);
            }
            $contact->save();

            return response()->json([
                'success' => true,
                'msg' => 'Store credit added successfully.',
                'new_balance' => (float) $contact->balance
            ]);
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return response()->json(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Signed adjustment to a customer's store-credit balance. Positive
     * numbers add, negative numbers subtract; a 'reason' is required so
     * there's always an audit trail when cashiers undo mistaken credits
     * (Clyde's 2026-04-21 ask: he applied credit by accident and had no
     * way to reverse it).
     *
     * Refuses adjustments that would drive the balance negative — if
     * you need to zero it out, query the current balance first.
     */
    public function adjustStoreCredit($id, Request $request)
    {
        if (!auth()->user()->can('customer.update') && !auth()->user()->can('supplier.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $contact = Contact::where('business_id', $business_id)->findOrFail($id);

            $delta = (float) $request->input('amount', 0);
            $reason = trim((string) $request->input('reason', ''));
            if (abs($delta) < 0.01) {
                return response()->json(['success' => false, 'msg' => 'Enter a non-zero amount.']);
            }
            if ($reason === '') {
                return response()->json(['success' => false, 'msg' => 'Reason is required for credit adjustments.']);
            }

            $currentBalance = (float) $contact->balance;
            $newBalance = round($currentBalance + $delta, 2);
            if ($newBalance < 0) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Adjustment would drive balance below \$0 (current: $' . number_format($currentBalance, 2) . ').'
                ]);
            }

            $contact->balance = $newBalance;

            // Audit trail: append to the contact's additional_information /
            // notes field so the history is never lost. One line per edit.
            $sign = $delta >= 0 ? '+' : '−';
            $stamp = now()->format('Y-m-d H:i');
            $who = auth()->user()->first_name ?? 'unknown';
            $line = sprintf(
                '[%s] store-credit %s$%s by %s → new balance $%s. Reason: %s',
                $stamp, $sign, number_format(abs($delta), 2),
                $who, number_format($newBalance, 2), $reason
            );
            $contact->balance_notes = trim(($contact->balance_notes ?? '') . "\n" . $line);
            $contact->save();

            return response()->json([
                'success' => true,
                'msg' => 'Store credit adjusted. New balance: $' . number_format($newBalance, 2),
                'new_balance' => $newBalance,
                'delta' => $delta,
            ]);
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return response()->json(['success' => false, 'msg' => $e->getMessage()]);
        }
    }
}
