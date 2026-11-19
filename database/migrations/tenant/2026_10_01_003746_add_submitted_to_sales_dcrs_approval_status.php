<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            Schema::table('sales_dcrs', fn (Blueprint $table) => $table
                ->enum('approval_status', ['draft', 'pending', 'submitted', 'approved', 'rejected'])->default('draft')->change());

            return;
        }

        DB::statement("ALTER TABLE sales_dcrs DROP CONSTRAINT sales_dcrs_approval_status_check,
            ADD CONSTRAINT sales_dcrs_approval_status_check
            CHECK (approval_status IN ('draft', 'pending', 'submitted', 'approved', 'rejected'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            Schema::table('sales_dcrs', fn (Blueprint $table) => $table
                ->enum('approval_status', ['draft', 'pending', 'approved', 'rejected'])->default('draft')->change());

            return;
        }

        DB::statement("ALTER TABLE sales_dcrs DROP CONSTRAINT sales_dcrs_approval_status_check,
            ADD CONSTRAINT sales_dcrs_approval_status_check
            CHECK (approval_status IN ('draft', 'pending', 'approved', 'rejected'))");
    }
};
