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
        Schema::create('expense_configuration_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_configuration_id')->cascadeOnDelete();

            $table->decimal('min_value', 10, 2)->nullable();
            $table->decimal('max_value', 10, 2)->nullable();

            $table->decimal('rate', 10, 2)->nullable();
            $table->decimal('flat_amount', 10, 2)->nullable();

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
        Schema::dropIfExists('expense_configuration_slabs');
    }
};
