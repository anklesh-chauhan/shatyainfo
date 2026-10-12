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
        Schema::table('sales_document_items', function (Blueprint $table) {
            $table->decimal('final_taxable_amount', 15, 2)
                ->nullable()
                ->after('amount')
                ->comment('Amount after discount but before tax');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_document_items', function (Blueprint $table) {
            $table->dropColumn('final_taxable_amount');
        });
    }
};
