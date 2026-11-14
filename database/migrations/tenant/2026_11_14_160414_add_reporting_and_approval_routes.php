<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('explicit_reporting_manager_id')->nullable()->constrained('employees')->restrictOnDelete();
        });
        Schema::table('approval_flows', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('route_type')->default('custom');
        });
        Schema::create('approval_flow_modules', function (Blueprint $table): void {
            $table->foreignId('approval_flow_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->primary(['approval_flow_id', 'module']);
            $table->index('module');
        });
        Schema::table('approval_flow_steps', function (Blueprint $table): void {
            $table->unsignedBigInteger('job_role_id')->nullable()->change();
            $table->string('routing_type')->default('job_role');
            $table->foreignId('approver_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
        });
        Schema::table('approval_steps', function (Blueprint $table): void {
            $table->unsignedBigInteger('job_role_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic rollback would remove saved reporting and approval routes.');
    }
};
