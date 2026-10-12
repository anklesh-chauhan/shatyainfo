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
        Schema::create('emp_provident_funds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->boolean('pf_flag')->default(false);
            $table->string('pf_no', 50)->nullable();
            $table->string('pf_new_version', 50)->nullable();
            $table->text('pf_remarks')->nullable();
            $table->date('pf_join_date')->nullable();
            $table->string('fpf_no', 50)->nullable();
            $table->decimal('vpf_percentage', 5, 2)->nullable();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->enum('pf_account_type', ['EPF', 'VPF'])->nullable()->comment('EPF: Employees Provident Fund, VPF: Voluntary Provident Fund');
            $table->string('pf_account_bank', 100)->nullable();
            $table->string('pf_account_number', 50)->nullable();
            $table->string('pf_account_ifsc', 50)->nullable();
            $table->string('pf_account_branch', 100)->nullable();
            $table->string('pf_account_remarks', 255)->nullable();
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
        Schema::dropIfExists('emp_provident_funds');
    }
};
