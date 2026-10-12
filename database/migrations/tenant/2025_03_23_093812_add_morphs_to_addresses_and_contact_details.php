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
            $table->nullableMorphs('addressable'); // Adding polymorphic relation
        });

        Schema::table('contact_details', function (Blueprint $table) {
            $table->nullableMorphs('contactable'); // Adding polymorphic relation
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropMorphs('addressable');
        });

        Schema::table('contact_details', function (Blueprint $table) {
            $table->dropMorphs('contactable');
        });
    }
};
