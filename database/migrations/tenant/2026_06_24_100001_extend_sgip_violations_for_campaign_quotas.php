<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sgip_violations', function (Blueprint $table) {
            $table->dropForeign(['sgip_limit_id']);
        });

        Schema::table('sgip_violations', function (Blueprint $table) {
            $table->unsignedBigInteger('sgip_limit_id')->nullable()->change();
            $table->string('violation_type')->change();
        });

        Schema::table('sgip_violations', function (Blueprint $table) {
            $table->foreign('sgip_limit_id')
                ->references('id')
                ->on('sgip_limits')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sgip_violations', function (Blueprint $table) {
            $table->dropForeign(['sgip_limit_id']);
        });

        Schema::table('sgip_violations', function (Blueprint $table) {
            $table->unsignedBigInteger('sgip_limit_id')->nullable(false)->change();
            $table->enum('violation_type', ['quantity', 'value'])->change();
        });

        Schema::table('sgip_violations', function (Blueprint $table) {
            $table->foreign('sgip_limit_id')
                ->references('id')
                ->on('sgip_limits')
                ->cascadeOnDelete();
        });
    }
};
