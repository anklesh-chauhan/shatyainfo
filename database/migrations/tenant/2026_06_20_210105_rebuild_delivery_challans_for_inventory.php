<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('delivery_challans');

        Schema::create('delivery_challans', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('account_masters')->cascadeOnDelete();
            $table->foreignId('location_master_id')->constrained('location_masters')->cascadeOnDelete();
            $table->date('delivery_date');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index(['status', 'delivery_date']);
            $table->index(['sales_invoice_id', 'delivery_date']);
        });

        Schema::create('delivery_challan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_challan_id')->constrained('delivery_challans')->cascadeOnDelete();
            $table->foreignId('sales_document_item_id')->nullable()->constrained('sales_document_items')->nullOnDelete();
            $table->foreignId('item_master_id')->constrained('item_masters')->cascadeOnDelete();
            $table->decimal('quantity_delivered', 15, 3);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('batch_number')->nullable();
            $table->text('remarks')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            $table->index(['delivery_challan_id', 'item_master_id'], 'dc_lines_dc_item_idx');
        });

        Schema::table('sales_document_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_document_items', 'quantity_delivered')) {
                $table->decimal('quantity_delivered', 15, 3)->default(0)->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_document_items', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_document_items', 'quantity_delivered')) {
                $table->dropColumn('quantity_delivered');
            }
        });

        Schema::dropIfExists('delivery_challan_lines');
        Schema::dropIfExists('delivery_challans');
    }
};
