<?php

namespace App\Utils;

use App\Contact;
use App\Utils\TransactionUtil;
use App\Transaction;
use DB;

class ContactUtil extends Util
{

    /**
     * Returns Walk In Customer for a Business
     *
     * @param int $business_id
     *
     * @return array/false
     */
    public function getWalkInCustomer($business_id, $array = true)
    {
        $contact = Contact::whereIn('type', ['customer', 'both'])
                    ->where('contacts.business_id', $business_id)
                    ->where('contacts.is_default', 1)
                    ->leftjoin('customer_groups as cg', 'cg.id', '=', 'contacts.customer_group_id')
                    ->select('contacts.*', 
                        'cg.amount as discount_percent',
                        'cg.price_calculation_type',
                        'cg.selling_price_group_id'
                    )
                    ->first();

        if (!empty($contact)) {
            $contact->contact_address = $contact->contact_address;
            $output = $array ? $contact->toArray() : $contact;
            return $output;
        } else {
            return null;
        }
    }

    /**
     * Returns the customer group
     *
     * @param int $business_id
     * @param int $customer_id
     *
     * @return array
     */
    public function getCustomerGroup($business_id, $customer_id)
    {
        $cg = [];

        if (empty($customer_id)) {
            return $cg;
        }

        $contact = Contact::leftjoin('customer_groups as CG', 'contacts.customer_group_id', 'CG.id')
            ->where('contacts.id', $customer_id)
            ->where('contacts.business_id', $business_id)
            ->select('CG.*')
            ->first();

        return $contact;
    }

    /**
     * Returns the contact info
     *
     * @param int $business_id
     * @param int $contact_id
     *
     * @return array
     */
    public function getContactInfo($business_id, $contact_id)
    {
        $contact = Contact::where('contacts.id', $contact_id)
                    ->where('contacts.business_id', $business_id)
                    ->leftjoin('transactions AS t', 'contacts.id', '=', 't.contact_id')
                    ->with(['business'])
                    ->select(
                        DB::raw("SUM(IF(t.type = 'purchase', final_total, 0)) as total_purchase"),
                        DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', final_total, 0)) as total_invoice"),
                        DB::raw("SUM(IF(t.type = 'purchase', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_paid"),
                        DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as invoice_received"),
                        DB::raw("SUM(IF(t.type = 'opening_balance', final_total, 0)) as opening_balance"),
                        DB::raw("SUM(IF(t.type = 'opening_balance', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as opening_balance_paid"),
                        'contacts.*'
                    )->first();

        return $contact;
    }

    public function createNewContact($input)
    {
        //Check Contact id
        $count = 0;
        if (!empty($input['contact_id'])) {
            $count = Contact::where('business_id', $input['business_id'])
                            ->where('contact_id', $input['contact_id'])
                            ->count();
        }
        if ($count == 0) {
            //Update reference count
            $ref_count = $this->setAndGetReferenceCount('contacts', $input['business_id']);

            if (empty($input['contact_id'])) {
                //Generate reference number
                $input['contact_id'] = $this->generateReferenceNumber('contacts', $ref_count, $input['business_id']);
            }

            $opening_balance = isset($input['opening_balance']) ? $input['opening_balance'] : 0;
            if (isset($input['opening_balance'])) {
                unset($input['opening_balance']);
            }

            //Assigned the user
            $assigned_to_users = [];;
            if(!empty($input['assigned_to_users'])){
                $assigned_to_users = $input['assigned_to_users'];
                unset($input['assigned_to_users']);
            }
            
            $contact = Contact::create($input);

            //Assigned the user
            if(!empty($assigned_to_users)){
                $contact->userHavingAccess()->sync($assigned_to_users);
            }

            //Add opening balance
            if (!empty($opening_balance)) {
                $transactionUtil = new TransactionUtil();
                $transactionUtil->createOpeningBalanceTransaction($contact->business_id, $contact->id, $opening_balance, $contact->created_by, false);
            }

            $output = ['success' => true,
                        'data' => $contact,
                        'msg' => __("contact.added_success")
                    ];
            return $output;
        } else {
            throw new \Exception("Error Processing Request", 1);
        }
    }

