<?php

namespace App\Services\DataImport;

use App\Models\AccountMaster;
use App\Models\AccountOwnership;
use App\Models\AccountReportingMaster;
use App\Models\AccountStatus;
use App\Models\Address;
use App\Models\CityPinCode;
use App\Models\ContactDetail;
use App\Models\IndustryType;
use App\Models\OrganizationalUnit;
use App\Models\Patch;
use App\Models\RatingType;
use App\Models\Region;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\AccountActivationService;
use App\Services\AccountOnboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AccountImportRow
{
    private function match(Builder $query, string $label): int
    {
        $matches = $query->limit(2)->get();
        if ($matches->count() !== 1) {
            throw new ImportRowException($label.' must match exactly one existing record. Check the reference sheets.');
        }

        return $matches->first()->id;
    }

    public function resolve(array $row, array $references): array
    {
        $rules = array_fill_keys(AccountOnboardingService::COLUMNS, ['nullable', 'string', 'max:255']);
        $rules['external_reference'] = ['required', 'string', 'max:128', 'regex:/^[A-Z0-9][A-Z0-9_.:\/-]*$/'];
        foreach (['name', 'account_type', 'owner_email'] as $field) {
            $rules[$field] = ['required', 'string', 'max:255'];
        }
        $rules['owner_email'][] = 'email';
        $rules['email'][] = 'email';
        $rules['secondary_email'][] = 'email';
        $rules['ref_contact_email'][] = 'email';
        foreach (['website', 'twitter', 'linked_in'] as $field) {
            $rules[$field][] = 'url:http,https';
        }
        $rules['description'] = ['nullable', 'string', 'max:10000'];
        $rules['phone_number'] = ['nullable', 'string', 'max:30'];
        $rules['patch_codes'] = ['nullable', 'string', 'max:5000'];
        $rules['commission'] = ['nullable', 'numeric', 'between:0,100'];
        $rules['potential_value'] = ['nullable', 'required_with:potential_unit_code,potential_period,currency,assessed_on', 'numeric', 'min:0', 'max:9999999999999999.99'];
        $rules['potential_unit_code'] = ['nullable', 'required_with:potential_value', 'string'];
        $rules['potential_period'] = ['nullable', 'required_with:potential_value', Rule::in(['monthly', 'quarterly', 'annual'])];
        $rules['currency'] = ['nullable', 'regex:/^[A-Z]{3}$/'];
        $rules['assessed_on'] = ['nullable', 'required_with:potential_value', 'date_format:Y-m-d', 'before_or_equal:today'];
        $rules['segment'] = ['nullable', 'required_with:division_code,profile_from,profile_to,potential_value'];
        $rules['profile_from'] = ['nullable', 'required_with:segment', 'date_format:Y-m-d'];
        $rules['profile_to'] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:profile_from'];
        $rules['calls_per_month'] = ['nullable', 'required_with:target_from,target_to', 'integer', 'between:0,1000'];
        $rules['target_from'] = ['nullable', 'required_with:calls_per_month', 'date_format:Y-m-d'];
        $rules['target_to'] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:target_from'];
        foreach (['street', 'address_type', 'city', 'area', 'pin_code'] as $field) {
            $rules[$field] = ['nullable', 'required_with:street,address_type,city,area,pin_code', 'string', 'max:255'];
        }
        Validator::make($row, $rules)->validate();
        $account = AccountMaster::withTrashed()->where('external_reference', $row['external_reference'])->first();
        if ($account?->trashed()) {
            throw new ImportRowException('This external reference belongs to a deleted account. Restore it in Accounts first.');
        }
        if ($account?->approval_status === 'approved' && ! auth()->user()->can('OverrideApproval:AccountMaster')) {
            throw new ImportRowException('This approved account cannot be updated without approval override permission.');
        }
        $attributes = [
            'name' => $row['name'],
            'type_master_id' => $this->match(TypeMaster::where('typeable_type', AccountMaster::class)->where('name', $row['account_type']), 'Account type '.$row['account_type']),
            'owner_id' => $this->match(User::where('email', $row['owner_email']), 'Owner email '.$row['owner_email']),
        ];
        if ($row['account_subtype'] !== '') {
            $attributes['type_master_id'] = $this->match(TypeMaster::where('typeable_type', AccountMaster::class)->where('parent_id', $attributes['type_master_id'])->where('name', $row['account_subtype']), 'Account subtype');
        }
        foreach (['industry' => [IndustryType::class, 'industry_type_id'], 'region' => [Region::class, 'region_id'], 'account_ownership' => [AccountOwnership::class, 'account_ownership_id'], 'ref_contact_email' => [ContactDetail::class, 'ref_dealer_contact']] as $column => [$model, $field]) {
            if ($row[$column] !== '') {
                $attributes[$field] = $this->match($model::where($column === 'ref_contact_email' ? 'email' : 'name', $row[$column]), $column);
            }
        }
        foreach (['phone_number', 'email', 'commission', 'alias', 'secondary_email', 'website', 'no_of_employees', 'twitter', 'linked_in', 'annual_revenue', 'sic_code', 'ticker_symbol', 'description'] as $field) {
            if ($row[$field] !== '') {
                $attributes[$field] = $row[$field];
            }
        }
        if ($row['territory_code'] !== '') {
            $attributes['territory_id'] = $this->match(Territory::where('code', $row['territory_code']), 'Territory code '.$row['territory_code']);
        }
        if ($row['rating'] !== '') {
            $attributes['rating_type_id'] = $this->match(RatingType::where('name', $row['rating'])->where('is_lifecycle', false), 'Potential rating '.$row['rating']);
        }
        foreach (['specialty' => 'specialty_id', 'therapy_area' => 'therapy_area_id'] as $kind => $field) {
            if ($row[$kind.'_code'] !== '') {
                $attributes[$field] = $this->match(AccountReportingMaster::where('code', $row[$kind.'_code'])->where('kind', $kind)->where('is_active', true), $kind.' code '.$row[$kind.'_code']);
            }
        }
        $status = $row['status_code'] !== '' ? $this->match(AccountStatus::where('code', $row['status_code']), 'Status code') : ($account ? $account->account_status_id : $this->match(AccountStatus::where('code', 'prospect'), 'Prospect status'));
        $patches = [];
        if ($row['patch_codes'] !== '') {
            foreach (array_unique(array_map('trim', explode(';', $row['patch_codes']))) as $code) {
                $patches[] = $this->match(Patch::where('code', $code), 'Patch code '.$code);
            }
        }
        $address = [];
        if ($row['street'] !== '') {
            $area = CityPinCode::findOrFail($this->match(CityPinCode::where('area_town', $row['area'])->where('pin_code', $row['pin_code'])->whereHas('city', fn ($query) => $query->where('name', $row['city'])), 'City / area / PIN'));
            if ($account && $account->addresses()->where('is_primary', true)->count() > 1) {
                throw new ImportRowException('Multiple primary addresses exist. Correct these in Accounts first.');
            }
            $address = ['street' => $row['street'], 'type_master_id' => $this->match(TypeMaster::where('typeable_type', Address::class)->where('name', $row['address_type']), 'Address type'), 'area_town_id' => $area->id, 'pin_code' => $area->pin_code, 'city_id' => $area->city_id, 'state_id' => $area->city?->state_id, 'country_id' => $area->city?->country_id, 'is_primary' => true];
        }
        foreach (['parent_reference', 'institution_reference', 'supplier_reference'] as $field) {
            if ($row[$field] === '') {
                continue;
            }
            if ($row[$field] === $row['external_reference']) {
                throw new ImportRowException('An account cannot reference itself in '.$field.'.');
            }
            if (! in_array($row[$field], $references, true)) {
                $this->match(AccountMaster::where('external_reference', $row[$field]), $field.' '.$row[$field]);
            }
        }
        $profile = [];
        if ($row['segment'] !== '') {
            $profile = ['rating_type_id' => $this->match(RatingType::where('name', $row['segment'])->where('is_lifecycle', false), 'Segment'), 'division_ou_id' => $row['division_code'] === '' ? null : $this->match(OrganizationalUnit::where('code', $row['division_code'])->where('is_active', true)->whereHas('typeMaster', fn ($query) => $query->where('name', 'Division')->where('typeable_type', OrganizationalUnit::class)), 'Active division code'), 'effective_from' => $row['profile_from'], 'effective_to' => $row['profile_to'] ?: null];
            if ($row['potential_value'] !== '') {
                $unit = AccountReportingMaster::findOrFail($this->match(AccountReportingMaster::where('kind', 'potential_unit')->where('code', $row['potential_unit_code'])->where('is_active', true), 'Potential unit code'));
                if ($unit->is_monetary && $row['currency'] === '') {
                    throw new ImportRowException('Monetary potential requires a three-letter currency code.');
                }
                $profile += ['potential_value' => $row['potential_value'], 'potential_unit_id' => $unit->id, 'potential_period' => $row['potential_period'], 'currency' => $unit->is_monetary ? $row['currency'] : null, 'assessed_on' => $row['assessed_on']];
            }
            if ($account) {
                if ($row['profile_to'] === '') {
                    $profile['effective_to'] = $account->divisionProfiles()->where('division_ou_id', $profile['division_ou_id'])->whereDate('effective_from', $profile['effective_from'])->first()?->effective_to?->toDateString();
                }
                $this->checkOverlap($account->divisionProfiles()->where('division_ou_id', $profile['division_ou_id'])->getQuery(), $profile, 'Division profile');
            }
        }
        $target = [];
        if ($row['calls_per_month'] !== '') {
            $target = ['calls_per_month' => (int) $row['calls_per_month'], 'effective_from' => $row['target_from'], 'effective_to' => $row['target_to'] ?: null];
            if (CarbonImmutable::parse($row['target_from'])->day !== 1 || ($row['target_to'] !== '' && ! CarbonImmutable::parse($row['target_to'])->isLastOfMonth())) {
                throw new ImportRowException('Targets must start on the first day and end on the last day of a month.');
            }
            if ($account) {
                if ($row['target_to'] === '') {
                    $target['effective_to'] = $account->callTargets()->whereDate('effective_from', $target['effective_from'])->first()?->effective_to?->toDateString();
                }
                $this->checkOverlap($account->callTargets()->getQuery(), $target, 'Call target');
            }
        }
        if (AccountStatus::find($status)?->code === 'active') {
            $proposed = array_replace($account?->getAttributes() ?? [], $attributes);
            $checks = ['territory' => filled($proposed['territory_id'] ?? null), 'patch' => $patches !== [] || ($account?->patches()->exists() ?? false), 'primary_address' => $address !== [] || ($account?->addresses()->where('is_primary', true)->whereNotNull('street')->where('street', '<>', '')->exists() ?? false), 'contact' => filled($proposed['phone_number'] ?? null) || filled($proposed['email'] ?? null), 'specialty' => filled($proposed['specialty_id'] ?? null), 'therapy_area' => filled($proposed['therapy_area_id'] ?? null), 'rating' => filled($proposed['rating_type_id'] ?? null)];
            foreach (TypeMaster::find($attributes['type_master_id'])->activation_requirements ?? [] as $requirement) {
                if (! ($checks[$requirement] ?? false)) {
                    throw new ImportRowException('Active status requires '.(AccountActivationService::REQUIREMENTS[$requirement] ?? $requirement).'. Complete the row or use prospect.');
                }
            }
        }
        $similar = ! $account && AccountMaster::where('name', $row['name'])->exists();

        return ['id' => $account?->id, 'attributes' => $attributes, 'status' => $status, 'patches' => $patches, 'address' => $address, 'profile' => $profile, 'target' => $target,
            'details' => app(AccountImportDetails::class)->resolve($row, $account, $address),
            'warning' => $similar ? 'An account with this name already exists. This new external reference will create a separate account; review before importing.' : null];
    }

    private function checkOverlap(Builder $query, array $period, string $label): void
    {
        if ($query->where('effective_from', '<>', $period['effective_from'])->where('effective_from', '<=', $period['effective_to'] ?? '9999-12-31')->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $period['effective_from']))->exists()) {
            throw new ImportRowException($label.' overlaps an existing period. End the existing period in Accounts first.');
        }
    }
}
