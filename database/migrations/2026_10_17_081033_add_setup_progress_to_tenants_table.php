<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->unsignedTinyInteger('provisioning_progress')->nullable();
            $table->string('provisioning_step')->nullable();
            $table->unsignedTinyInteger('master_setup_progress')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['provisioning_progress', 'provisioning_step', 'master_setup_progress']);
        });
    }
};
