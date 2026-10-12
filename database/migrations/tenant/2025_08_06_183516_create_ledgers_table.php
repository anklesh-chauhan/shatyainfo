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
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chart_of_account_id')->constrained();
            $table->date('date');
            $table->string('reference')->nullable(); // Invoice No, Journal Ref
            $table->text('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->morphs('ledgerable'); // For connecting to invoices, payments, etc.
            $table->string('currency', 3)->default('INR'); // Currency code (e.g., USD, EUR, INR)
            $table->string('currency_symbol', 10)->default('₹'); // Symbol for the currency
            $table->string('currency_name', 50)->default('Indian Rupee');
            $table->boolean('is_reconciled')->default(false); // Indicates if the ledger entry is reconciled
            $table->boolean('is_active')->default(true); // Indicates if the ledger entry is active
            $table->boolean('is_system')->default(false); // Indicates if the ledger entry is a system entry
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
        Schema::dropIfExists('ledgers');
    }
};
