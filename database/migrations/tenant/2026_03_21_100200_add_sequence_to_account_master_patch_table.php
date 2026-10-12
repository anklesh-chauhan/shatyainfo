<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_master_patch', function (Blueprint $table) {
            $table->unsignedInteger('sequence_no')->nullable()->after('account_master_id');
            $table->decimal('distance_km', 10, 2)->nullable()->after('sequence_no');
            $table->index(['patch_id', 'sequence_no'], 'account_master_patch_sequence_idx');
        });

        DB::statement('
            UPDATE account_master_patch amp
            JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY patch_id ORDER BY id) AS seq
                FROM account_master_patch
                WHERE deleted_at IS NULL
            ) ranked ON ranked.id = amp.id
            SET amp.sequence_no = ranked.seq
            WHERE amp.sequence_no IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('account_master_patch', function (Blueprint $table) {
            $table->dropIndex('account_master_patch_sequence_idx');
            $table->dropColumn(['sequence_no', 'distance_km']);
        });
    }
};
