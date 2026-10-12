<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('company_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('contact_details_id')->nullable()->constrained('contact_details')->onDelete('set null');
            $table->foreignId('region_id')->nullable()->constrained('regions')->onDelete('set null');
            $table->foreignId('company_master_type_id')->nullable()->constrained('company_master_types')->onDelete('set null');
            $table->string('vendor_code')->nullable();
            $table->string('company_code')->nullable();
            $table->foreignId('address_id')->nullable()->constrained('addresses')->onDelete('set null');
            $table->foreignId('dealer_name_id')->nullable()->constrained('contact_details')->onDelete('set null');
            $table->decimal('commission', 10, 2)->nullable();
            $table->nullableMorphs('category'); // Polymorphic relation for Category
            $table->nullableMorphs('typeable');
            $table->timestamps();
            $table->blameable();
            $table->blameableSoftDeletes();
        });

        // Pivot Table for Multiple Contact Details
        Schema::create('company_master_contact_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_master_id')->constrained('company_masters')->onDelete('cascade');
            $table->foreignId('contact_detail_id')->constrained('contact_details')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('company_master_contact_details');
        Schema::dropIfExists('company_masters');
    }
};
