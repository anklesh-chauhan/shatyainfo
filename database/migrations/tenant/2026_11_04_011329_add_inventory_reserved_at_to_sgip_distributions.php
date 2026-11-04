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
        Schema::table('sgip_distributions', function (Blueprint $table) {
            $table->timestamp('inventory_reserved_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sgip_distributions', function (Blueprint $table) {
            $table->dropColumn('inventory_reserved_at');
        });
    }
};
