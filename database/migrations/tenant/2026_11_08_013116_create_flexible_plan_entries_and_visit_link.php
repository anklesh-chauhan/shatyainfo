<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flexible_plan_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_plan_id')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('account_master_id')->constrained();
            $table->foreignId('patch_id')->nullable()->constrained();
            $table->date('date');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->json('history');
            $table->timestamps();
            $table->index(['user_id', 'date']);
            $table->unique(['user_id', 'date', 'account_master_id'], 'flexible_customer_day');
        });
        Schema::table('visits', function (Blueprint $table): void {
            $table->foreignId('flexible_plan_entry_id')->nullable()->constrained();
            $table->unsignedInteger('flexible_plan_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('flexible_plan_entry_id');
            $table->dropColumn('flexible_plan_version');
        });
        Schema::dropIfExists('flexible_plan_entries');
    }
};
