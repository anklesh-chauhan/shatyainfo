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
        Schema::create('shift_over_time_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_master_id')->constrained('shift_masters')->onDelete('cascade');
            $table->boolean('is_active')->default(false);
            // is Weekly Off and Paid Holiday
            $table->boolean('is_weekly_off_paid_holiday_as_ot')->default(false);
            // Minimum OT Hours Required
            $table->integer('minimum_ot_hours_required_per_day')->nullable();
            // OT Calculation Basis
            $table->enum('ot_calculation_basis', ['fixed_hours', 'actual_hours'])->default('fixed_hours')->nullable();
            // OT Rounding Method
            $table->enum('ot_rounding_method', ['none', 'based_on_slab', 'up_to_nearest_15_minutes', 'up_to_nearest_30_minutes', 'up_to_nearest_hour'])->default('none')->nullable();
            // Maximum OT Hours Per Day
            $table->integer('maximum_ot_hours_allowed_per_day')->nullable();
            // Maximum OT Hours Per Month
            $table->integer('maximum_ot_hours_per_month')->nullable();
            // Consider Working Hours as OT if Less Than Half Day Hours
            $table->boolean('consider_working_hours_as_ot_if_less_than_half_day_hours')->default(false);
            // Monthly total OT Round Off Method
            $table->enum('monthly_total_ot_round_off_method', ['none', 'based_on_slab', 'up_to_nearest_15_minutes', 'up_to_nearest_30_minutes', 'up_to_nearest_hour'])->default('none')->nullable();
            // is approval required
            $table->boolean('is_approval_required')->default(false);

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
        Schema::dropIfExists('shift_over_time_setups');
    }
};
