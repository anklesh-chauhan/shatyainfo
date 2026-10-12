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
        Schema::create('expense_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->foreignId('expense_type_id')
                ->constrained()
                ->cascadeOnDelete();

            // Calculation logic
            $table->string('calculation_strategy'); // flat, per_km, slab, multiplier

            $table->decimal('rate', 10, 2)->nullable(); // ₹ per km / day / visit
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->decimal('min_amount', 10, 2)->nullable();

            $table->integer('priority')->default(0);

            // Controls
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('allow_manual_override')->default(true);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->boolean('is_active')->default(true);

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
        Schema::dropIfExists('expense_configurations');
    }
};
