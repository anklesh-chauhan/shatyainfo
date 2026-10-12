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
        Schema::create('customer_prices', function (Blueprint $table) {
            $table->id();

            // Customer reference
            $table->foreignId('customer_id')
                ->constrained('account_masters')
                ->cascadeOnDelete();

            // Item reference (can be parent or variant)
            $table->foreignId('item_master_id')
                ->constrained('item_masters')
                ->cascadeOnDelete();

            // Price and discount
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('discount', 5, 2)->default(0);

            $table->blameable();
            $table->blameableSoftDeletes();

            $table->timestamps();

            // ✅ Ensure uniqueness — customer can have only one price per item (parent or variant)
            $table->unique(['customer_id', 'item_master_id'], 'customer_price_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_prices');
    }
};
