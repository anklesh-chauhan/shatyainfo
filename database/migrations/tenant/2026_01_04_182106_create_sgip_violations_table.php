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
        Schema::create('sgip_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sgip_distribution_id')->constrained();
            $table->foreignId('sgip_limit_id')->constrained();

            $table->enum('violation_type', [
                'quantity',
                'value',
            ]);

            $table->decimal('allowed_value', 12, 2);
            $table->decimal('actual_value', 12, 2);
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
        Schema::dropIfExists('sgip_violations');
    }
};
