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
        Schema::create('shift_masters', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();              // GEN, NIGHT, FLEX
            $table->string('name');                        // General Shift

            $table->time('shift_change_time')->nullable();

            // Shift characteristics enumerations
            $table->enum('shift_type', ['fixed', 'rotational'])->default('fixed');
            $table->enum('week_off_type', ['fixed', 'rotational', 'none'])->default('none');

            // Shift timings
            $table->time('start_time');
            $table->time('end_time');
            $table->time('first_half_start_at')->nullable();
            $table->time('first_half_end_at')->nullable();
            $table->time('second_half_start_at')->nullable();
            $table->time('second_half_end_at')->nullable();
            $table->integer('shift_duration_hours')->nullable();

            $table->integer('overtime_start_minutes')->default(0);

            $table->boolean('is_first_in_last_out_punch')->default(false);
            $table->boolean('is_night_shift')->default(false);
            $table->boolean('is_flexible')->default(false);
            $table->boolean('is_system')->default(false);

            $table->boolean('is_lunch_time_flexible')->default(false);
            $table->integer('lunch_break_minutes')->nullable();
            $table->time('lunch_start_time')->nullable();
            $table->time('lunch_end_time')->nullable();

            $table->boolean('is_dinner_time_flexible')->default(false);
            $table->integer('dinner_break_minutes')->nullable();
            $table->time('dinner_start_time')->nullable();
            $table->time('dinner_end_time')->nullable();

            $table->boolean('is_break_time_flexible')->default(false);
            $table->integer('break_minutes')->nullable();
            $table->time('break_start_time')->nullable();
            $table->time('break_end_time')->nullable();

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
        Schema::dropIfExists('shift_masters');
    }
};
