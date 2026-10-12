<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('item_master_id')->constrained('item_masters')->cascadeOnDelete();
            $table->decimal('quantity_ordered', 15, 3);
            $table->decimal('quantity_received', 15, 3)->default(0);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index(['purchase_order_id', 'item_master_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
