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
        Schema::create('item_measurement_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_master_id')->constrained('item_masters')->onDelete('cascade');
            $table->foreignId('unit_of_measurement_id')->constrained('unit_of_measurements')->onDelete('cascade');
            $table->decimal('conversion_rate', 10, 2)->default(1);
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
        Schema::dropIfExists('item_measurement_units');
    }
};
