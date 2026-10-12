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
        Schema::create('packing_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();
        });

        Schema::create('item_masters', function (Blueprint $table) {
            $table->id();
            $table->string('item_code')->unique();
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->nullableMorphs('category');
            $table->foreignId('item_brand_id')->nullable()->constrained('item_brands');
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2)->nullable();
            $table->string('hsn_code')->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->integer('discount')->nullable();
            $table->integer('opening_stock')->nullable()->default(0);
            $table->integer('minimum_stock_level')->nullable();
            $table->integer('reorder_quantity')->nullable();
            $table->foreignId('unit_of_measurement_id')->nullable()->constrained('unit_of_measurements');
            $table->integer('lead_time')->nullable();
            $table->string('storage_location')->nullable();
            $table->string('barcode')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('packaging_type_id')->nullable()->constrained('packing_types');
            $table->integer('per_packing_qty')->nullable();
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();
        });

        // Pivot Table for Multiple Address Details
        Schema::create('item_master_account_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_master_id')->constrained('account_masters')->onDelete('cascade');
            $table->foreignId('item_master_id')->constrained('item_masters')->onDelete('cascade');
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_master_account_masters');
        Schema::dropIfExists('item_masters');
        Schema::dropIfExists('packing_types');
    }
};
