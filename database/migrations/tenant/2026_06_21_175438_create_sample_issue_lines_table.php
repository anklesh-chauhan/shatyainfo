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
        Schema::create('sample_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sample_request_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_master_id')->constrained('item_masters');
            $table->unsignedBigInteger('inventory_batch_id')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->text('remarks')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index(['sample_issue_id', 'item_master_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sample_issue_lines');
    }
};
