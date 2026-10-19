<?php

use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\UpdateAccountRequest;
use App\Models\AccountMaster;
use App\Models\AccountStatus;
use App\Models\Company;
use App\Models\RatingType;
use App\Models\TypeMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

it('assigns unique account codes across similarly named types and creates companies only when configured', function () {
    $user = User::factory()->create();
    $doctor = TypeMaster::create(['name' => 'Doctor', 'typeable_type' => AccountMaster::class]);
    $unconfigured = TypeMaster::create(['name' => 'Unconfigured', 'typeable_type' => AccountMaster::class]);
    $dealer = TypeMaster::create(['name' => 'Dealer', 'typeable_type' => AccountMaster::class, 'creates_company' => true]);
    $first = AccountMaster::create(['name' => 'Person', 'owner_id' => $user->id, 'type_master_id' => $doctor->id]);
    $second = AccountMaster::create(['name' => 'Business', 'owner_id' => $user->id, 'type_master_id' => $dealer->id]);
    $third = AccountMaster::create(['name' => 'No company', 'owner_id' => $user->id, 'type_master_id' => $unconfigured->id]);
    expect($first->account_code)->toBe('ACC-'.str_pad((string) $first->id, 8, '0', STR_PAD_LEFT))
        ->not->toBe($second->account_code)
        ->and(Company::where('account_master_id', $first->id)->exists())->toBeFalse()
        ->and(Company::where('account_master_id', $third->id)->exists())->toBeFalse()
        ->and(Company::where('account_master_id', $second->id)->exists())->toBeTrue();
    $code = $first->account_code;
    $first->update(['type_master_id' => $dealer->id]);
    expect($first->fresh()->account_code)->toBe($code);
});

it('rejects lifecycle ratings and invalid commission percentages through both api requests', function () {
    $rating = RatingType::create(['name' => 'Active']);
    $rating->forceFill(['is_lifecycle' => true])->saveQuietly();
    foreach ([new StoreAccountRequest, new UpdateAccountRequest] as $request) {
        $rules = $request->rules();
        expect(Validator::make(['rating_type_id' => $rating->id, 'commission' => 101], array_intersect_key($rules, array_flip(['rating_type_id', 'commission'])))->fails())->toBeTrue();
        expect(Validator::make(['commission' => 5.25, 'account_status_id' => AccountStatus::firstOrFail()->id], array_intersect_key($rules, array_flip(['commission', 'account_status_id'])))->passes())->toBeTrue();
    }
});

it('migrates lifecycle ratings without losing their meaning and rebuilds duplicate codes', function () {
    $migration = require database_path('migrations/tenant/2026_10_20_002933_add_account_rollout_fields.php');
    $migration->down();
    $user = User::factory()->create();
    $type = TypeMaster::create(['name' => 'Doctor', 'typeable_type' => AccountMaster::class]);
    $rating = RatingType::create(['name' => 'Active']);
    $ids = [];
    foreach (['One', 'Two'] as $name) {
        $ids[] = DB::table('account_masters')->insertGetId(['name' => $name, 'owner_id' => $user->id, 'type_master_id' => $type->id, 'rating_type_id' => $rating->id, 'account_code' => 'D-1']);
    }
    $migration->up();
    foreach ($ids as $id) {
        $account = AccountMaster::findOrFail($id);
        expect($account->accountStatus->code)->toBe('active')
            ->and($account->rating_type_id)->toBeNull()
            ->and($account->legacy_rating_type_id)->toBe($rating->id)
            ->and($account->account_code)->toBe('ACC-'.str_pad((string) $id, 8, '0', STR_PAD_LEFT));
    }
});
