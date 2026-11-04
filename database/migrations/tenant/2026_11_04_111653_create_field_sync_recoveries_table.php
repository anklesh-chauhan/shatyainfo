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
        Schema::create('field_sync_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->string('request_hash', 64);
            $table->string('path');
            $table->date('report_date')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->json('snapshot');
            $table->string('status', 30)->default('failed');
            $table->unsignedInteger('attempts')->default(1);
            $table->text('last_error');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->json('review_history')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'operation_id'], 'field_recovery_operation_unique');
            $table->index(['reviewer_id', 'status']);
            $table->index(['user_id', 'report_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_sync_recoveries');
    }
};
