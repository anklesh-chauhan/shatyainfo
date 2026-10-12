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
        Schema::table('addresses', function (Blueprint $table) {
            $table->foreignId('contact_detail_id')->nullable()->constrained('contact_details')->onDelete('cascade'); // ✅ Add optional contact link
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            // $table->foreignId('company_id')->nullable(false)->change();   // Rollback
            $table->dropForeign(['contact_detail_id']);
            $table->dropColumn('contact_detail_id');
        });
    }
};
