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
        Schema::create('leave_rules', function (Blueprint $table) {
            $table->id();

            $table->string('rule_key')->unique();
            $table->foreignId('leave_rule_category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('leave_type_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->json('condition_json');
            $table->json('action_json');

            $table->unsignedInteger('priority')->default(100);

            $table->foreignId('employee_attendance_status_id')
                ->nullable()
                ->constrained('employee_attendance_statuses')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);

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
        Schema::dropIfExists('leave_rules');
    }
};
