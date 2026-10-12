<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('packing_forwarding', 15, 2)->nullable()->default(0);
            $table->decimal('insurance_charges', 15, 2)->nullable()->default(0);
            $table->decimal('other_charges', 15, 2)->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('packing_forwarding');
            $table->dropColumn('insurance_charges');
            $table->dropColumn('other_charges');
        });
    }
};