    public function updateContact($input, $id, $business_id)
    {
        $count = 0;
        //Check Contact id
        if (!empty($input['contact_id'])) {
            $count = Contact::where('business_id', $business_id)
                    ->where('contact_id', $input['contact_id'])
                    ->where('id', '!=', $id)
                    ->count();
        }

        if ($count == 0) {
            //Get opening balance if exists
            $ob_transaction =  Transaction::where('contact_id', $id)
                                    ->where('type', 'opening_balance')
                                    ->first();

            $opening_balance = isset($input['opening_balance']) ? $input['opening_balance'] : 0;
            if (isset($input['opening_balance'])) {
                unset($input['opening_balance']);
            }

            //Assigned the user
            $assigned_to_users = [];;
            if(!empty($input['assigned_to_users'])){
                $assigned_to_users = $input['assigned_to_users'];
                unset($input['assigned_to_users']);
            }
            
            $contact = Contact::where('business_id', $business_id)->findOrFail($id);
            foreach ($input as $key => $value) {
                $contact->$key = $value;
            }
            $contact->save();


            //Assigned the user
            if(!empty($assigned_to_users)){
                $contact->userHavingAccess()->sync($assigned_to_users);
            }
            
            //Opening balance update
            $transactionUtil = new TransactionUtil();
            if (!empty($ob_transaction)) {
                $opening_balance_paid = $transactionUtil->getTotalAmountPaid($ob_transaction->id);
                if (!empty($opening_balance_paid)) {
                    $opening_balance += $opening_balance_paid;
                }
                
                $ob_transaction->final_total = $opening_balance;
                $ob_transaction->save();
                //Update opening balance payment status
                $transactionUtil->updatePaymentStatus($ob_transaction->id, $ob_transaction->final_total);
            } else {
                //Add opening balance
                if (!empty($opening_balance)) {
                    $transactionUtil->createOpeningBalanceTransaction($business_id, $contact->id, $opening_balance, $contact->created_by, false);
                }
            }

            $output = ['success' => true,
                        'msg' => __("contact.updated_success"),
                        'data' => $contact
                        ];
        } else {
            throw new \Exception("Error Processing Request", 1);
        }

        return $output;
    }
    
