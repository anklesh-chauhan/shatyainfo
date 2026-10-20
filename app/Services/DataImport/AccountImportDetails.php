<?php

namespace App\Services\DataImport;

use App\Enums\AccountMasterCreditType;
use App\Models\AccountMaster;
use App\Models\AccountMasterGSTDetail;
use App\Models\BankDetail;
use App\Models\ContactDetail;
use App\Models\Department;
use App\Models\Designation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AccountImportDetails
{
    public const COLUMNS = [
        'contact_first_name', 'contact_last_name', 'contact_salutation', 'contact_email', 'contact_mobile_number', 'contact_whatsapp_number', 'contact_alternate_phone', 'contact_birthday', 'contact_designation', 'contact_department', 'contact_linkedin', 'contact_facebook', 'contact_twitter', 'contact_website', 'contact_notes',
        'bank_name', 'bank_account_number', 'bank_account_name', 'bank_account_ifsc_code', 'bank_account_swift_code', 'bank_account_branch', 'bank_account_iban', 'bank_account_bic', 'bank_account_phone', 'bank_account_email', 'bank_account_address', 'bank_account_city', 'bank_account_state', 'bank_account_country', 'bank_account_zip', 'bank_account_tax_id', 'bank_account_micr_code', 'bank_account_rtgs_code', 'bank_account_ecs_code', 'bank_account_code', 'bank_account_type', 'bank_account_currency', 'bank_account_status', 'bank_remark',
        'credit_type', 'credit_days', 'credit_limit', 'credit_status', 'credit_review_date', 'credit_terms', 'credit_remark',
        'gst_number', 'gst_state_name', 'gst_state_code', 'gst_type', 'gst_status', 'pan_number', 'gst_remark',
        'tan_number', 'cin', 'tds_parameters', 'tds_section', 'tds_rate', 'tds_type', 'tds_status', 'is_tds_deduct', 'is_tds_compulsory', 'tds_remark',
    ];

    public function resolve(array $row, ?AccountMaster $account, array $address): array
    {
        $data = array_filter(array_intersect_key($row, array_flip(self::COLUMNS)), fn ($value): bool => $value !== '');
        $rules = array_fill_keys(self::COLUMNS, ['sometimes', 'string', 'max:255']);
        foreach (['contact_email', 'bank_account_email'] as $field) {
            $rules[$field][] = 'email';
        }
        foreach (['contact_birthday', 'credit_review_date'] as $field) {
            $rules[$field][] = 'date_format:Y-m-d';
        }
        foreach (['contact_linkedin', 'contact_facebook', 'contact_twitter', 'contact_website'] as $field) {
            $rules[$field][] = 'url:http,https';
        }
        $rules['credit_type'][] = Rule::in(AccountMasterCreditType::values());
        $rules['credit_days'] = ['sometimes', 'integer', 'between:0,2147483647'];
        $rules['credit_limit'] = ['sometimes', 'numeric', 'between:0,9999999999999.99'];
        foreach (['is_tds_deduct', 'is_tds_compulsory'] as $field) {
            $rules[$field][] = Rule::in(['yes', 'no', '1', '0']);
        }
        $rules['tds_rate'] = ['sometimes', 'numeric', 'between:0,100'];
        Validator::make($data, $rules)->validate();
        $groups = ['contact' => [], 'bank' => [], 'credit' => [], 'gst' => [], 'statutory' => []];
        foreach ($data as $field => $value) {
            if (str_starts_with($field, 'contact_')) {
                $groups['contact'][substr($field, 8)] = $value;
            } elseif (str_starts_with($field, 'bank_')) {
                $groups['bank'][$field === 'bank_remark' ? 'remark' : $field] = $value;
            } elseif (str_starts_with($field, 'credit_')) {
                $groups['credit'][$field === 'credit_remark' ? 'remark' : $field] = $value;
            } elseif (str_starts_with($field, 'gst_') || $field === 'pan_number') {
                $groups['gst'][match ($field) {
                    'gst_state_name' => 'state_name', 'gst_state_code' => 'state_code', 'gst_remark' => 'remark', default => $field
                }] = $value;
            } else {
                $groups['statutory'][$field] = $value;
            }
        }
        $contact = $groups['contact'];
        if ($contact !== []) {
            Validator::make($contact, ['first_name' => ['required'], 'email' => ['required_without:mobile_number'], 'mobile_number' => ['required_without:email']])->validate();
            foreach (['designation' => Designation::class, 'department' => Department::class] as $field => $model) {
                if (isset($contact[$field])) {
                    $matches = $model::where('name', $contact[$field])->limit(2)->get();
                    if ($matches->count() !== 1) {
                        throw new ImportRowException('Contact '.$field.' must match one existing master.');
                    }
                    $contact[$field.'_id'] = $matches->first()->id;
                    unset($contact[$field]);
                }
            }
            $matches = $account ? $account->contactDetails()->where(function ($query) use ($contact): void {
                if (isset($contact['email'])) {
                    $query->orWhere('email', $contact['email']);
                }
                if (isset($contact['mobile_number'])) {
                    $query->orWhere('mobile_number', $contact['mobile_number']);
                }
            })->get() : collect();
            if ($matches->count() > 1) {
                throw new ImportRowException('Contact email and phone match multiple linked contacts. Resolve them first.');
            }
            if ($matches->first()?->accountMasters()->whereKeyNot($account->id)->exists()) {
                throw new ImportRowException('This contact is shared with another account. Edit it in Contacts first.');
            }
            $groups['contact'] = $contact;
            $groups['contact_id'] = $matches->first()?->id;
            $this->checkApproved($matches->first());
        }
        if ($groups['bank'] !== []) {
            Validator::make($groups['bank'], ['bank_name' => ['required'], 'bank_account_number' => ['required']])->validate();
            $bank = BankDetail::withTrashed()->where('bank_account_number', $groups['bank']['bank_account_number'])->first();
            if ($bank && ($bank->trashed() || ! $account || $bank->bankable_type !== $account->getMorphClass() || (int) $bank->bankable_id !== $account->id)) {
                throw new ImportRowException('Bank account number is already used by another or deleted record.');
            }
            $this->checkApproved($bank);
        }
        if ($groups['credit'] !== []) {
            Validator::make(array_replace($account?->creditDetail?->getAttributes() ?? [], $groups['credit']), ['credit_type' => ['required', Rule::in(AccountMasterCreditType::values())]])->validate();
            $this->checkApproved($account?->creditDetail);
        }
        if ($groups['gst'] !== []) {
            Validator::make(array_replace($account?->gstDetail?->getAttributes() ?? [], $groups['gst']), ['gst_number' => ['required'], 'state_name' => ['required'], 'state_code' => ['required'], 'gst_type' => ['required']])->validate();
            if (isset($groups['gst']['gst_number']) && AccountMasterGSTDetail::withTrashed()->where('gst_number', $groups['gst']['gst_number'])->when($account?->gstDetail, fn ($query) => $query->whereKeyNot($account->gstDetail->id))->exists()) {
                throw new ImportRowException('GST number is already used by another or deleted record.');
            }
            $this->checkApproved($account?->gstDetail);
        }
        if ($groups['statutory'] !== []) {
            $this->checkApproved($account?->statutoryDetail);
        }

        return $groups;
    }

    private function checkApproved(?Model $record): void
    {
        if ($record?->approval_status === 'approved' && ! auth()->user()->can('OverrideApproval:'.class_basename($record))) {
            throw new ImportRowException('An approved related record cannot be changed without override permission.');
        }
    }

    public function save(AccountMaster $account, array $details): void
    {
        foreach (['credit' => 'creditDetail', 'gst' => 'gstDetail', 'statutory' => 'statutoryDetail'] as $key => $relation) {
            if ($details[$key] !== []) {
                if ($key === 'gst') {
                    $address = $account->addresses()->where('is_primary', true)->first();
                    if ($address) {
                        $details[$key]['address_id'] = $address->id;
                    }
                }
                $account->{$relation}()->updateOrCreate([], $details[$key]);
            }
        }
        if ($details['bank'] !== []) {
            $account->bankDetail()->updateOrCreate(['bank_account_number' => $details['bank']['bank_account_number']], $details['bank']);
        }
        if ($details['contact'] !== []) {
            $contact = isset($details['contact_id']) ? $account->contactDetails()->findOrFail($details['contact_id']) : new ContactDetail;
            $contact->fill($details['contact']);
            if (! $contact->exists) {
                $contact->account_master_id = $account->id;
            }
            $contact->save();
            $account->contactDetails()->syncWithoutDetaching([$contact->id]);
        }
    }
}
