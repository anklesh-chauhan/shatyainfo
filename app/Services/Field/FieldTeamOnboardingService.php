<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\FieldUserHq;
use App\Models\LocationMaster;
use App\Models\User;
use App\Services\CompanyLaunchService;
use Illuminate\Support\Facades\Validator;

class FieldTeamOnboardingService
{
    public const PERMISSION = 'Manage:FieldTeam';

    public static function canManage(?User $user): bool
    {
        return (bool) ($user && ($user->can(self::PERMISSION) || $user->hasAnyRole(['super_admin', 'administration_admin'])));
    }

    public function save(User $actor, array $input): array
    {
        abort_unless(self::canManage($actor), 403);
        $data = Validator::make($input, [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'location_master_id' => [app(CompanyLaunchService::class)->requiresHq() ? 'required' : 'nullable', 'integer', 'exists:location_masters,id'],
            'account_ids' => ['present', 'array', 'max:500'],
            'account_ids.*' => ['integer', 'distinct'],
            'calls_per_month' => ['required', 'integer', 'between:0,1000'],
        ])->validate();

        return $actor->getConnection()->transaction(function () use ($actor, $data): array {
            User::query()->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            if (isset($data['location_master_id'])) {
                LocationMaster::query()->whereKey($data['location_master_id'])->where('is_active', true)->firstOrFail();
            }
            $accounts = AccountMaster::query()->applyVisibility('AccountMaster')->whereIn('id', $data['account_ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($accounts->count() === count($data['account_ids']), 404, 'Select customers available in your visibility.');
            foreach ($accounts as $account) {
                abort_unless($actor->can('update', $account), 403);
            }
            $hq = FieldUserHq::query()->where('user_id', $data['user_id'])->first();
            if (isset($data['location_master_id'])) {
                $hq = FieldUserHq::query()->updateOrCreate(['user_id' => $data['user_id']], [
                    'location_master_id' => $data['location_master_id'], 'updated_by' => $actor->id,
                ]);
            }
            foreach ($accounts as $account) {
                $account->update(['owner_id' => $data['user_id']]);
                if (! $account->callTargets()->where('effective_from', '<=', today()->endOfMonth())
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()->startOfMonth()))->exists()) {
                    $nextTarget = $account->callTargets()->where('effective_from', '>', today()->endOfMonth())->orderBy('effective_from')->first();
                    $account->callTargets()->create(['calls_per_month' => $data['calls_per_month'], 'effective_from' => today()->startOfMonth(),
                        'effective_to' => $nextTarget?->effective_from->copy()->subDay()]);
                }
            }

            return ['hq_id' => $hq?->id, 'assigned_customers' => $accounts->count()];
        });
    }
}
