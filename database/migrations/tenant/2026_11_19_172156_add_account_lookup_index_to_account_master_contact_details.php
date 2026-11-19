<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account_master_contact_details', function (Blueprint $table) {
            $table->index(['account_master_id', 'contact_detail_id'], 'account_contact_account_lookup_idx')->online();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_master_contact_details', function (Blueprint $table) {
            $table->dropIndex('account_contact_account_lookup_idx');
        });
    }
};
