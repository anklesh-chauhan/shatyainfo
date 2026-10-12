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
        Schema::create('bank_details', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('bank_account_number')->unique(); // Unique account number
            $table->string('bank_account_name')->nullable(); // Account holder's name
            $table->string('bank_account_ifsc_code')->nullable(); // IFSC code for the bank account
            $table->string('bank_account_swift_code')->nullable(); // SWIFT code for international transactions
            $table->string('bank_account_code')->nullable(); // Optional code for the account
            $table->string('bank_account_type')->nullable(); // e.g., "Savings", "Current"
            $table->string('bank_account_currency')->default('INR'); // Default to Indian Rupee
            $table->string('bank_account_status')->default('active'); // e.g., "active", "inactive"
            $table->string('bank_account_branch')->nullable(); // Branch name or identifier
            $table->string('bank_account_iban')->nullable(); // IBAN for international transactions
            $table->string('bank_account_bic')->nullable(); // BIC for international transactions
            $table->string('bank_account_phone')->nullable(); // Contact phone number for the bank account
            $table->string('bank_account_email')->nullable(); // Contact email for the bank account
            $table->string('bank_account_address')->nullable(); // Address of the bank branch
            $table->string('bank_account_city')->nullable(); // City of the bank branch
            $table->string('bank_account_state')->nullable(); // State of the bank branch
            $table->string('bank_account_country')->default('India'); // Default to India
            $table->string('bank_account_zip')->nullable(); // ZIP code of the bank branch
            $table->string('bank_account_tax_id')->nullable(); // Tax ID associated with the bank account
            $table->string('bank_account_micr_code')->nullable();
            $table->string('bank_account_rtgs_code')->nullable();
            $table->string('bank_account_ecs_code')->nullable();
            $table->text('remark')->nullable();
            $table->boolean('is_deleted')->default(false); // Soft delete flag
            $table->boolean('is_active')->default(true); // Active status flag
            $table->boolean('is_default')->default(false); // Flag to indicate if this is the default bank account
            $table->boolean('is_verified')->default(false); // Flag to indicate if the bank account is verified
            $table->boolean('is_primary')->default(false); // Flag to indicate
            $table->nullableMorphs('bankable'); // Polymorphic relation for associating with other models
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
        Schema::dropIfExists('bank_details');
    }
};
