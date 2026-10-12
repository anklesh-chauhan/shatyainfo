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
        Schema::create('emp_salaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->decimal('basic_salary', 12, 2)->nullable();
            $table->decimal('hra', 12, 2)->nullable();
            $table->decimal('conveyance', 12, 2)->nullable();
            $table->decimal('special_allowance', 12, 2)->nullable();
            $table->decimal('medical_allowance', 12, 2)->nullable();
            $table->decimal('other_allowances', 12, 2)->nullable();
            $table->decimal('gross_salary', 12, 2)->nullable();
            $table->decimal('pf_deduction', 12, 2)->nullable();
            $table->decimal('esic_deduction', 12, 2)->nullable();
            $table->decimal('professional_tax_deduction', 12, 2)->nullable();
            $table->decimal('net_salary', 12, 2)->nullable();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->string('salary_frequency', 20)->default('Monthly'); // e.g. 'Monthly', 'Weekly', 'Bi-Weekly'
            $table->string('salary_status', 20)->default('Active'); // e.g. 'Active', 'Inactive', 'Pending', 'Processed'
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
        Schema::dropIfExists('emp_salaries');
    }
};
