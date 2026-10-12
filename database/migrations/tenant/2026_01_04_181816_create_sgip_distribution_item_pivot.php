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
        Schema::create('sgip_distribution_item_pivot', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sgip_distribution_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('item_master_id')
                ->constrained('item_masters');

            $table->integer('quantity');
            $table->decimal('unit_value', 10, 2);
            $table->decimal('total_value', 12, 2);

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
        Schema::dropIfExists('sgip_distribution_item_pivot');
    }
};
