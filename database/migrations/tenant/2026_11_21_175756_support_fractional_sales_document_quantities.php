<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_document_items', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_document_items', function (Blueprint $table): void {
            $table->integer('quantity')->default(1)->change();
        });
    }
};
