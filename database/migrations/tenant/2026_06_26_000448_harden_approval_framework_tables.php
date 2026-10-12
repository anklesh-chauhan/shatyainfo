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
        Schema::table('approval_flows', function (Blueprint $table) {
            $table->unsignedInteger('priority')->default(0)->after('module');
            $table->unsignedInteger('version')->default(1)->after('priority');
            $table->string('condition_type')->default('amount')->after('version');
            $table->date('effective_from')->nullable()->after('condition_type');
            $table->date('effective_to')->nullable()->after('effective_from');

            $table->index(['module', 'active', 'priority']);
            $table->index(['module', 'territory_id', 'effective_from', 'effective_to'], 'approval_flows_effective_index');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->string('module')->nullable()->after('approval_flow_id');
            $table->string('record_type')->nullable()->after('module');
            $table->unsignedBigInteger('record_id')->nullable()->after('record_type');
            $table->decimal('requested_amount', 24, 2)->nullable()->after('requested_by');
            $table->foreignId('territory_id')->nullable()->after('requested_amount')->constrained()->nullOnDelete();
            $table->unsignedInteger('flow_version')->nullable()->after('territory_id');
            $table->json('selected_steps')->nullable()->after('flow_version');
            $table->json('selected_approvers')->nullable()->after('selected_steps');
            $table->json('submitted_record_summary')->nullable()->after('selected_approvers');
            $table->timestamp('finalized_at')->nullable()->after('completed_at');

            $table->index(['module', 'record_type', 'record_id']);
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->foreignId('reassigned_from_user_id')->nullable()->after('assigned_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable()->after('approved_at');
            $table->timestamp('reminded_at')->nullable()->after('due_at');
            $table->timestamp('escalated_at')->nullable()->after('reminded_at');

            $table->index(['assigned_user_id', 'status', 'step_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropIndex(['assigned_user_id', 'status', 'step_order']);
            $table->dropConstrainedForeignId('reassigned_from_user_id');
            $table->dropColumn(['due_at', 'reminded_at', 'escalated_at']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropIndex(['module', 'record_type', 'record_id']);
            $table->dropConstrainedForeignId('territory_id');
            $table->dropColumn([
                'module',
                'record_type',
                'record_id',
                'requested_amount',
                'flow_version',
                'selected_steps',
                'selected_approvers',
                'submitted_record_summary',
                'finalized_at',
            ]);
        });

        Schema::table('approval_flows', function (Blueprint $table) {
            $table->dropIndex(['module', 'active', 'priority']);
            $table->dropIndex('approval_flows_effective_index');
            $table->dropColumn(['priority', 'version', 'condition_type', 'effective_from', 'effective_to']);
        });
    }
};
