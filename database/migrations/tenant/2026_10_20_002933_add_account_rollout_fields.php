<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('type_masters', fn (Blueprint $table) => $table->boolean('creates_company')->default(false));
        Schema::table('rating_types', fn (Blueprint $table) => $table->boolean('is_lifecycle')->default(false));
        Schema::table('account_masters', function (Blueprint $table): void {
            $table->foreignId('account_status_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('legacy_rating_type_id')->nullable();
        });
        DB::table('account_masters')->orderBy('id')->chunkById(200, function ($accounts): void {
            foreach ($accounts as $account) {
                DB::table('account_masters')->where('id', $account->id)->update(['account_code' => 'ACC-'.str_pad((string) $account->id, 8, '0', STR_PAD_LEFT)]);
            }
        });
        Schema::table('account_masters', fn (Blueprint $table) => $table->unique('account_code'));
        foreach (['prospect' => 'Prospect', 'active' => 'Active', 'inactive' => 'Inactive', 'closed' => 'Closed', 'acquired' => 'Acquired', 'market_failed' => 'Market Failed', 'project_cancelled' => 'Project Cancelled', 'shut_down' => 'Shut Down'] as $code => $name) {
            $id = DB::table('account_statuses')->insertGetId(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            $ratings = DB::table('rating_types')->where('name', $name)->pluck('id');
            DB::table('rating_types')->whereIn('id', $ratings)->update(['is_lifecycle' => true]);
            foreach ($ratings as $rating) {
                DB::table('account_masters')->where('rating_type_id', $rating)->update(['account_status_id' => $id, 'legacy_rating_type_id' => $rating, 'rating_type_id' => null]);
            }
        }
    }

    public function down(): void
    {
        DB::table('account_masters')->whereNull('rating_type_id')->whereNotNull('legacy_rating_type_id')->update(['rating_type_id' => DB::raw('legacy_rating_type_id')]);
        Schema::table('account_masters', function (Blueprint $table): void {
            $table->dropUnique(['account_code']);
            $table->dropConstrainedForeignId('account_status_id');
            $table->dropColumn('legacy_rating_type_id');
        });
        Schema::table('rating_types', fn (Blueprint $table) => $table->dropColumn('is_lifecycle'));
        Schema::table('type_masters', fn (Blueprint $table) => $table->dropColumn('creates_company'));
        Schema::dropIfExists('account_statuses');
    }
};
