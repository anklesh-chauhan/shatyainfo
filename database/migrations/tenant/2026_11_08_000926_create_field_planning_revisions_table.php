<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_planning_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->foreignId('previous_revision_id')->nullable()->constrained('field_planning_revisions');
            $table->string('mode', 30);
            $table->json('rules');
            $table->date('proposed_effective_on')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_planning_revisions');
    }
};
