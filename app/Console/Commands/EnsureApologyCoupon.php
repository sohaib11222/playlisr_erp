<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Coupon;
use App\Business;
use App\User;

/**
 * ONE-OFF, idempotent: create the "WEMESSEDUP" apology coupon the
 * cancellation email promises (15% off, no expiry, no usage cap — it's
 * handed out indefinitely whenever an order is cancelled, not a one-time
 * promo). Does nothing if a coupon with that code already exists for the
 * business — safe to re-run.
 *
 * Dry-run by default. Usage:
 *   php artisan nivessa:ensure-apology-coupon
 *   php artisan nivessa:ensure-apology-coupon --commit
 */
class EnsureApologyCoupon extends Command
{
    protected $signature = 'nivessa:ensure-apology-coupon {--commit : Actually write (default: dry-run)}';

    protected $description = 'Idempotently create the WEMESSEDUP 15%-off apology coupon used in cancellation emails.';

    const CODE = 'WEMESSEDUP';
    const PERCENT_OFF = 15;

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $this->info($commit ? '🟢 COMMIT — writing changes' : '🔵 DRY RUN — no writes');

        // Business the /products screen shows on this single-tenant install
        // (same "most products" heuristic products-export.yml uses — Business
        // has no products() relation to withCount(), so raw SQL like that
        // workflow already does).
        $businessId = DB::selectOne('SELECT business_id, COUNT(*) AS c FROM products GROUP BY business_id ORDER BY c DESC LIMIT 1')->business_id ?? null;
        $business = $businessId ? Business::find($businessId) : Business::first();
        if (!$business) {
            $this->error('No business found.');
            return 1;
        }
        $this->line("Business: #{$business->id} {$business->name}");

        $existing = Coupon::where('business_id', $business->id)->where('code', self::CODE)->first();
        if ($existing) {
            $this->info("Coupon \"" . self::CODE . "\" already exists (id {$existing->id}, status {$existing->status}, {$existing->times_used} used). Nothing to do.");
            return 0;
        }

        $admin = User::where('business_id', $business->id)
            ->whereHas('roles', function ($q) use ($business) {
                $q->where('name', 'Admin#' . $business->id);
            })
            ->first();
        if (!$admin) {
            $this->error('No admin user found to attribute as coupon creator.');
            return 1;
        }
        $this->line("Attributed to: {$admin->first_name} {$admin->last_name} (user #{$admin->id})");

        $this->line('Would create: code=' . self::CODE . ' type=percent value=' . self::PERCENT_OFF . ' usage_limit=NULL (unlimited) expiry_date=NULL status=active');

        if (!$commit) {
            $this->info('Re-run with --commit to create it.');
            return 0;
        }

        $coupon = new Coupon();
        $coupon->business_id = $business->id;
        $coupon->code = self::CODE;
        $coupon->type = 'percent';
        $coupon->value = self::PERCENT_OFF;
        $coupon->min_order_amount = null;
        $coupon->usage_limit = null; // unlimited — handed out on every cancellation, not a one-shot promo
        $coupon->expiry_date = null;
        $coupon->status = 'active';
        $coupon->notes = 'Apology code sent automatically in the order-cancellation email (server/utils/emailTemplates.js — orderCancelledApologyEmail). Created by nivessa:ensure-apology-coupon.';
        $coupon->created_by = $admin->id;
        $coupon->save();

        $this->info("✅ Created coupon #{$coupon->id}.");
        return 0;
    }
}
