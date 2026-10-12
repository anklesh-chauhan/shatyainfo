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
        Schema::table('territories', function (Blueprint $table) {
            $table->foreignId('reporting_position_id')
                ->nullable()
                ->constrained('positions')
                ->onDelete('set null')
                ->after('parent_territory_id'); // Assuming you want to place it after parent_territory_id
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('territories', function (Blueprint $table) {
            $table->dropForeign(['reporting_position_id']);
            $table->dropColumn('reporting_position_id');
        });
    }
};
