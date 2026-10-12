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
        Schema::create('approval_flow_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('approval_flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->foreignId('job_role_id')->constrained('job_roles');
            $table->enum('territory_scope', ['self', 'children', 'all'])->default('self');
            $table->boolean('can_skip')->default(false);

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->unique(['approval_flow_id', 'step_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_flow_steps');
    }
};
