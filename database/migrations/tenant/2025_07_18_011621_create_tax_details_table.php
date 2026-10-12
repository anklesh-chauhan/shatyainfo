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
        Schema::create('tax_details', function (Blueprint $table) {
            $table->id();
            // Polymorphic relation
            $table->morphs('taxable'); // taxable_id, taxable_type (e.g., Quote, SalesOrder, etc.)

            // Foreign keys
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete(); // Optional: main Tax
            $table->foreignId('tax_component_id')->nullable()->constrained()->nullOnDelete(); // Specific CGST/SGST etc.

            // Tax information
            $table->string('type'); // e.g. CGST, SGST, IGST, CESS, ITC
            $table->decimal('rate', 8, 2); // e.g. 9.00 (%)
            $table->decimal('amount', 15, 2); // e.g. 1234.56
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
        Schema::dropIfExists('tax_details');
    }
};
