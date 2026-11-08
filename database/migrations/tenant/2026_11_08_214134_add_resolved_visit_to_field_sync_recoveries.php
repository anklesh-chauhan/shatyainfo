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
            $table->foreignId('resolved_visit_id')->nullable()->constrained('visits')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('field_sync_recoveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_visit_id');
        });
    }
};
