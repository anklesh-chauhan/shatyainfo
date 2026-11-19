<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sgip_violations DROP CONSTRAINT sgip_violations_violation_type_check');
        } else {
            Schema::table('sgip_violations', fn (Blueprint $table) => $table->string('violation_type')->change());
        }

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
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sgip_violations ADD CONSTRAINT sgip_violations_violation_type_check
                CHECK (violation_type IN ('quantity', 'value'))");
        } else {
            Schema::table('sgip_violations', fn (Blueprint $table) => $table->enum('violation_type', ['quantity', 'value'])->change());
        }

        Schema::table('sgip_violations', function (Blueprint $table) {
            $table->foreign('sgip_limit_id')
                ->references('id')
                ->on('sgip_limits')
                ->cascadeOnDelete();
        });
    }
};
