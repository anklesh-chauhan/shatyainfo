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
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete(); // For nesting (grouping)
            $table->foreignId('account_type_id')->constrained(); // Asset, Liability, etc.
            $table->string('code')->unique(); // 1000, 2000, etc.
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_group')->default(false); // true = grouping account, false = ledger
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // Indicates if the account is a system account
            $table->decimal('balance', 15, 2)->default(0.00); // Current balance of the account
            $table->decimal('opening_balance', 15, 2)->default(0.00); // Opening balance for the account
            $table->date('opening_balance_date')->nullable(); // Date of the opening balance
            $table->string('currency', 3)->default('INR'); // Currency code (e.g., USD, EUR, INR)
            $table->string('currency_symbol', 10)->default('₹'); // Symbol for  the currency
            $table->string('currency_name', 50)->default('Indian Rupee');
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
        Schema::dropIfExists('chart_of_accounts');
    }
};
