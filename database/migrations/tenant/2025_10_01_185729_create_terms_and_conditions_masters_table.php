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
        Schema::create('terms_and_conditions_masters', function (Blueprint $table) {
            $table->id();
            $table->string('document_type'); // quote, so, po, invoice
            $table->string('title')->nullable(); // e.g. "Payment Terms"
            $table->text('content'); // actual terms text
            $table->boolean('is_default')->default(false); // mark default term for each document type
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
        Schema::dropIfExists('terms_and_conditions_masters');
    }
};
