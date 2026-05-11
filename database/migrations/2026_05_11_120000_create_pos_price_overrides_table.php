<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

// Audit log for the new "cashier edits price inline at POS" flow.
// Sarah enabled inline price edit for cashiers (no manager floor staff to
// approve at the register); this table captures every override so she can
// scan them after the fact at /admin/pos-overrides.
class CreatePosPriceOverridesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('pos_price_overrides')) {
            Schema::create('pos_price_overrides', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('business_location_id')->nullable()->index();
                $table->unsignedInteger('transaction_id')->index();
                $table->unsignedBigInteger('transaction_sell_line_id')->nullable();
                $table->unsignedInteger('product_id')->nullable()->index();
                $table->unsignedInteger('variation_id')->nullable();
                $table->string('product_name', 191)->nullable();
                $table->string('artist', 191)->nullable();
                $table->decimal('system_price', 22, 4)->default(0);
                $table->decimal('sold_price', 22, 4)->default(0);
                // Signed: positive = cashier charged MORE than sticker,
                // negative = cashier charged LESS than sticker.
                $table->decimal('diff', 22, 4)->default(0);
                $table->unsignedInteger('user_id')->nullable()->index();
                $table->timestamps();
                $table->index(['business_id', 'created_at']);
            });
        }

        // Grant the existing "edit price at POS" permission to every role so
        // cashiers can change the line price inline. Logging in the controller
        // captures every override.
        $perm = Permission::where('name', 'edit_product_price_from_pos_screen')
            ->where('guard_name', 'web')
            ->first();
        if (!$perm) {
            $perm = Permission::create([
                'name' => 'edit_product_price_from_pos_screen',
                'guard_name' => 'web',
            ]);
        }
        foreach (Role::all() as $role) {
            $role->givePermissionTo($perm);
        }
    }

    public function down()
    {
        Schema::dropIfExists('pos_price_overrides');
    }
}
