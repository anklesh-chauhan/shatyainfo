<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_segments', function (Blueprint $table): void {
            $table->string('import_reference', 100)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('travel_segments', function (Blueprint $table): void {
            $table->dropUnique(['import_reference']);
            $table->dropColumn('import_reference');
        });
    }
};
