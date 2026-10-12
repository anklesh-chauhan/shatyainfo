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
        Schema::create('leave_instances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->decimal('pay_factor', 3, 2)->default(1.00); // 1, 0.5, 0

            $table->enum('approval_status', [
                'applied',
                'approved',
                'rejected',
                'cancelled',
            ])->default('applied');

            $table->boolean('is_half_day')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_instances');
    }
};
