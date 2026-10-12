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
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->string('module');
            $table->foreignId('territory_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('level')->default(1);
            $table->foreignId('approver_id')->constrained('users');
            $table->decimal('min_amount', 24, 2)->nullable();
            $table->decimal('max_amount', 24, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->index(['module', 'territory_id', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_rules');
    }
};
