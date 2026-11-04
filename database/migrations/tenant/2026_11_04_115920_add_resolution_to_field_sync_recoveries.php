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
        Schema::table('field_sync_recoveries', function (Blueprint $table) {
            $table->string('resolution_mode', 24)->default('retry_original');
            $table->json('resolution_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('field_sync_recoveries', function (Blueprint $table) {
            $table->dropColumn(['resolution_mode', 'resolution_snapshot']);
        });
    }
};
