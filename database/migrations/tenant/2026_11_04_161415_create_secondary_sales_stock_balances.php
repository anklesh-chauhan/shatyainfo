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
        Schema::create('secondary_sales_preferences', function (Blueprint $table): void {
            $table->id();
            $table->string('quantity_unit', 30)->default('box');
            $table->json('formula_signs');
            $table->decimal('variance_tolerance_qty', 18, 6)->default(0);
            $table->decimal('variance_tolerance_percent', 8, 4)->default(0);
            $table->boolean('freeze_incentive_on_variance')->default(true);
            $table->boolean('require_prior_closing')->default(false);
            $table->string('prior_closing_source', 20)->default('reported');
            $table->boolean('verify_primary_purchases')->default(false);
            $table->decimal('primary_purchase_tolerance_qty', 18, 6)->default(0);
            $table->boolean('include_secondary_free_in_scheme_deduction')->default(false);
            $table->boolean('flag_expired_batches')->default(true);
            $table->boolean('allow_fractional_quantities')->default(false);
            $table->boolean('exclusive_source_kind_per_period')->default(true);
            $table->json('approvable_discrepancy_codes');
            $table->timestamps();
        });
        Schema::table('secondary_sales_statements', function (Blueprint $table): void {
            $table->string('source_kind', 30)->default('transactions')->after('period');
            $table->json('balance_rules')->nullable()->after('source_kind');
        });
        Schema::table('secondary_sales_lines', function (Blueprint $table): void {
            $table->boolean('incentive_frozen')->default(false)->after('physically_verified');
        });
        Schema::create('secondary_sales_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secondary_sales_statement_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->foreignId('item_master_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('batch_no', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedBigInteger('pts_paise')->default(0);
            $table->decimal('opening_qty', 18, 6)->default(0);
            $table->decimal('purchase_qty', 18, 6)->default(0);
            $table->decimal('primary_free_qty', 18, 6)->default(0);
            $table->decimal('purchase_return_qty', 18, 6)->default(0);
            $table->decimal('secondary_sales_qty', 18, 6)->default(0);
            $table->decimal('secondary_free_qty', 18, 6)->default(0);
            $table->decimal('sales_return_qty', 18, 6)->default(0);
            $table->decimal('expiry_breakage_qty', 18, 6)->default(0);
            $table->decimal('reported_closing_qty', 18, 6)->default(0);
            $table->decimal('calculated_closing_qty', 18, 6)->default(0);
            $table->decimal('variance_qty', 18, 6)->default(0);
            $table->decimal('prior_closing_qty', 18, 6)->nullable();
            $table->decimal('primary_invoiced_qty', 18, 6)->nullable();
            $table->string('status', 30)->default('discrepancy');
            $table->string('group_key', 64)->nullable()->index();
            $table->json('raw');
            $table->json('errors');
            $table->timestamps();
        });
        Schema::table('secondary_sales_lines', function (Blueprint $table): void {
            $table->foreignId('secondary_sales_balance_id')->nullable()->after('secondary_sales_statement_id')->constrained('secondary_sales_balances')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('secondary_sales_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('secondary_sales_balance_id');
            $table->dropColumn('incentive_frozen');
        });
        Schema::dropIfExists('secondary_sales_balances');
        Schema::table('secondary_sales_statements', function (Blueprint $table): void {
            $table->dropColumn(['source_kind', 'balance_rules']);
        });
        Schema::dropIfExists('secondary_sales_preferences');
    }
};
