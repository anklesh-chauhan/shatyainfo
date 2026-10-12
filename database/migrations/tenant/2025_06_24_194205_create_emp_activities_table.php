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
        Schema::create('emp_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('activity_type', 50); // e.g. 'job_title_change', 'grade_change', 'location_transfer'
            $table->json('old_values')->nullable(); // Store previous values as JSON
            $table->json('new_values')->nullable(); // Store new values as JSON
            $table->date('activity_date');
            $table->string('performed_by', 50)->nullable(); // User/admin who performed the action
            $table->text('remarks')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emp_activities');
    }
};