    /**
     * Sarah 2026-09-23: this used to leftjoin ALL of `transactions` (every
     * type, every row) to every contact and GROUP BY contacts.id to compute
     * these SUMs — for a business with a large sale history that's a huge
     * join before it can aggregate anything, and it's why the Customers page
     * took 5-14+ seconds to load or sort. Same fix as the preorder_counts/
     * shop_stats joins elsewhere in this app: pre-aggregate each stat to one
     * row per contact_id in its own subquery FIRST (so MySQL can use the
     * transactions(type,status,contact_id) index instead of scanning
     * everything), then LEFT JOIN those onto contacts. Every alias below is
     * unchanged from before — callers (havingRaw filters, display columns,
     * and Modules/Connector's API passthrough) all keep working as-is.
     */
    public function getContactQuery($business_id, $type, $contact_ids = [])
    {
        $businessId = (int) $business_id;

        $query = Contact::leftjoin('customer_groups AS cg', 'contacts.customer_group_id', '=', 'cg.id')
                    ->where('contacts.business_id', $business_id);

        if ($type == 'supplier') {
           $query->onlySuppliers();
        } elseif ($type == 'customer') {
            // Business decision: all employees/admins should see the same customer list.
            // Keep permission checks in controllers, but avoid "own customers only" scoping here.
            $query->whereIn('contacts.type', ['customer', 'both']);
        } else {
            if (auth()->check() && ( (!auth()->user()->can('customer.view') && auth()->user()->can('customer.view_own'))) || (!auth()->user()->can('supplier.view') && auth()->user()->can('supplier.view_own')) ) {
                $query->onlyOwnContact();
            }
        }
        if (!empty($contact_ids)) {
            $query->whereIn('contacts.id', $contact_ids);
        }

        // opening_balance + max_transaction_date always applied, regardless
        // of $type — matches the original unconditional select.
        $query->leftJoin(
            DB::raw("(SELECT contact_id,
                        SUM(final_total) as opening_balance,
                        SUM((SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id = transactions.id)) as opening_balance_paid
                      FROM transactions
                      WHERE business_id = {$businessId} AND type = 'opening_balance'
                      GROUP BY contact_id) as ob_stats"),
            'ob_stats.contact_id', '=', 'contacts.id'
        );
        $query->leftJoin(
            DB::raw("(SELECT contact_id, SUM(final_total) as total_ledger_discount
                      FROM transactions
                      WHERE business_id = {$businessId} AND type = 'ledger_discount'
                      GROUP BY contact_id) as ld_stats"),
            'ld_stats.contact_id', '=', 'contacts.id'
        );
        // Max date across every transaction type, same as the original
        // unfiltered MAX(DATE(transaction_date)) — not narrowed to sells,
        // to avoid changing has_no_sell_from's existing behavior.
        $query->leftJoin(
            DB::raw("(SELECT contact_id, MAX(DATE(transaction_date)) as max_transaction_date
                      FROM transactions
                      WHERE business_id = {$businessId}
                      GROUP BY contact_id) as mtd_stats"),
            'mtd_stats.contact_id', '=', 'contacts.id'
        );

        $query->select([
            'contacts.*',
            'cg.name as customer_group',
            DB::raw('COALESCE(ob_stats.opening_balance, 0) as opening_balance'),
            DB::raw('COALESCE(ob_stats.opening_balance_paid, 0) as opening_balance_paid'),
            'mtd_stats.max_transaction_date',
            DB::raw('COALESCE(ld_stats.total_ledger_discount, 0) as total_ledger_discount'),
            // Original exposed the same date twice under two names (a bare
            // unaggregated `t.transaction_date` alongside the MAX() one) —
            // `orHavingRaw('transaction_date IS NULL')` checks this alias
            // specifically, so keep it rather than rewrite every call site.
            'mtd_stats.max_transaction_date as transaction_date',
        ]);

        if (in_array($type, ['supplier', 'both'])) {
            $query->leftJoin(
                DB::raw("(SELECT contact_id,
                            SUM(IF(type = 'purchase', final_total, 0)) as total_purchase,
                            SUM(IF(type = 'purchase', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id = transactions.id), 0)) as purchase_paid,
                            SUM(IF(type = 'purchase_return', final_total, 0)) as total_purchase_return,
                            SUM(IF(type = 'purchase_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id = transactions.id), 0)) as purchase_return_paid
                          FROM transactions
                          WHERE business_id = {$businessId} AND type IN ('purchase', 'purchase_return')
                          GROUP BY contact_id) as purchase_stats"),
                'purchase_stats.contact_id', '=', 'contacts.id'
            );
            $query->addSelect([
                DB::raw('COALESCE(purchase_stats.total_purchase, 0) as total_purchase'),
                DB::raw('COALESCE(purchase_stats.purchase_paid, 0) as purchase_paid'),
                DB::raw('COALESCE(purchase_stats.total_purchase_return, 0) as total_purchase_return'),
                DB::raw('COALESCE(purchase_stats.purchase_return_paid, 0) as purchase_return_paid'),
            ]);
        }

        if (in_array($type, ['customer', 'both'])) {
            $query->leftJoin(
                DB::raw("(SELECT contact_id,
                            SUM(IF(type = 'sell' AND status = 'final', final_total, 0)) as total_invoice,
                            SUM(IF(type = 'sell' AND status = 'final', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id = transactions.id), 0)) as invoice_received,
                            SUM(IF(type = 'sell_return', final_total, 0)) as total_sell_return,
                            SUM(IF(type = 'sell_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id = transactions.id), 0)) as sell_return_paid
                          FROM transactions
                          WHERE business_id = {$businessId} AND type IN ('sell', 'sell_return')
                          GROUP BY contact_id) as sell_stats"),
                'sell_stats.contact_id', '=', 'contacts.id'
            );
            $query->addSelect([
                DB::raw('COALESCE(sell_stats.total_invoice, 0) as total_invoice'),
                DB::raw('COALESCE(sell_stats.invoice_received, 0) as invoice_received'),
                DB::raw('COALESCE(sell_stats.total_sell_return, 0) as total_sell_return'),
                DB::raw('COALESCE(sell_stats.sell_return_paid, 0) as sell_return_paid'),
            ]);
        }

        // No groupBy needed anymore — every join above is already
        // pre-aggregated to one row per contact_id (or is the simple 1:1
        // customer_groups lookup), so contacts.id no longer gets multiplied.

        return $query;
    }
}
