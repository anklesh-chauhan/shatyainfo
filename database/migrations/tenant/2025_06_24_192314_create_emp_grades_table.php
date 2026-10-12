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
        Schema::create('emp_grades', function (Blueprint $table) {
            $table->id();
            $table->string('grade_name', 50)->unique(); // Name of the grade
            $table->string('description', 255)->nullable(); // Description of the grade
            $table->unsignedBigInteger('department_id')->nullable(); // Foreign key to departments table
            $table->foreign('department_id')->references('id')->on('emp_departments')->onDelete('set null');
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
        Schema::dropIfExists('emp_grades');
    }
};
