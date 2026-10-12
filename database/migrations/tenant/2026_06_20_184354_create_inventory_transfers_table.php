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
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->unique();
            $table->foreignId('item_master_id')->constrained('item_masters')->cascadeOnDelete();
            $table->foreignId('from_location_master_id')->constrained('location_masters')->cascadeOnDelete();
            $table->foreignId('to_location_master_id')->constrained('location_masters')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('posted');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            // $table->index(['item_master_id', 'from_location_master_id', 'to_location_master_id']);
            $table->index(['status', 'posted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
