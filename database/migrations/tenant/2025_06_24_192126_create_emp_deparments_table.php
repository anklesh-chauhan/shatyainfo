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
        Schema::create('emp_departments', function (Blueprint $table) {
            $table->id();
            $table->string('department_name', 100)->unique()->comment('Name of the department');
            $table->string('department_code', 50)->unique()->comment('Unique code for the department');
            $table->text('description')->nullable()->comment('Description of the department');
            $table->unsignedBigInteger('organizational_unit_id')->nullable()->comment('Foreign key to organizational_units table');
            $table->foreign('organizational_unit_id')->references('id')->on('organizational_units')->onDelete('set null')->comment('Set to null if the organizational unit is deleted');
            $table->boolean('is_active')->default(true)->comment('Indicates if the department is active');
            $table->boolean('is_deleted')->default(false)->comment('Indicates if the department is deleted');
            $table->string('remark')->nullable()->comment('Additional remarks or notes about the department');
            $table->unsignedBigInteger('department_head_id')->nullable()->comment('Foreign key to the employees table for department head');
            $table->foreign('department_head_id')->references('id')->on('employees')->onDelete('set null')->comment('Set to null if the employee is deleted or no longer head of department');
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emp_departments');
    }
};
