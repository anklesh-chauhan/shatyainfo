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
        Schema::create('tax_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['CGST', 'SGST', 'IGST', 'CESS', 'VAT', 'EXCISE', 'CUSTOM']);
            $table->decimal('rate', 6, 2); // e.g. 9.00, 22.00, 5.00
            $table->string('description')->nullable(); // Optional note about CESS type or usage
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
        Schema::dropIfExists('tax_components');
    }
};
