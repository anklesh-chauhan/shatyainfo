<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_reporting_masters', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32)->index();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_monetary')->default(false);
            $table->timestamps();
        });
        Schema::table('type_masters', fn (Blueprint $table) => $table->json('activation_requirements')->nullable());
        Schema::table('account_masters', function (Blueprint $table): void {
            $table->foreignId('specialty_id')->nullable()->constrained('account_reporting_masters')->restrictOnDelete();
            $table->foreignId('therapy_area_id')->nullable()->constrained('account_reporting_masters')->restrictOnDelete();
            $table->foreignId('institution_account_id')->nullable()->constrained('account_masters')->restrictOnDelete();
            $table->foreignId('supplier_account_id')->nullable()->constrained('account_masters')->restrictOnDelete();
        });
        Schema::create('account_division_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_master_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_ou_id')->nullable()->constrained('organizational_units')->restrictOnDelete();
            $table->foreignId('rating_type_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('potential_value', 18, 2)->nullable();
            $table->foreignId('potential_unit_id')->nullable()->constrained('account_reporting_masters')->restrictOnDelete();
            $table->string('potential_period', 16)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('assessed_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['account_master_id', 'division_ou_id', 'effective_from'], 'account_profile_period');
        });
        Schema::create('account_call_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_master_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('calls_per_month');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['account_master_id', 'effective_from']);
        });
        Schema::create('account_assignment_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_master_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained()->nullOnDelete();
            $table->json('patch_ids');
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['account_master_id', 'effective_from'], 'account_assignment_period');
        });
        DB::table('account_masters')->orderBy('id')->chunkById(200, function ($accounts): void {
            foreach ($accounts as $account) {
                DB::table('account_assignment_histories')->insert([
                    'account_master_id' => $account->id, 'owner_id' => $account->owner_id,
                    'territory_id' => $account->territory_id,
                    'patch_ids' => json_encode(DB::table('account_master_patch')->where('account_master_id', $account->id)->orderBy('patch_id')->pluck('patch_id')->all()),
                    'effective_from' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_assignment_histories');
        Schema::dropIfExists('account_call_targets');
        Schema::dropIfExists('account_division_profiles');
        Schema::table('account_masters', function (Blueprint $table): void {
            foreach (['specialty_id', 'therapy_area_id', 'institution_account_id', 'supplier_account_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
        });
        Schema::table('type_masters', fn (Blueprint $table) => $table->dropColumn('activation_requirements'));
        Schema::dropIfExists('account_reporting_masters');
    }
};
