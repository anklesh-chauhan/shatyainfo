<?php

namespace App\Services;

use App\Models\AccountMaster;
use App\Models\AccountReportingMaster;
use App\Models\AccountStatus;
use App\Models\TypeMaster;
use Illuminate\Validation\ValidationException;

class AccountActivationService
{
    public const REQUIREMENTS = ['territory' => 'Territory', 'patch' => 'At least one patch', 'primary_address' => 'Primary address', 'contact' => 'Phone or email', 'specialty' => 'Specialty', 'therapy_area' => 'Therapy area', 'rating' => 'Potential rating'];

    public function validateReferences(AccountMaster $account): void
    {
        foreach (['specialty_id' => 'specialty', 'therapy_area_id' => 'therapy_area'] as $field => $kind) {
            if ($account->isDirty($field) && $account->$field && ! AccountReportingMaster::whereKey($account->$field)->where('kind', $kind)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([$field => 'Choose an active '.str_replace('_', ' ', $kind).'.']);
            }
        }
        foreach (['institution_account_id', 'supplier_account_id'] as $field) {
            if ($account->$field && (int) $account->$field === (int) $account->id) {
                throw ValidationException::withMessages([$field => 'An account cannot reference itself.']);
            }
            if ($account->isDirty($field) && $account->$field && auth()->check() && ! AccountMaster::query()->applyVisibility('AccountMaster')->whereKey($account->$field)->exists()) {
                throw ValidationException::withMessages([$field => 'Choose an account you can access.']);
            }
        }
    }

    public function validateActivation(AccountMaster $account): void
    {
        if (! $account->isDirty(['account_status_id', 'type_master_id']) || AccountStatus::whereKey($account->account_status_id)->value('code') !== 'active') {
            return;
        }
        $requirements = TypeMaster::find($account->type_master_id)?->activation_requirements ?? [];
        $checks = [
            'territory' => filled($account->territory_id),
            'patch' => $account->exists && $account->patches()->exists(),
            'primary_address' => $account->exists && $account->addresses()->where('is_primary', true)->whereNotNull('street')->where('street', '<>', '')->exists(),
            'contact' => filled($account->phone_number) || filled($account->email),
            'specialty' => filled($account->specialty_id), 'therapy_area' => filled($account->therapy_area_id), 'rating' => filled($account->rating_type_id),
        ];
        $missing = array_filter($requirements, fn (string $key): bool => ! ($checks[$key] ?? false));
        if ($missing) {
            throw ValidationException::withMessages(['account_status_id' => 'Before activation, complete: '.implode(', ', array_map(fn ($key) => self::REQUIREMENTS[$key] ?? $key, $missing)).'. Save as Prospect first to add addresses and patches.']);
        }
    }
}
