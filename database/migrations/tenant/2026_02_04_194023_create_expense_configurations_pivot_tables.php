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
        Schema::create('expense_configuration_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_configuration_id')->cascadeOnDelete();
            $table->foreignId('job_role_id')->cascadeOnDelete();
        });

        Schema::create('expense_configuration_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_configuration_id')->cascadeOnDelete();
            $table->foreignId('position_id')->cascadeOnDelete();
        });

        Schema::create('expense_configuration_territories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_configuration_id')->cascadeOnDelete();
            $table->foreignId('territory_id')->cascadeOnDelete();
        });

        Schema::create('expense_configuration_transport_modes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_configuration_id')->cascadeOnDelete();
            $table->foreignId('transport_mode_id')->cascadeOnDelete();
        });

        Schema::create('expense_configuration_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_configuration_id')->cascadeOnDelete();
            $table->foreignId('emp_grade_id')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_configuration_roles');
        Schema::dropIfExists('expense_configuration_positions');
        Schema::dropIfExists('expense_configuration_territories');
        Schema::dropIfExists('expense_configuration_transport_modes');
        Schema::dropIfExists('expense_configuration_grades');
    }
};
