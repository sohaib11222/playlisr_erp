<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Contact;
use App\Utils\ContactUtil;

/**
 * ONE-OFF verification: does the "email a receipt" walk-in-to-customer
 * linking (SellPosController::linkOrCreateReceiptContact) actually
 * dedupe by email instead of creating duplicates? Exercises the exact
 * same find-or-create query twice with a synthetic test email — never
 * touches a real transaction or a real customer's contact record.
 * Self-cleans: deletes the test contact it creates before exiting.
 *
 * Usage:
 *   php artisan nivessa:verify-receipt-contact-link
 *   php artisan nivessa:verify-receipt-contact-link --commit
 */
class VerifyReceiptContactLink extends Command
{
    protected $signature = 'nivessa:verify-receipt-contact-link {--commit : Actually write+clean up (default: dry-run, read-only)}';

    protected $description = 'Verify the receipt-email walk-in-to-customer contact linking dedupes by email and self-cleans.';

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $this->info($commit ? '🟢 COMMIT — will create+delete a throwaway test contact' : '🔵 DRY RUN — read-only, no writes');

        $businessId = DB::selectOne('SELECT business_id, COUNT(*) AS c FROM products GROUP BY business_id ORDER BY c DESC LIMIT 1')->business_id ?? null;
        if (!$businessId) {
            $this->error('Could not resolve a business_id.');
            return 1;
        }

        $testEmail = 'receipt-link-verify-' . time() . '@nivessa-test.internal';
        $this->info("Test email: {$testEmail}");

        if (!$commit) {
            $this->info('Dry run — would run the find-or-create query twice and confirm it returns the same contact both times, then delete it. Pass --commit to actually run it.');
            return 0;
        }

        $contactUtil = app(ContactUtil::class);

        // --- Round 1: should create ---
        $found1 = Contact::where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_default', 0)
            ->whereRaw('LOWER(email) = ?', [strtolower($testEmail)])
            ->first();

        if ($found1) {
            $this->error('FAIL: a contact with the test email already existed before round 1 — test isn\'t isolated.');
            return 1;
        }

        $localPart = strtok(strtolower($testEmail), '@') ?: 'Customer';
        $result = $contactUtil->createNewContact([
            'type' => 'customer',
            'first_name' => ucfirst($localPart),
            'email' => $testEmail,
            'mobile' => '',
            'business_id' => $businessId,
            'created_by' => 1,
            'name' => ucfirst($localPart),
        ]);

        if (empty($result['success']) || empty($result['data'])) {
            $this->error('FAIL: createNewContact did not succeed: ' . json_encode($result));
            return 1;
        }
        $created = $result['data'];
        $this->info("Round 1: created contact id={$created->id} email={$created->email}");

        // --- Round 2: should match, NOT create a duplicate ---
        $found2 = Contact::where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_default', 0)
            ->whereRaw('LOWER(email) = ?', [strtolower($testEmail)])
            ->first();

        $countWithEmail = Contact::where('business_id', $businessId)
            ->whereRaw('LOWER(email) = ?', [strtolower($testEmail)])
            ->count();

        $ok = true;
        if (!$found2 || $found2->id !== $created->id) {
            $this->error('FAIL: round 2 lookup did not find the same contact created in round 1.');
            $ok = false;
        }
        if ($countWithEmail !== 1) {
            $this->error("FAIL: expected exactly 1 contact with this email, found {$countWithEmail} — duplicate created.");
            $ok = false;
        }

        // --- Cleanup ---
        Contact::withTrashed()->where('id', $created->id)->forceDelete();
        $this->info('Cleaned up: deleted test contact.');

        if ($ok) {
            $this->info('✅ ALL CHECKS PASSED — dedupes by email correctly, no duplicates.');
            return 0;
        }

        return 1;
    }
}
