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
        Schema::create('sales_document_preferences', function (Blueprint $table) {
            $table->id();
            $table->boolean('attach_pdf_in_email')->default(true);
            $table->boolean('encrypt_pdf')->default(false);

            // Discounts
            $table->enum('discount_level', ['none', 'line_item', 'transaction', 'both'])->default('line_item');

            // Additional charges
            $table->boolean('include_adjustments')->default(false);
            $table->boolean('include_shipping_charges')->default(false);

            // Tax preferences
            $table->enum('tax_mode', ['inclusive', 'exclusive', 'both'])->default('exclusive');

            // Rounding
            $table->enum('rounding_option', ['none', 'nearest'])->default('none');

            // Salesperson field
            $table->boolean('enable_salesperson')->default(false);

            // Billable expenses
            $table->boolean('enable_billable_expenses')->default(false);
            $table->decimal('default_markup_percentage', 5, 2)->nullable();

            // Document copy label options
            $table->enum('document_copy_type', [
                'original_duplicate',
                'original_duplicate_triplicate',
                'original_duplicate_triplicate_quadruplicate',
                'original_duplicate_triplicate_quadruplicate_quintuplicate',
                'two_copies',
                'three_copies',
                'four_five_copies',
            ])->default('original_duplicate');

            // Print preferences
            $table->json('default_print_preferences')->nullable();
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
        Schema::dropIfExists('sales_document_preferences');
    }
};
