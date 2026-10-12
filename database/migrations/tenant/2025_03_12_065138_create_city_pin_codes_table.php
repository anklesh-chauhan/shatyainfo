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
        Schema::create('city_pin_codes', function (Blueprint $table) {
            $table->id();
            $table->string('area_town')->nullable();
            $table->integer('pin_code'); // To relate with `city_pin_codes`
            $table->foreignId('city_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('state_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('country_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('city_pin_codes');
    }
};
