<?php

namespace App\Http\Controllers;

use App\Contact;
use App\Transaction;
use App\User;
use Illuminate\Support\Facades\Schema;

/**
 * "Delete" on the customer list — handles a customer asking us to delete
 * their account (Sarah 2026-09-27; the website admin has no delete and the
 * old trash icon refused anyone with a sale).
 *
 * preview(): what will happen — the matching website account(s) and whether
 *   the ERP contact gets deleted outright or scrubbed.
 * destroy(): does it. Website accounts are deleted through the bridge
 *   (/api/v1/erp/customers/:id/delete, snapshot kept in the website audit
 *   log). The ERP contact is deleted if it has no sales; otherwise its
 *   personal details are cleared and the name set to "Deleted Customer" so
 *   sales history and reports stay intact. The old values are kept in the
 *   activity log either way.
 *
 * Extends WebsiteOrdersController only to reuse its bridge helpers
 * (websiteApi / key resolution).
 */
class CustomerAccountDeleteController extends WebsiteOrdersController
{
    // Personal fields cleared when the contact has sales and can't be deleted.
    const SCRUB_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'prefix', 'email', 'mobile',
        'alternate_number', 'landline', 'address_line_1', 'address_line_2',
        'city', 'state', 'country', 'zip_code', 'landmark', 'shipping_address',
        'dob', 'tax_number', 'supplier_business_name', 'custom_field1',
        'custom_field2', 'custom_field3', 'custom_field4', 'favorite_genres',
        'clover_customer_id',
    ];

    /** Only Sarah and Jon can delete customers (Sarah 2026-09-27). */
    public static function canDelete()
    {
        $u = auth()->user();
        return $u
            && strtolower(trim((string) $u->last_name)) === 'hedvat'
            && in_array(strtolower(trim((string) $u->first_name)), ['jonathan', 'sarah'], true);
    }

    protected function findContact($id)
    {
        if (!self::canDelete()) {
            abort(403, 'Only Sarah and Jon can delete customers.');
        }
        $business_id = request()->user()->business_id;
        return Contact::where('business_id', $business_id)->findOrFail($id);
    }

    protected function websiteAccounts(Contact $contact): array
    {
        $resp = $this->websiteApi('POST', '/erp/customers/lookup', [
            'email' => (string) $contact->email,
            'phone' => (string) $contact->mobile,
        ]);
        if (empty($resp['success'])) {
            return ['error' => $resp['message'] ?? 'Could not reach the website.', 'data' => []];
        }
        return ['error' => null, 'data' => $resp['data'] ?? []];
    }

    protected function saleCount(Contact $contact): int
    {
        return Transaction::where('business_id', $contact->business_id)
            ->where('contact_id', $contact->id)
            ->count();
    }

    public function preview($id)
    {
        $contact = $this->findContact($id);
        if ($contact->is_default) {
            return ['success' => false, 'msg' => 'The Walk-In Customer can\'t be deleted.'];
        }
        $web = $this->websiteAccounts($contact);
        return [
            'success'   => true,
            'contact'   => ['id' => $contact->id, 'contact_id' => $contact->contact_id, 'name' => $contact->name],
            'sales'     => $this->saleCount($contact),
            'website'   => $web['data'],
            'web_error' => $web['error'],
        ];
    }

    public function destroy($id)
    {
        $contact = $this->findContact($id);
        if ($contact->is_default) {
            return ['success' => false, 'msg' => 'The Walk-In Customer can\'t be deleted.'];
        }

        // Only delete the website accounts staff saw in the preview.
        $confirmed = array_filter((array) request()->input('website_ids', []), function ($v) {
            return preg_match('/^[a-f0-9]{24}$/', (string) $v);
        });
        $by = trim(auth()->user()->first_name . ' ' . auth()->user()->last_name);
        $done = [];
        foreach ($confirmed as $webId) {
            $resp = $this->websiteApi('POST', '/erp/customers/' . $webId . '/delete', ['by' => $by]);
            if (empty($resp['success'])) {
                return ['success' => false, 'msg' => 'Website account not deleted: ' . ($resp['message'] ?? 'unknown error') . ' Nothing in the ERP was changed.'];
            }
            $done[] = 'website account deleted';
        }

        $snapshot = $contact->toArray();
        if ($this->saleCount($contact) === 0) {
            $this->contactUtil()->activityLog($contact, 'contact_deleted', null, ['reason' => 'Customer requested account deletion', 'snapshot' => $snapshot]);
            User::where('crm_contact_id', $contact->id)->update(['allow_login' => 0]);
            $contact->delete();
            $done[] = 'ERP contact deleted';
        } else {
            foreach (self::SCRUB_FIELDS as $f) {
                if (Schema::hasColumn('contacts', $f)) {
                    $contact->{$f} = $f === 'dob' ? null : '';
                }
            }
            $contact->name = 'Deleted Customer';
            if (Schema::hasColumn('contacts', 'opt_in_marketing')) {
                $contact->opt_in_marketing = 0;
            }
            $contact->save();
            $this->contactUtil()->activityLog($contact, 'edited', null, ['reason' => 'Customer requested account deletion - personal details cleared, sales kept', 'snapshot' => $snapshot]);
            User::where('crm_contact_id', $contact->id)->update(['allow_login' => 0]);
            $done[] = 'ERP contact cleared (sales history kept)';
        }

        return ['success' => true, 'msg' => ucfirst(implode(', ', $done)) . '.'];
    }

    protected function contactUtil()
    {
        return app(\App\Utils\ContactUtil::class);
    }
}
