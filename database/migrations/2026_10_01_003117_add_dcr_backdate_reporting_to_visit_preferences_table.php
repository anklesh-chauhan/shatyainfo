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
        Schema::table('visit_preferences', function (Blueprint $table) {
            $table->boolean('allow_backdated_dcr')->default(true);
            $table->unsignedTinyInteger('dcr_backdate_days')->default(3);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_preferences', function (Blueprint $table) {
            $table->dropColumn(['allow_backdated_dcr', 'dcr_backdate_days']);
        });
    }
};
