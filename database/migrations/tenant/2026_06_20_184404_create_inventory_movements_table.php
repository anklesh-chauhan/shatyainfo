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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_master_id')->constrained('item_masters')->cascadeOnDelete();
            $table->foreignId('location_master_id')->constrained('location_masters')->cascadeOnDelete();
            $table->nullableMorphs('reference');
            $table->string('movement_type');
            $table->decimal('quantity_in', 15, 3)->default(0);
            $table->decimal('quantity_out', 15, 3)->default(0);
            $table->decimal('balance_after', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_value', 15, 4)->nullable();
            $table->timestamp('movement_at');
            $table->text('remarks')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            // $table->index(['item_master_id', 'location_master_id', 'movement_at']);
            $table->index(['movement_type', 'movement_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
