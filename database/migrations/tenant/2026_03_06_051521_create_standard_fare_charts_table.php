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
        Schema::create('standard_fare_charts', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Route Information
            |--------------------------------------------------------------------------
            */
            $table->foreignId('from_area_town_id')
                ->constrained('city_pin_codes')
                ->cascadeOnDelete();

            $table->foreignId('to_area_town_id')
                ->constrained('city_pin_codes')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Distance / Fare
            |--------------------------------------------------------------------------
            */
            $table->decimal('distance_km', 8, 2)->nullable();

            $table->decimal('fare_amount', 10, 2)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Optional Territory Override
            |--------------------------------------------------------------------------
            */
            $table->foreignId('territory_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('patch_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('type_master_id')
                ->nullable()
                ->constrained('type_masters')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */
            $table->boolean('is_active')->default(true);

            /*
            |--------------------------------------------------------------------------
            | System Fields
            |--------------------------------------------------------------------------
            */
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standard_fare_charts');
    }
};
