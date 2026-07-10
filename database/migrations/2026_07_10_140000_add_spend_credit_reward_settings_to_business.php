<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sarah 2026-07-10: makes the automatic store-credit spend reward
 * admin-editable (Settings → Store Credit Rewards) instead of hardcoded.
 *
 *   enable_spend_credit_reward  on/off toggle (default on = current behavior)
 *   spend_credit_reward_amount  $ credit granted per bracket (default 5)
 *   spend_credit_reward_per     $ of qualifying pre-tax spend per bracket (default 100)
 *
 * Read in SellPosController::grantSpendReward(). Defaults preserve the
 * original "$5 per $100" rule for every existing business row. Each column
 * is guarded with hasColumn so a push-before-migrate can't crash inserts.
 */
class AddSpendCreditRewardSettingsToBusiness extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('business')) {
            return;
        }

        Schema::table('business', function (Blueprint $table) {
            if (!Schema::hasColumn('business', 'enable_spend_credit_reward')) {
                $table->boolean('enable_spend_credit_reward')->default(1);
            }
            if (!Schema::hasColumn('business', 'spend_credit_reward_amount')) {
                $table->decimal('spend_credit_reward_amount', 22, 4)->default(5);
            }
            if (!Schema::hasColumn('business', 'spend_credit_reward_per')) {
                $table->decimal('spend_credit_reward_per', 22, 4)->default(100);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('business')) {
            return;
        }

        Schema::table('business', function (Blueprint $table) {
            foreach (['enable_spend_credit_reward', 'spend_credit_reward_amount', 'spend_credit_reward_per'] as $col) {
                if (Schema::hasColumn('business', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
