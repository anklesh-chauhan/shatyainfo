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
        Schema::create('leave_encashments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();

            $table->decimal('days', 4, 2);
            $table->decimal('amount', 10, 2)->nullable();

            $table->date('encashed_on');
            $table->date('payroll_month')->nullable();

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index(['employee_id', 'leave_type_id', 'encashed_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_encashments');
    }
};
