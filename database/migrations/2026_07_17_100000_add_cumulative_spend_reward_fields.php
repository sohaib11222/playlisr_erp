<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sarah 2026-07-17: switch the store-credit spend reward from per-sale to
 * CUMULATIVE. A customer now accrues qualifying pre-tax spend across visits
 * and earns one reward per full bracket ($5 per $100 by default).
 *
 *   contacts.spend_reward_brackets_paid  how many brackets already credited
 *                                        to this customer (guards re-grants)
 *   business.spend_reward_start_date     program cutoff — sales before this
 *                                        date don't accrue. Seeded to the
 *                                        program start (2026-07-05) so years of
 *                                        old history don't retro-pay; editable
 *                                        in Settings → Store Credit Rewards.
 *
 * Columns are hasColumn-guarded so a push-before-migrate can't crash inserts.
 */
class AddCumulativeSpendRewardFields extends Migration
{
    public function up()
    {
        if (Schema::hasTable('contacts') && !Schema::hasColumn('contacts', 'spend_reward_brackets_paid')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->integer('spend_reward_brackets_paid')->default(0);
            });
        }

        if (Schema::hasTable('business') && !Schema::hasColumn('business', 'spend_reward_start_date')) {
            Schema::table('business', function (Blueprint $table) {
                $table->date('spend_reward_start_date')->nullable();
            });

            // Seed the cutoff to the program start date for existing businesses
            // so cumulative accrual starts there, not from all-time history.
            DB::table('business')->whereNull('spend_reward_start_date')->update([
                'spend_reward_start_date' => '2026-07-05',
            ]);
        }
    }

    public function down()
    {
        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'spend_reward_brackets_paid')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('spend_reward_brackets_paid');
            });
        }
        if (Schema::hasTable('business') && Schema::hasColumn('business', 'spend_reward_start_date')) {
            Schema::table('business', function (Blueprint $table) {
                $table->dropColumn('spend_reward_start_date');
            });
        }
    }
}
