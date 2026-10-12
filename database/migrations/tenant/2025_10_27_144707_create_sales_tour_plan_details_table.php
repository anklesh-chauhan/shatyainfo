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
        Schema::create('sales_tour_plan_details', function (Blueprint $table) {
            $table->id();
            $table->integer('order_index')->nullable(); // For ordering
            $table->foreignId('sales_tour_plan_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('territory_id')->nullable()->constrained()->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->json('joint_with')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_tour_plan_details');
    }
};
