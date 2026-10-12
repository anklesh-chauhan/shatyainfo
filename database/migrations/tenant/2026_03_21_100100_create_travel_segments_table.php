<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_segments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_dcr_id')->constrained('sales_dcrs')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('sales_tour_plan_detail_id')->nullable()->constrained('sales_tour_plan_details')->nullOnDelete();
            $table->foreignId('patch_id')->nullable()->constrained('patches')->nullOnDelete();

            $table->foreignId('from_account_id')->nullable()->constrained('account_masters')->nullOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('account_masters')->nullOnDelete();
            $table->foreignId('from_area_town_id')->nullable()->constrained('city_pin_codes')->nullOnDelete();
            $table->foreignId('to_area_town_id')->nullable()->constrained('city_pin_codes')->nullOnDelete();
            $table->foreignId('transport_mode_id')->nullable()->constrained('transport_modes')->nullOnDelete();

            $table->decimal('distance_km', 10, 2)->default(0);
            $table->string('distance_source')->default('manual');
            $table->decimal('gps_distance_km', 10, 2)->nullable();
            $table->boolean('is_auto_generated')->default(true);

            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();

            $table->index('sales_dcr_id');
            $table->index('visit_id');
            $table->index('patch_id');
            $table->index(['from_area_town_id', 'to_area_town_id']);
            $table->index(['from_account_id', 'to_account_id']);
            $table->index('distance_source');
            $table->index(['sales_dcr_id', 'sales_tour_plan_detail_id', 'is_auto_generated'], 'travel_segments_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_segments');
    }
};
