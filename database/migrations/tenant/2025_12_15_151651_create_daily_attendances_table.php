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
        Schema::create('daily_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_master_id')->constrained('shift_masters')->cascadeOnDelete();

            $table->date('attendance_date');

            /* ================= NORMALIZED PUNCH SUMMARY ================= */
            $table->time('first_punch_in')->nullable();
            $table->time('last_punch_out')->nullable();

            /* ================= DERIVED METRICS ================= */
            $table->integer('actual_working_minutes')->default(0);
            $table->integer('late_in_minutes')->default(0);
            $table->integer('early_out_minutes')->default(0);
            $table->integer('early_in_minutes')->default(0);
            $table->integer('late_out_minutes')->default(0);

            /* ================= POLICY RESULTS ================= */
            $table->integer('final_working_minutes')->default(0);
            $table->integer('late_mark_count')->default(0);

            $table->boolean('is_half_day')->default(false);
            $table->boolean('is_absent')->default(false);

            $table->integer('ot_minutes')->default(0);
            $table->integer('comp_off_days')->default(0);

            /* ================= DAY TYPE FLAGS ================= */
            $table->boolean('is_weekly_off')->default(false);
            $table->boolean('is_paid_holiday')->default(false);

            $table->foreignId('status_id')
                ->constrained('employee_attendance_statuses')
                ->onDelete('cascade')
                ->nullable();

            $table->unique(['employee_id', 'attendance_date']);

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
        Schema::dropIfExists('daily_attendances');
    }
};
