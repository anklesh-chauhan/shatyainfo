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
        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Asset, Liability, Income, Expense, Equity
            $table->string('code')->unique(); // A, L, I, E, Q
            $table->string('description')->nullable(); // Optional description of the account type
            $table->boolean('is_active')->default(true); // Indicates if the account type is active
            $table->boolean('is_system')->default(false); // Indicates if the account type is a system type
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
        Schema::dropIfExists('account_types');
    }
};
