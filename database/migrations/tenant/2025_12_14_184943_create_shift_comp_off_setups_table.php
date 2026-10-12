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
        Schema::create('shift_comp_off_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_master_id')->constrained('shift_masters')->onDelete('cascade');
            $table->boolean('is_active')->default(false);
            // is Weekly Off and Paid Holiday
            $table->boolean('is_weekly_off_paid_holiday_as_comp_off')->default(false);
            // Minimum Comp Off Hours Required
            $table->integer('minimum_comp_off_hours_required_per_day')->nullable();
            // conversion daily ot to comp off
            $table->integer('conversion_daily_ot_hours')->nullable();
            $table->integer('conversion_co_plus_credit_days')->nullable();

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
        Schema::dropIfExists('shift_comp_off_setups');
    }
};
