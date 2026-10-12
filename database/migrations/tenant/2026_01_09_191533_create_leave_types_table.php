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
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // CL, SL, PL
            $table->string('name');
            $table->boolean('is_paid')->default(false);
            $table->boolean('affects_payroll')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('employee_attendance_status_id')
                ->constrained('employee_attendance_statuses')
                ->nullable();
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
        Schema::dropIfExists('leave_types');
    }
};
