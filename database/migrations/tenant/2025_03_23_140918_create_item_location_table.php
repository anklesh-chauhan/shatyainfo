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
        Schema::create('item_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_master_id')->constrained('item_masters')->onDelete('cascade');
            $table->foreignId('location_master_id')->constrained('location_masters')->onDelete('cascade');
            $table->integer('quantity')->default(0);
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
        Schema::dropIfExists('item_location');
    }
};
