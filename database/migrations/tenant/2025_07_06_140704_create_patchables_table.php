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
        Schema::create('patchables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patch_id')->constrained('patches')->onDelete('cascade');
            $table->morphs('patchable'); // Creates patchable_id and patchable_type
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patchables');
    }
};
