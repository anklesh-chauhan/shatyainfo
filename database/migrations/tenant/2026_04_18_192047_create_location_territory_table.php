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
        Schema::create('location_territory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_master_id')->constrained('location_masters')->onDelete('cascade');
            $table->foreignId('territory_id')->constrained('territories')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_territory');
    }
};
