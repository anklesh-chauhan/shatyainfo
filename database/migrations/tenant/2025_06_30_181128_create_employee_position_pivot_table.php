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
        Schema::create('employee_position_pivot', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('position_id')->constrained('positions')->onDelete('cascade');
            $table->boolean('is_primary')->default(false);
            $table->primary(['employee_id', 'position_id']); // Composite primary key
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps(); // Add timestamps if you want to track when associations were made
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_position_pivot');
    }
};
