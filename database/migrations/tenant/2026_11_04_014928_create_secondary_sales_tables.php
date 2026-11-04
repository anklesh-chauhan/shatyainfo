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
        Schema::table('account_masters', fn (Blueprint $table) => $table->boolean('accept_secondary_statements')->default(false));
        Schema::create('sku_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_master_id')->constrained()->restrictOnDelete();
            $table->string('unit', 30);
            $table->decimal('base_units', 18, 6);
            $table->unique(['item_master_id', 'unit']);
            $table->timestamps();
        });
        Schema::create('secondary_sales_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stockist_id')->constrained('account_masters')->restrictOnDelete();
            $table->foreignId('territory_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('period', 7);
            $table->string('status', 30)->default('pending_review');
            $table->string('source_path');
            $table->string('source_sha256', 64);
            $table->index(['stockist_id', 'source_sha256'], 'secondary_statement_source_index');
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('secondary_sales_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secondary_sales_statement_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('invoice_number', 100)->nullable();
            $table->date('invoice_date')->nullable();
            $table->index(['secondary_sales_statement_id', 'invoice_date'], 'secondary_statement_date_index');
            $table->string('batch_no', 100)->nullable();
            $table->foreignId('item_master_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit', 30)->nullable();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('conversion_factor', 18, 6)->default(0);
            $table->decimal('base_quantity', 18, 6)->default(0);
            $table->unsignedBigInteger('gross_paise')->default(0);
            $table->unsignedBigInteger('discount_paise')->default(0);
            $table->string('transaction_type', 30)->nullable();
            $table->string('physical_reference', 100)->nullable();
            $table->boolean('physically_verified')->default(false);
            $table->string('group_key', 64)->nullable()->index();
            $table->string('adjustment_key', 64)->nullable()->index();
            $table->json('raw');
            $table->json('errors');
            $table->timestamps();
        });
        Schema::create('secondary_sales_groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 64)->unique();
            $table->foreignId('secondary_sales_statement_id')->constrained()->restrictOnDelete();
        });
        Schema::create('secondary_sales_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_key', 64)->unique();
            $table->foreignId('secondary_sales_line_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secondary_sales_adjustments');
        Schema::dropIfExists('secondary_sales_groups');
        Schema::dropIfExists('secondary_sales_lines');
        Schema::dropIfExists('secondary_sales_statements');
        Schema::dropIfExists('sku_unit_conversions');
        Schema::table('account_masters', fn (Blueprint $table) => $table->dropColumn('accept_secondary_statements'));
    }
};
