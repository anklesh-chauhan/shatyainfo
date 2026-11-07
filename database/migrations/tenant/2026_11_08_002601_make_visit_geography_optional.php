<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->unsignedBigInteger('territory_id')->nullable()->change();
            $table->unsignedBigInteger('patch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->unsignedBigInteger('territory_id')->nullable(false)->change();
            $table->unsignedBigInteger('patch_id')->nullable(false)->change();
        });
    }
};
