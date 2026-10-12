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
        Schema::create('account_master_patch', function (Blueprint $table) {
            $table->id();

            $table->foreignId('patch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('account_master_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->blameable();
            $table->blameableSoftDeletes();

            $table->timestamps();

            // Prevent duplicate assignment
            $table->unique(['patch_id', 'account_master_id']);

            // For reverse lookup (account → patches)
            $table->index(
                ['account_master_id'],
                'account_patch_idx'
            );

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_master_patch');
    }
};
