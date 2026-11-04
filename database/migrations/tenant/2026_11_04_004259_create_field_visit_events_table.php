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
        Schema::create('field_visit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_dcr_id')->constrained()->restrictOnDelete();
            $table->uuid('previous_event_id')->nullable()->index();
            $table->string('action', 24);
            $table->json('target');
            $table->json('payload');
            $table->timestamp('captured_at', 6);
            $table->timestamp('received_at', 6);
            $table->index(['sales_dcr_id', 'user_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_visit_events');
    }
};
