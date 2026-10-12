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
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();

            // Core
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('leave_type_id')->constrained();

            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('total_days', 4, 2)->default(0);

            // Half-day support
            $table->boolean('is_half_day')->default(false);
            $table->string('half_day_type')->nullable(); // first_half / second_half

            // Substitute
            $table->foreignId('substitute_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Workflow
            $table->string('approval_status')->default('draft');
            $table->text('reason')->nullable();

            // Payroll lock
            $table->boolean('payroll_locked')->default(false);
            $table->date('payroll_lock_till')->nullable();

            // Audit
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            // Indexes
            $table->index(['employee_id', 'approval_status']);
            $table->index(['from_date', 'to_date']);
            $table->index('payroll_locked');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
    }
};
