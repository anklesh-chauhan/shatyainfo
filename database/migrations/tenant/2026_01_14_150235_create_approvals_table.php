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
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->foreignId('approval_flow_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('approval_status')->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index('approval_status');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
