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
        Schema::create('shift_general_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_master_id')->constrained('shift_masters')->onDelete('cascade');
            $table->boolean('reduce_lunch_break_minutes_from_working_hours')->default(false);
            $table->boolean('reduce_dinner_break_minutes_from_working_hours')->default(false);
            $table->boolean('reduce_break_minutes_from_working_hours')->default(false);
            $table->boolean('auto_convert_wop_to_co_plus')->default(false);
            $table->boolean('auto_convert_php_to_co_plus')->default(false);
            $table->boolean('calculate_compensation')->default(false);
            $table->boolean('is_allow_auto_shift')->default(false);
            $table->boolean('is_allow_half_day_leave')->default(false);
            $table->boolean('is_allow_shift_change_request')->default(false);
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
        Schema::dropIfExists('shift_general_setups');
    }
};
