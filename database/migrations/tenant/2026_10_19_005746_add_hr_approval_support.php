<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['leave_adjustments', 'leave_encashments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('approval_status')->default('approved')->index();
            });
        }
        Schema::table('daily_attendances', function (Blueprint $table): void {
            $table->integer('calculated_ot_minutes')->default(0);
            $table->decimal('calculated_comp_off_days', 6, 2)->default(0);
        });
        Schema::create('leave_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_application_id')->constrained();
            $table->foreignId('employee_id')->constrained();
            $table->text('reason')->nullable();
            $table->string('approval_status')->default('draft');
            $table->timestamp('applied_at')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();
        });
        Schema::create('hr_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->string('request_type');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->json('previous_values');
            $table->json('proposed_values');
            $table->text('reason');
            $table->string('approval_status')->default('draft');
            $table->timestamp('applied_at')->nullable();
            $table->index(['target_type', 'target_id']);
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendances', fn (Blueprint $table) => $table->dropColumn(['calculated_ot_minutes', 'calculated_comp_off_days']));
        Schema::dropIfExists('hr_change_requests');
        Schema::dropIfExists('leave_cancellations');
        foreach (['leave_adjustments', 'leave_encashments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('approval_status'));
        }
    }
};
