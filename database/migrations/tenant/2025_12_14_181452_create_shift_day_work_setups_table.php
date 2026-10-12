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
        Schema::create('shift_day_work_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_master_id')->constrained('shift_masters')->onDelete('cascade');
            $table->boolean('is_active')->default(false);

            $table->integer('first_half_late_in_cutoff_minutes')->nullable();
            $table->integer('first_half_early_out_cutoff_minutes')->nullable();
            $table->integer('second_half_late_in_cutoff_minutes')->nullable();
            $table->integer('second_half_early_out_cutoff_minutes')->nullable();

            $table->boolean('add_early_in_minutes')->default(false);
            $table->boolean('add_late_out_minutes')->default(false);

            $table->integer('daily_early_in_limit_minutes')->nullable();
            $table->integer('daily_late_out_limit_minutes')->nullable();

            $table->integer('monthly_early_in_grace_no_of_times')->nullable();
            $table->integer('monthly_late_out_grace_no_of_times')->nullable();
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
        Schema::dropIfExists('shift_day_work_setups');
    }
};
