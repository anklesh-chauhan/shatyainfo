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
        Schema::create('emp_statutory_ids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->string('pan', 50)->nullable();
            $table->string('uan_no', 50)->nullable();
            $table->date('group_join_date')->nullable();
            $table->string('gratuity_code', 50)->nullable();
            $table->string('pran', 50)->nullable();
            $table->string('aadhar_number', 50)->nullable();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->string('tax_code', 50)->nullable();
            $table->string('tax_exemption', 50)->nullable();
            $table->string('tax_exemption_reason', 255)->nullable();
            $table->string('tax_exemption_validity', 50)->nullable();
            $table->string('tax_exemption_remarks', 255)->nullable();
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
        Schema::dropIfExists('emp_statutory_ids');
    }
};
