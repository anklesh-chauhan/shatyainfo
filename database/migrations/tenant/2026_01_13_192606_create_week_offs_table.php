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
        Schema::create('week_offs', function (Blueprint $table) {
            $table->id();

            // Scope
            $table->foreignId('employee_id')->nullable()->constrained();
            $table->foreignId('emp_department_id')->nullable()->constrained();
            $table->foreignId('shift_master_id')->nullable()->constrained();

            // Day of week: 0 (Sunday) → 6 (Saturday)
            $table->unsignedTinyInteger('day_of_week');

            $table->boolean('is_active')->default(true);

            $table->index(['employee_id', 'emp_department_id', 'shift_master_id', 'day_of_week'],
                'idx_week_offs_emp_dept_shift_day');

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
        Schema::dropIfExists('week_offs');
    }
};
