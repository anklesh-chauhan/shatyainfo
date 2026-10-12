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
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->foreignId('lead_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('deal_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('contact_detail_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_master_id')->constrained()->onDelete('cascade');
            $table->foreignId('billing_address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->foreignId('shipping_address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->date('date');
            $table->date('expiration_date')->nullable(); // e.g., '2025-12-31'
            $table->enum('status', ['draft', 'sent', 'accepted', 'rejected', 'canceled'])->default('draft');
            $table->foreignId('sales_person_id')->nullable()->constrained('users')->onDelete('cascade'); // Assuming you have a users table
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->enum('discount_type', ['percentage', 'amount'])->nullable(); // e.g., 'percentage' or 'fixed'
            $table->decimal('discount_value', 15, 2)->nullable(); // e.g., '10.00' for 10% or '50.00' for fixed amount
            $table->decimal('transaction_discount', 15, 2)->default(0); // Computed discount amount
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('round_off', 15, 2)->default(0); // round off to 2 decimal places
            $table->decimal('total', 15, 2)->default(0);
            $table->enum('discount_mode', ['none', 'line_item', 'transaction', 'both'])->default('none'); // adjust column placement as needed
            $table->decimal('gross_total', 15, 2)->default(0);
            $table->string('currency', 3)->default('INR'); // Assuming INR as default currency
            $table->foreignId('payment_term_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('payment_method_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('shipping_method_id')->nullable()->constrained()->onDelete('set null');
            $table->string('shipping_cost')->nullable(); // e.g., '5.00'
            $table->text('description')->nullable();
            $table->date('accepted_at')->nullable(); // e.g., '2025-12-31'
            $table->date('rejected_at')->nullable();
            $table->date('canceled_at')->nullable(); // e.g., '2025-12-31'
            $table->date('sent_at')->nullable(); // e.g., '2025-12-31'
            $table->date('delivery_date')->nullable(); // e.g., '2025-12-31'
            $table->dateTime('order_confirmation_at')->nullable(); // e.g., '2025-12-31'
            $table->string('approval_status')->default('draft');
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
        Schema::dropIfExists('sales_orders');
    }
};
