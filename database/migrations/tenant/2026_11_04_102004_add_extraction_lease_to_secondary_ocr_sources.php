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
        Schema::table('secondary_ocr_sources', function (Blueprint $table): void {
            $table->uuid('extraction_token')->nullable();
            $table->timestamp('extraction_started_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('secondary_ocr_sources', function (Blueprint $table): void {
            $table->dropColumn(['extraction_token', 'extraction_started_at']);
        });
    }
};
