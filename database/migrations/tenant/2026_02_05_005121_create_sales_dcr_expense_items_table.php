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
        Schema::create('sales_dcr_expense_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_dcr_expense_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('label'); // Lunch, Dinner, Toll, Parking
            $table->decimal('amount', 10, 2);

            $table->json('meta')->nullable();

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
        Schema::dropIfExists('sales_dcr_expense_items');
    }
};
