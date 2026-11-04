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
            $table->uuid('replacement_event_id')->nullable()->unique('field_recovery_replacement_unique');
            $table->json('replacement_payload')->nullable();
            $table->timestamp('amended_at', 6)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('field_sync_recoveries', function (Blueprint $table) {
            $table->dropUnique('field_recovery_replacement_unique');
            $table->dropColumn(['replacement_event_id', 'replacement_payload', 'amended_at']);
        });
    }
};
