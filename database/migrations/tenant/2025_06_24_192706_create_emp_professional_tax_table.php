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
        Schema::create('emp_professional_taxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->boolean('pt_flag')->default(false);
            $table->string('pt_no', 50)->nullable();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->decimal('pt_amount', 10, 2)->nullable();
            $table->date('pt_join_date')->nullable();
            $table->string('pt_remarks', 255)->nullable();
            $table->string('pt_state', 50)->nullable();
            $table->string('pt_city', 50)->nullable();
            $table->string('pt_zone', 50)->nullable();
            $table->string('pt_code', 50)->nullable();
            $table->string('pt_jv_code', 50)->nullable();
            $table->string('pt_jv_code_cr', 50)->nullable();
            $table->string('pt_jv_code_dr', 50)->nullable();
            $table->string('pt_jv_code_remarks', 255)->nullable();
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
        Schema::dropIfExists('emp_professional_taxes');
    }
};
