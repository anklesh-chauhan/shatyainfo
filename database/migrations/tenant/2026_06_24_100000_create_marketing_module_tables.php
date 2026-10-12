<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotional_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('scheme_type');
            $table->string('status')->default('draft');
            $table->string('applies_to')->default('global');
            $table->unsignedBigInteger('applies_to_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->decimal('min_order_value', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            // $table->index(['status', 'valid_from', 'valid_to']);
        });

        Schema::create('promotional_scheme_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotional_scheme_id')->constrained()->cascadeOnDelete();
            $table->string('benefit_type');
            $table->foreignId('item_master_id')->nullable()->constrained('item_masters')->nullOnDelete();
            $table->decimal('buy_quantity', 12, 3)->nullable();
            $table->decimal('get_quantity', 12, 3)->nullable();
            $table->decimal('discount_value', 12, 4)->nullable();
            $table->decimal('min_quantity', 12, 3)->nullable();
            $table->decimal('max_quantity', 12, 3)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_number')->unique();
            $table->string('name');
            $table->foreignId('promotional_scheme_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_budget', 14, 2)->nullable();
            $table->text('description')->nullable();
            $table->blameable();
            $table->blameableSoftDeletes();
            $table->timestamps();

            // $table->index(['status', 'start_date', 'end_date']);
        });

        Schema::create('marketing_campaign_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_master_id')->constrained('item_masters')->cascadeOnDelete();
            $table->decimal('total_quota', 12, 3)->default(0);
            $table->decimal('unit_value', 12, 2)->nullable();
            $table->timestamps();

            // $table->unique(['marketing_campaign_id', 'item_master_id']);
        });

        Schema::create('marketing_campaign_territory_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id');
            $table->foreignId('territory_id');
            $table->foreignId('item_master_id');

            $table->decimal('quota_quantity', 12, 3)->default(0);
            $table->decimal('used_quantity', 12, 3)->default(0);

            $table->timestamps();

            $table->foreign('marketing_campaign_id', 'mc_tq_campaign_fk')
                ->references('id')
                ->on('marketing_campaigns')
                ->cascadeOnDelete();

            $table->foreign('territory_id', 'mc_tq_territory_fk')
                ->references('id')
                ->on('territories')
                ->cascadeOnDelete();

            $table->foreign('item_master_id', 'mc_tq_item_fk')
                ->references('id')
                ->on('item_masters')
                ->cascadeOnDelete();

            // $table->unique(['marketing_campaign_id', 'territory_id', 'item_master_id'], 'campaign_territory_item_unique');
        });

        Schema::table('sample_requests', function (Blueprint $table) {
            $table->foreign('campaign_id')
                ->references('id')
                ->on('marketing_campaigns')
                ->nullOnDelete();
        });

        Schema::table('sgip_distributions', function (Blueprint $table) {
            $table->foreignId('marketing_campaign_id')
                ->nullable()
                ->after('sample_issue_id')
                ->constrained('marketing_campaigns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sgip_distributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketing_campaign_id');
        });

        Schema::table('sample_requests', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
        });

        Schema::dropIfExists('marketing_campaign_territory_quotas');
        Schema::dropIfExists('marketing_campaign_items');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('promotional_scheme_benefits');
        Schema::dropIfExists('promotional_schemes');
    }
};
