<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->string('master_setup_status', 20)->default('ready');
            $table->string('master_setup_step')->nullable();
            $table->text('master_setup_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['master_setup_status', 'master_setup_step', 'master_setup_error']);
        });
    }
};
