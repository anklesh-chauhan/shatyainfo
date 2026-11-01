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
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->string('registration_email')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['registration_email']);
            $table->dropColumn('registration_email');
        });
    }
};
