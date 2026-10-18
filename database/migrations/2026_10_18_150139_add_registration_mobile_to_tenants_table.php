<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->string('registration_mobile', 16)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['registration_mobile']);
            $table->dropColumn('registration_mobile');
        });
    }
};
