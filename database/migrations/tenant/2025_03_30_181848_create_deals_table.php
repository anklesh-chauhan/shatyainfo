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
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code')->unique();
            $table->string('deal_name');
            $table->date('transaction_date');
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('contact_detail_id')->nullable()->constrained('contact_details')->onDelete('set null');
            $table->foreignId('account_master_id')->nullable()->constrained('account_masters')->onDelete('set null');
            $table->foreignId('address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->nullableMorphs('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('expected_revenue', 15, 2);
            $table->date('expected_close_date');
            $table->foreignId('lead_source_id')->nullable()->constrained()->onDelete('set null');
            $table->text('description')->nullable();
            $table->morphs('status'); // Polymorphic status columns
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
