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
        Schema::create('shift_late_mark_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_master_id')->constrained('shift_masters')->onDelete('cascade');
            $table->boolean('is_active')->default(false);
            $table->integer('late_in_grace_minutes')->nullable();
            $table->integer('early_out_grace_minutes')->nullable();
            $table->integer('total_late_in_early_out_mark_threshold_minutes_in_month')->nullable();
            $table->integer('total_late_in_early_out_mark_no_of_times_in_month')->nullable();
            $table->boolean('is_save_late_minutes_as_late_mark')->default(false);
            $table->boolean('is_calculate_on_weekly_off_and_paid_holiday')->default(false);
            $table->boolean('is_mark_abs_once_late_mark_grace_crossed_in_a_month')->default(false);
            $table->boolean('is_avoid_latemark_on_half_day_absent')->default(false);
            $table->boolean('is_avoid_latemark_on_full_day_absent')->default(false);
            $table->integer('conversion_rate_grace_late_mark_count')->nullable();
            $table->integer('conversion_rate_no_of_late_mark_count')->nullable();
            $table->integer('conversion_rate_no_of_day_absent')->nullable();
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
        Schema::dropIfExists('shift_late_mark_setups');
    }
};
