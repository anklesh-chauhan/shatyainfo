<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->foreignId('approver_user_id')->nullable()->after('job_role_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_user_id');
        });
    }
};
