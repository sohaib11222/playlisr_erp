<?php

namespace App\Http\Controllers;

use App\Contact;
use App\BusinessLocation;
use App\Services\CloverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CloverController extends Controller
{
    /**
     * Test Clover API connection
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testConnection()
    {
        try {
            $cloverService = new CloverService();
            $result = $cloverService->testConnection();
            
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'msg' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Preview customers from Clover before import
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function previewCustomers(Request $request)
    {
        try {
            $limit = $request->get('limit', 50);
            $offset = $request->get('offset', 0);
            
            $cloverService = new CloverService();
            $result = $cloverService->getCustomers($limit, $offset);
            
            if ($result['success']) {
                // Format customers for preview
                $customers = array_map(function($customer) {
                    return [
                        'id' => $customer['id'] ?? '',
                        'name' => trim(($customer['firstName'] ?? '') . ' ' . ($customer['lastName'] ?? '')),
                        'email' => $customer['emailAddresses'][0]['emailAddress'] ?? '',
                        'phone' => $customer['phoneNumbers'][0]['phoneNumber'] ?? '',
                        'address' => $this->formatAddress($customer['addresses'][0] ?? []),
                        'created' => $customer['createdTime'] ?? '',
                    ];
                }, $result['customers']);
                
                return response()->json([
                    'success' => true,
                    'customers' => $customers,
                    'total' => $result['total'],
                    'has_more' => $result['has_more'] ?? false
                ]);
            }
            
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'msg' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Import customers from Clover
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function importCustomers(Request $request)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $location_id = $request->get('location_id');
            $clover_customer_ids = $request->get('customer_ids', []); // Specific customers to import, or empty for all
            
            $cloverService = new CloverService();
            
            $imported = 0;
            $skipped = 0;
            $errors = [];
            
            // If specific customer IDs provided, import only those
            if (!empty($clover_customer_ids)) {
                // First, fetch the customer data for selected IDs
                $allCustomers = [];
                $offset = 0;
                $limit = 100;
                $hasMore = true;
                
                // Fetch all customers to find the selected ones
                while ($hasMore) {
                    $fetchResult = $cloverService->getCustomers($limit, $offset);
                    if (!$fetchResult['success']) {
                        $errors[] = $fetchResult['msg'];
                        break;
                    }
                    
                    foreach ($fetchResult['customers'] as $customer) {
                        if (in_array($customer['id'], $clover_customer_ids)) {
                            $allCustomers[$customer['id']] = $customer;
                        }
                    }
                    
                    $hasMore = $fetchResult['has_more'] ?? false;
                    $offset += $limit;
                    
                    // Break if we found all requested customers
                    if (count($allCustomers) >= count($clover_customer_ids)) {
                        break;
                    }
                    
                    // Safety limit
                    if ($offset > 10000) {
                        break;
                    }
                }
                
                // Now import the selected customers
                foreach ($clover_customer_ids as $clover_customer_id) {
                    if (isset($allCustomers[$clover_customer_id])) {
                        $result = $this->importSingleCustomer($cloverService, $business_id, $location_id, $clover_customer_id, $allCustomers[$clover_customer_id]);
                        if ($result['success']) {
                            $imported++;
                        } elseif ($result['skipped']) {
                            $skipped++;
                        } else {
                            $errors[] = $result['msg'];
                        }
                    } else {
                        $errors[] = "Customer ID {$clover_customer_id} not found";
                    }
                }
            } else {
                // Import all customers (with pagination)
                $offset = 0;
                $limit = 100;
                $hasMore = true;
                
                while ($hasMore) {
                    $result = $cloverService->getCustomers($limit, $offset);
                    
                    if (!$result['success']) {
                        $errors[] = $result['msg'];
                        break;
                    }
                    
                    foreach ($result['customers'] as $clover_customer) {
                        $importResult = $this->importSingleCustomer($cloverService, $business_id, $location_id, $clover_customer['id'], $clover_customer);
                        if ($importResult['success']) {
                            $imported++;
                        } elseif ($importResult['skipped']) {
                            $skipped++;
                        } else {
                            $errors[] = $importResult['msg'];
                        }
                    }
                    
                    $hasMore = $result['has_more'] ?? false;
                    $offset += $limit;
                    
                    // Safety limit
                    if ($offset > 10000) {
                        break;
                    }
                }
            }
            
            return response()->json([
                'success' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'msg' => "Imported {$imported} customers, skipped {$skipped} duplicates"
            ]);
        } catch (\Exception $e) {
            Log::error('Clover Import Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'msg' => 'Import error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Import a single customer from Clover
     *
     * @param CloverService $cloverService
     * @param int $business_id
     * @param int $location_id
     * @param string $clover_customer_id
     * @param array|null $clover_customer_data Pre-fetched customer data (optional)
     * @return array
     */
    private function importSingleCustomer($cloverService, $business_id, $location_id, $clover_customer_id, $clover_customer_data = null)
    {
        try {
            // If customer data not provided, fetch it
            if ($clover_customer_data === null) {
                // Would need a getCustomer method in CloverService
                // For now, assume we have the data from getCustomers
                return [
                    'success' => false,
                    'msg' => 'Customer data required'
                ];
            }
            
            // Check if customer already exists (by email or phone)
            $email = $clover_customer_data['emailAddresses'][0]['emailAddress'] ?? null;
            $phone = $clover_customer_data['phoneNumbers'][0]['phoneNumber'] ?? null;
            
            $existing = Contact::where('business_id', $business_id)
                ->where(function($query) use ($email, $phone) {
                    if ($email) {
                        $query->where('email', $email);
                    }
                    if ($phone) {
                        $query->orWhere('mobile', $phone);
                    }
                })
                ->first();
            
            if ($existing) {
                // Update existing customer with Clover ID if not set
                if (empty($existing->clover_customer_id)) {
                    $existing->clover_customer_id = $clover_customer_id;
                    $existing->save();
                }
                return [
                    'success' => false,
                    'skipped' => true,
                    'msg' => 'Customer already exists'
                ];
            }
            
            // Create new customer
            $firstName = $clover_customer_data['firstName'] ?? '';
            $lastName = $clover_customer_data['lastName'] ?? '';
            $name = trim($firstName . ' ' . $lastName);
            if (empty($name)) {
                $name = $email ?? $phone ?? 'Clover Customer';
            }
            
            $address = $clover_customer_data['addresses'][0] ?? [];
            
            $contact = new Contact();
            $contact->business_id = $business_id;
            $contact->type = 'customer';
            $contact->name = $name;
            $contact->first_name = $firstName;
            $contact->last_name = $lastName;
            $contact->email = $email;
            $contact->mobile = $phone;
            $contact->address_line_1 = $address['address1'] ?? null;
            $contact->address_line_2 = $address['address2'] ?? null;
            $contact->city = $address['city'] ?? null;
            $contact->state = $address['state'] ?? null;
            $contact->zip_code = $address['zip'] ?? null;
            $contact->country = $address['country'] ?? null;
            $contact->clover_customer_id = $clover_customer_id;
            $contact->created_by = auth()->user()->id;
            
            // Set created_at from Clover if available
            if (!empty($clover_customer_data['createdTime'])) {
                try {
                    $contact->created_at = \Carbon\Carbon::createFromTimestampMs($clover_customer_data['createdTime']);
                } catch (\Exception $e) {
                    // Use current time if parsing fails
                }
            }
            
            $contact->save();
            
            return [
                'success' => true,
                'contact_id' => $contact->id
            ];
        } catch (\Exception $e) {
            Log::error('Import Single Customer Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Error importing customer: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Format Clover address to string
     *
     * @param array $address
     * @return string
     */
    private function formatAddress($address)
    {
        $parts = array_filter([
            $address['address1'] ?? '',
            $address['address2'] ?? '',
            $address['city'] ?? '',
            $address['state'] ?? '',
            $address['zip'] ?? '',
            $address['country'] ?? ''
        ]);

        return implode(', ', $parts);
    }

    /**
     * Shift summary — credit-card slip count + total for the given register
     * window. Auto-fills the 'Total card slips' field on the close-register
     * modal so cashiers don't have to hand-count swipes.
     *
     * Query params:
     *   location_id (required) — picks the per-location Clover creds
     *   start       (required) — shift start (ISO date or unix seconds)
     *   end         (required) — shift end   (same)
     *
     * Response: { success, card_slip_count, card_total, error? }
     */
    public function shiftSummary(Request $request)
    {
        $locationId = $request->get('location_id');
        $start = $request->get('start');
        $end   = $request->get('end');

        if (empty($locationId) || empty($start) || empty($end)) {
            return response()->json([
                'success' => false,
                'error' => 'location_id, start, and end are required.',
                'card_slip_count' => 0,
                'card_total' => 0,
            ], 422);
        }

        try {
            $cloverService = (new CloverService())->forLocation($locationId);
            $summary = $cloverService->getCardSlipCountForShift($start, $end);
            return response()->json($summary);
        } catch (\Exception $e) {
            Log::error('Clover shiftSummary error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage(),
                'card_slip_count' => 0,
                'card_total' => 0,
            ]);
        }
    }
}
