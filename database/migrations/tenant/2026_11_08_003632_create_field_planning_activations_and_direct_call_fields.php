<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_planning_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('field_planning_revision_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->date('effective_on');
            $table->timestamp('created_at');
        });
        Schema::table('visits', function (Blueprint $table): void {
            $table->uuid('client_call_id')->nullable()->unique();
            $table->text('unplanned_reason')->nullable();
            $table->foreignId('planning_revision_id')->nullable()->constrained('field_planning_revisions');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('planning_revision_id');
            $table->dropColumn(['client_call_id', 'unplanned_reason']);
        });
        Schema::dropIfExists('field_planning_activations');
    }
};
