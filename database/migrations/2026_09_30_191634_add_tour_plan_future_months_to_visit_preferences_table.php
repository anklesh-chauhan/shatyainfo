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
            $table->unsignedTinyInteger('tour_plan_future_months')->default(12);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_preferences', function (Blueprint $table) {
            $table->dropColumn('tour_plan_future_months');
        });
    }
};
