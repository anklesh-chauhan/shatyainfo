<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('city_pin_codes', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('country_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('standard_fare_charts', function (Blueprint $table) {
            $table->string('distance_source')->default('manual')->after('distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('standard_fare_charts', function (Blueprint $table) {
            $table->dropColumn('distance_source');
        });

        Schema::table('city_pin_codes', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
