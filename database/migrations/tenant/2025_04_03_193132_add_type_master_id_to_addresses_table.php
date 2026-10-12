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
            $table->unsignedBigInteger('type_master_id')->nullable()->after('addressable_type'); // Add the column
            $table->foreign('type_master_id')->references('id')->on('type_masters')->onDelete('cascade'); // Add foreign key constraint
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropForeign(['type_master_id']); // Drop the foreign key
            $table->dropColumn('type_master_id'); // Drop the column
        });
    }
};
