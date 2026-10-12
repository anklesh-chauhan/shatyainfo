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
        Schema::create('sales_dcr_expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_dcr_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('expense_type_id')
                ->constrained('expense_types');

            $table->foreignId('transport_mode_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->decimal('amount', 10, 2);

            $table->boolean('is_auto_calculated')->default(false);

            $table->decimal('quantity', 8, 2)->nullable();
            // km, days, units etc.

            $table->decimal('rate', 10, 2)->nullable();

            $table->json('meta')->nullable();
            /*
            {
                "distance": 42,
                "rate_per_km": 12,
                "config_id": 5,
                "source": "tour_plan"
            }
            */

            $table->text('remarks')->nullable();

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
        Schema::dropIfExists('sales_dcr_expenses');
    }
};
