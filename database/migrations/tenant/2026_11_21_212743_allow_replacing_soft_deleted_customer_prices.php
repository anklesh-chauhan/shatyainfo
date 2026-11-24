<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_prices', function (Blueprint $table): void {
            $table->smallInteger('active_price')->nullable()->storedAs('CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END');
            $table->unique(['customer_id', 'item_master_id', 'active_price'], 'customer_price_active_unique');
            $table->dropUnique('customer_price_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_prices', function (Blueprint $table): void {
            $table->unique(['customer_id', 'item_master_id'], 'customer_price_unique');
            $table->dropUnique('customer_price_active_unique');
            $table->dropColumn('active_price');
        });
    }
};
