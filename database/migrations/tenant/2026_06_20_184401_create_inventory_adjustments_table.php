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
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number')->unique();
            $table->foreignId('item_master_id')->constrained('item_masters')->cascadeOnDelete();
            $table->foreignId('location_master_id')->constrained('location_masters')->cascadeOnDelete();
            $table->string('adjustment_type');
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('reason');
            $table->text('remarks')->nullable();
            $table->string('status')->default('posted');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index(['item_master_id', 'location_master_id']);
            $table->index(['status', 'posted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
