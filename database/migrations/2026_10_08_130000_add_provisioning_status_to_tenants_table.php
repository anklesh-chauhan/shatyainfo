<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('provisioning_status', 32)->default('ready');
            $table->text('provisioning_error')->nullable();
            $table->text('provisioning_owner')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'provisioning_status',
                'provisioning_error',
                'provisioning_owner',
            ]);
        });
    }
};
