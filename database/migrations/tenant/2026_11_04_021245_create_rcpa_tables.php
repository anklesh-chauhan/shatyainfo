<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account_masters', function (Blueprint $table) {
            $table->string('rcpa_tier', 1)->nullable();
        });
        Schema::create('rcpa_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapy_area_id')->constrained('account_reporting_masters')->restrictOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['therapy_area_id', 'rank']);
            $table->timestamps();
        });
        Schema::create('rcpa_observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('chemist_id')->constrained('account_masters')->restrictOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sales_dcr_id')->constrained()->restrictOnDelete();
            $table->foreignId('therapy_area_id')->constrained('account_reporting_masters')->restrictOnDelete();
            $table->foreignId('item_master_id')->constrained()->restrictOnDelete();
            $table->string('chemist_tier', 1);
            $table->date('observed_on')->index();
            $table->string('stock_status', 30);
            $table->decimal('doctor_rx_share', 5, 2);
            $table->decimal('offtake_quantity', 18, 6);
            $table->unsignedTinyInteger('offtake_days');
            $table->json('competitors');
            $table->string('data_flag')->default('ESTIMATED_FIELD');
            $table->text('remarks')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rcpa_observations');
        Schema::dropIfExists('rcpa_competitors');
        Schema::table('account_masters', fn (Blueprint $table) => $table->dropColumn('rcpa_tier'));
    }
};
