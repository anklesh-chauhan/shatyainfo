<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_location', function (Blueprint $table) {
            $table->decimal('quantity', 15, 3)->default(0)->change();
        });

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });

        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });

        Schema::table('inventory_audits', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('item_location', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->string('status')->default('posted')->change();
        });

        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->string('status')->default('posted')->change();
        });

        Schema::table('inventory_audits', function (Blueprint $table) {
            $table->string('status')->default('posted')->change();
        });
    }
};
