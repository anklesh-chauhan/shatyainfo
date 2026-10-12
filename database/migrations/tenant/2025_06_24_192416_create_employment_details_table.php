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
        Schema::create('employment_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->string('ticket_no', 20)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('job_title_id')->nullable();
            $table->foreign('job_title_id')->references('id')->on('emp_job_titles')->onDelete('set null');
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->foreign('grade_id')->references('id')->on('emp_grades')->onDelete('set null');
            $table->unsignedBigInteger('division_id')->nullable();
            $table->foreignId('division_ou_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->unsignedBigInteger('organizational_unit_id')->nullable();
            $table->foreign('organizational_unit_id')->references('id')->on('organizational_units')->onDelete('set null');
            $table->date('hire_date')->nullable();
            $table->enum('employment_type', ['Permanent', 'Contract', 'Part-Time', 'Intern', 'Temporary', 'Consultant'])->nullable();
            $table->enum('employment_status', ['Active', 'Inactive', 'Terminated', 'Retired', 'On Leave'])->nullable();
            $table->date('resign_offer_date')->nullable();
            $table->date('last_working_date')->nullable();
            $table->date('probation_date')->nullable();
            $table->date('confirm_date')->nullable();
            $table->date('fnf_retiring_date')->nullable();
            $table->date('last_increment_date')->nullable();
            $table->unsignedBigInteger('work_location_id')->nullable();
            $table->unsignedBigInteger('reporting_manager_id')->nullable();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('department_id')->references('id')->on('emp_departments')->onDelete('set null');
            $table->foreign('work_location_id')->references('id')->on('location_masters')->onDelete('set null');
            $table->foreign('reporting_manager_id')->references('id')->on('employees')->onDelete('set null');
            $table->text('remarks')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index('division_ou_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_details');
    }
};
