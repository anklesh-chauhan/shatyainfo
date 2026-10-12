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
        Schema::create('delivery_challans', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->foreignId('lead_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('deal_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('contact_detail_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_master_id')->constrained()->onDelete('cascade');
            $table->foreignId('billing_address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->foreignId('shipping_address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->date('date');
            $table->enum('status', ['draft', 'sent', 'accepted', 'rejected', 'canceled'])->default('draft');
            $table->foreignId('sales_person_id')->nullable()->constrained('users')->onDelete('cascade'); // Assuming you have a users table
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('currency', 3)->default('INR'); // Assuming INR as default currency
            $table->string('payment_terms')->nullable(); // e.g., 'Net 30', 'Due on receipt'
            $table->string('payment_method')->nullable(); // e.g., 'Credit Card', 'Bank Transfer'
            $table->string('shipping_method')->nullable();
            $table->string('shipping_cost')->nullable(); // e.g., '5.00'
            $table->text('description')->nullable();
            $table->date('rejected_at')->nullable();
            $table->date('canceled_at')->nullable(); // e.g., '2025-12-31'
            $table->date('sent_at')->nullable(); // e.g., '2025-12-31'
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
        Schema::dropIfExists('delivery_challans');
    }
};
