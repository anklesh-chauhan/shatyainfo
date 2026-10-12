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
        Schema::table('leads', function (Blueprint $table) {
            // Add the account_master_id column to the leads table
            $table->unsignedBigInteger('account_master_id')->nullable()->after('id');

            // Add a foreign key constraint to the account_master_id column
            $table->foreign('account_master_id')
                ->references('id')
                ->on('account_masters')
                ->onDelete('set null'); // Set to null if the related account master is deleted
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Drop the foreign key constraint
            $table->dropForeign(['account_master_id']);

            // Drop the account_master_id column
            $table->dropColumn('account_master_id');
        });
    }
};
