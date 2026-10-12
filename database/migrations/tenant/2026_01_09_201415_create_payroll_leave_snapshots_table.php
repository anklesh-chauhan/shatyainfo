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
        Schema::create('payroll_leave_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();

            $table->date('processed_till'); // payroll cut-off date

            $table->decimal('opening_balance', 5, 2);
            $table->decimal('closing_balance', 5, 2);

            $table->unsignedBigInteger('payroll_run_id')->nullable();

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->unique(
                ['employee_id', 'leave_type_id', 'processed_till'],
                'pls_emp_leave_proc_uq'
            );

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_leave_snapshots');
    }
};
