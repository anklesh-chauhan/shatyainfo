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
        Schema::create('emp_esic_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->boolean('esic_flag')->default(false);
            $table->string('esic_old_no', 50)->nullable();
            $table->string('esic_new_version', 50)->nullable();
            $table->string('esic_imp_code', 50)->nullable();
            $table->string('esic_imp_name', 50)->nullable();
            $table->text('esic_remarks')->nullable();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->string('esic_account_bank', 100)->nullable();
            $table->string('esic_account_number', 50)->nullable();
            $table->string('esic_account_ifsc', 50)->nullable();
            $table->string('esic_account_branch', 100)->nullable();
            $table->string('esic_account_remarks', 255)->nullable();
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
        Schema::dropIfExists('emp_esic_details');
    }
};
