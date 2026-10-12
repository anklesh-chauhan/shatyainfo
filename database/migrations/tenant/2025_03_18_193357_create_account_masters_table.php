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
        Schema::create('account_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('type_master_id')->constrained('type_masters')->onDelete('cascade');
            $table->string('name');
            $table->string('account_code')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->string('secondary_email')->nullable();
            $table->string('website')->nullable();
            $table->string('no_of_employees')->nullable();
            $table->string('twitter')->nullable();
            $table->string('linked_in')->nullable();
            $table->string('annual_revenue')->nullable();
            $table->string('sic_code')->nullable();
            $table->string('ticker_symbol')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('industry_type_id')->nullable()->constrained('industry_types')->onDelete('set null');
            $table->foreignId('region_id')->nullable()->constrained('regions')->onDelete('set null');
            $table->foreignId('ref_dealer_contact')->nullable()->constrained('contact_details')->onDelete('set null');
            $table->decimal('commission', 10, 2)->nullable();
            $table->nullableMorphs('category'); // Polymorphic relation for Category
            $table->nullableMorphs('typeable');
            $table->string('alias')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('account_masters')->onDelete('cascade');
            $table->foreignId('rating_type_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('account_ownership_id')->nullable()->constrained('account_ownerships')->onDelete('set null');
            $table->string('approval_status')->default('draft');
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();
        });

        // Pivot Table for Multiple Contact Details
        Schema::create('account_master_contact_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_master_id')->constrained('account_masters')->onDelete('cascade');
            $table->foreignId('contact_detail_id')->constrained('contact_details')->onDelete('cascade');
            $table->timestamps();
        });

        // Pivot Table for Multiple Address Details
        Schema::create('account_master_address_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_master_id')->constrained('account_masters')->onDelete('cascade');
            $table->foreignId('address_id')->constrained('addresses')->onDelete('cascade');
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_master_address_details');
        Schema::dropIfExists('account_master_contact_details');
        Schema::dropIfExists('account_masters');
    }
};
