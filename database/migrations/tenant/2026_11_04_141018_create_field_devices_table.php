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
        Schema::create('field_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('device_id');
            $table->string('name', 120);
            $table->string('platform', 40)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('pending_dates');
            $table->unsignedInteger('pending_count')->default(0);
            $table->timestamp('reported_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('close_reason')->nullable();
            $table->json('history')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_id'], 'field_devices_user_device_unique');
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_devices');
    }
};
