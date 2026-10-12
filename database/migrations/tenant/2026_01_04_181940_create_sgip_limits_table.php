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
        Schema::create('sgip_limits', function (Blueprint $table) {
            $table->id();
            $table->enum('applies_to', [
                'account',   // doctor
                'employee',
                'territory',
                'global',
            ]);

            $table->foreignId('applies_to_id')->nullable();

            $table->enum('item_type', [
                'sample',
                'gift',
                'input',
            ]);

            $table->enum('period', [
                'daily',
                'monthly',
                'yearly',
            ]);

            $table->integer('max_quantity')->nullable();
            $table->decimal('max_value', 12, 2)->nullable();
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
        Schema::dropIfExists('sgip_limits');
    }
};
