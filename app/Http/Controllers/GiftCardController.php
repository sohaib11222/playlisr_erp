<?php

namespace App\Http\Controllers;

use App\GiftCard;
use App\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftCardController extends Controller
{
    /**
     * Display a listing of gift cards
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Allow all authenticated users to view gift cards (or add permission check later)
        // if (!auth()->user()->can('gift_card.view')) {
        //     abort(403, 'Unauthorized action.');
        // }

        $business_id = request()->session()->get('user.business_id');

        if (request()->ajax()) {
            $gift_cards = GiftCard::where('business_id', $business_id)
                ->with(['contact', 'creator'])
                ->select('gift_cards.*');

            return \DataTables::of($gift_cards)
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">
                        <button type="button" class="btn btn-info dropdown-toggle btn-xs" data-toggle="dropdown" aria-expanded="false">' .
                        __("messages.actions") .
                        '<span class="caret"></span><span class="sr-only">Toggle Dropdown</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-left" role="menu">';

                    // Show edit and delete for all users (or add permission check later)
                    $html .= '<li><a href="' . action('GiftCardController@edit', [$row->id]) . '" class="edit_gift_card_button"><i class="glyphicon glyphicon-edit"></i> ' . __("messages.edit") . '</a></li>';
                    $html .= '<li><a href="' . action('GiftCardController@destroy', [$row->id]) . '" class="delete_gift_card_button"><i class="glyphicon glyphicon-trash"></i> ' . __("messages.delete") . '</a></li>';

                    $html .= '</ul></div>';
                    return $html;
                })
                ->editColumn('contact', function ($row) {
                    return $row->contact ? $row->contact->name : 'N/A';
                })
                ->editColumn('balance', function ($row) {
                    return '<span class="display_currency" data-currency_symbol="true">' . $row->balance . '</span>';
                })
                ->editColumn('initial_value', function ($row) {
                    return '<span class="display_currency" data-currency_symbol="true">' . $row->initial_value . '</span>';
                })
                ->editColumn('status', function ($row) {
                    $statuses = [
                        'active' => '<span class="label label-success">Active</span>',
                        'expired' => '<span class="label label-warning">Expired</span>',
                        'used' => '<span class="label label-info">Used</span>',
                        'cancelled' => '<span class="label label-danger">Cancelled</span>',
                    ];
                    return $statuses[$row->status] ?? $row->status;
                })
                ->editColumn('expiry_date', function ($row) {
                    return $row->expiry_date ? date('Y-m-d', strtotime($row->expiry_date)) : 'N/A';
                })
                ->rawColumns(['action', 'balance', 'initial_value', 'status'])
                ->removeColumn('id')
                ->make(true);
        }

        return view('gift_card.index');
    }

    /**
     * Show the form for creating a new gift card
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // Allow all authenticated users to create gift cards (or add permission check later)
        // if (!auth()->user()->can('gift_card.create')) {
        //     abort(403, 'Unauthorized action.');
        // }

        $business_id = request()->session()->get('user.business_id');
        $customers = Contact::customersDropdown($business_id, false);

        return view('gift_card.create')->with(compact('customers'));
    }

    /**
     * Store a newly created gift card
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Allow all authenticated users to create gift cards (or add permission check later)
        // if (!auth()->user()->can('gift_card.create')) {
        //     abort(403, 'Unauthorized action.');
        // }

        try {
            $business_id = $request->session()->get('user.business_id');

            $request->validate([
                'card_number' => 'nullable|unique:gift_cards,card_number,NULL,id,business_id,' . $business_id,
                'initial_value' => 'required|numeric|min:0',
                'expiry_date' => 'nullable|date',
                'contact_id' => 'nullable|exists:contacts,id',
            ]);

            $gift_card = new GiftCard();
            $gift_card->business_id = $business_id;
            
            // Generate card number if not provided
            if (empty($request->card_number)) {
                $gift_card->card_number = GiftCard::generateCardNumber($business_id);
            } else {
                $gift_card->card_number = $request->card_number;
            }
            
            $gift_card->contact_id = $request->contact_id;
            $gift_card->initial_value = $request->initial_value;
            $gift_card->balance = $request->initial_value;
            $gift_card->expiry_date = $request->expiry_date;
            $gift_card->status = 'active';
            $gift_card->notes = $request->notes;
            $gift_card->created_by = auth()->user()->id;
            $gift_card->save();

            $output = [
                'success' => true,
                'msg' => 'Gift card created successfully'
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => 'Something went wrong: ' . $e->getMessage()
            ];
        }

        return redirect()->action('GiftCardController@index')->with('status', $output);
    }

    /**
     * Show the form for editing the specified gift card
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        // Allow all authenticated users to update gift cards (or add permission check later)
        // if (!auth()->user()->can('gift_card.update')) {
        //     abort(403, 'Unauthorized action.');
        // }

        $business_id = request()->session()->get('user.business_id');
        $gift_card = GiftCard::where('business_id', $business_id)->findOrFail($id);
        $customers = Contact::customersDropdown($business_id, false);

        return view('gift_card.edit')->with(compact('gift_card', 'customers'));
    }

    /**
     * Update the specified gift card
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // Allow all authenticated users to update gift cards (or add permission check later)
        // if (!auth()->user()->can('gift_card.update')) {
        //     abort(403, 'Unauthorized action.');
        // }

        try {
            $business_id = $request->session()->get('user.business_id');
            $gift_card = GiftCard::where('business_id', $business_id)->findOrFail($id);

            $request->validate([
                'card_number' => 'required|unique:gift_cards,card_number,' . $id . ',id,business_id,' . $business_id,
                'expiry_date' => 'nullable|date',
                'contact_id' => 'nullable|exists:contacts,id',
                'status' => 'required|in:active,expired,used,cancelled',
            ]);

            $gift_card->card_number = $request->card_number;
            $gift_card->contact_id = $request->contact_id;
            $gift_card->expiry_date = $request->expiry_date;
            $gift_card->status = $request->status;
            $gift_card->notes = $request->notes;

            // If balance is being updated
            if ($request->has('balance')) {
                $gift_card->balance = $request->balance;
            }

            $gift_card->save();

            $output = [
                'success' => true,
                'msg' => 'Gift card updated successfully'
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => 'Something went wrong: ' . $e->getMessage()
            ];
        }

        return redirect()->action('GiftCardController@index')->with('status', $output);
    }

    /**
     * Remove the specified gift card
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        // Allow all authenticated users to delete gift cards (or add permission check later)
        // if (!auth()->user()->can('gift_card.delete')) {
        //     abort(403, 'Unauthorized action.');
        // }

        try {
            $business_id = request()->session()->get('user.business_id');
            $gift_card = GiftCard::where('business_id', $business_id)->findOrFail($id);
            $gift_card->delete();

            $output = [
                'success' => true,
                'msg' => 'Gift card deleted successfully'
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => 'Something went wrong: ' . $e->getMessage()
            ];
        }

        return $output;
    }
}

