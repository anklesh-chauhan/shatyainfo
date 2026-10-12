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
        Schema::table('cities', function (Blueprint $table) {
            $table->foreignId('city_class_id')->nullable()->after('country_id')->constrained('city_classes')->nullOnDelete();
            $table->boolean('is_hill_station')->default(false)->after('city_class_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_class_id');
            $table->dropColumn('is_hill_station');
        });
    }
};
