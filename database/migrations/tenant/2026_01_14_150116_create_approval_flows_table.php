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
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();

            $table->string('module'); // sales_order, quote, leave, lead, expense
            $table->foreignId('territory_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('min_amount', 24, 2)->nullable();
            $table->decimal('max_amount', 24, 2)->nullable();
            $table->boolean('active')->default(true);

            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index(['module', 'territory_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_flows');
    }
};
