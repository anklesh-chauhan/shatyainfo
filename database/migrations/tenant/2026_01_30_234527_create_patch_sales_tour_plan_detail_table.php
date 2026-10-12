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
        Schema::create('patch_sales_tour_plan_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_tour_plan_detail_id')
                ->constrained('sales_tour_plan_details') // Ensure table name is correct
                ->cascadeOnDelete();

            $table->foreignId('patch_id')
                ->constrained('patches')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patch_sales_tour_plan_detail');
    }
};
