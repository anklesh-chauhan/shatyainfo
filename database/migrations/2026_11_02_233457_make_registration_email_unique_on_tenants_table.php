<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('multitenancy.landlord_database_connection_name', 'mysql');
        $hasDuplicateEmails = DB::connection($connection)->table('tenants')
            ->selectRaw('LOWER(TRIM(registration_email))')
            ->whereNotNull('registration_email')
            ->whereRaw("TRIM(registration_email) <> ''")
            ->groupByRaw('LOWER(TRIM(registration_email))')
            ->havingRaw('COUNT(*) > 1')
            ->first() !== null;

        if ($hasDuplicateEmails) {
            throw new RuntimeException('Resolve duplicate tenant registration emails before adding the unique index.');
        }

        DB::connection($connection)->table('tenants')
            ->whereNotNull('registration_email')
            ->whereRaw("TRIM(registration_email) = ''")
            ->update(['registration_email' => null]);

        DB::connection($connection)->table('tenants')
            ->whereNotNull('registration_email')
            ->update(['registration_email' => DB::raw('LOWER(TRIM(registration_email))')]);

        Schema::connection($connection)->table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['registration_email']);
            $table->unique('registration_email');
        });
    }

    public function down(): void
    {
        Schema::connection(config('multitenancy.landlord_database_connection_name', 'mysql'))->table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['registration_email']);
            $table->index('registration_email');
        });
    }
};
