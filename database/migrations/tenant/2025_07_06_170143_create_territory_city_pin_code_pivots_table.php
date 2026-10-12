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
        Schema::create('territory_city_pin_code_pivots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->onDelete('cascade');
            $table->foreignId('city_pin_code_id')->constrained('city_pin_codes')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('territory_city_pin_code_pivots');
    }
};
