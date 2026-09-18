<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountMasterCreditType;
use App\Models\AccountMaster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AccountMaster::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type_master_id' => ['required', 'integer', 'exists:type_masters,id'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'secondary_email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'no_of_employees' => ['nullable', 'string', 'max:255'],
            'twitter' => ['nullable', 'string', 'max:255'],
            'linked_in' => ['nullable', 'string', 'max:255'],
            'annual_revenue' => ['nullable', 'string', 'max:255'],
            'sic_code' => ['nullable', 'string', 'max:255'],
            'ticker_symbol' => ['nullable', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'territory_id' => ['nullable', 'integer', 'exists:territories,id'],
            'industry_type_id' => ['nullable', 'integer', 'exists:industry_types,id'],
            'rating_type_id' => ['nullable', 'integer', 'exists:rating_types,id'],
            'parent_id' => ['nullable', 'integer', 'exists:account_masters,id'],
            'ref_dealer_contact' => ['nullable', 'integer', 'exists:contact_details,id'],
            'commission' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
            'patch_ids' => ['nullable', 'array'],
            'patch_ids.*' => ['integer', 'exists:patches,id'],
            'contact' => ['nullable', 'array'],
            'contact.salutation' => ['nullable', 'string', 'max:20'],
            'contact.first_name' => ['nullable', 'string', 'max:100'],
            'contact.last_name' => ['nullable', 'string', 'max:100'],
            'contact.mobile_number' => ['nullable', 'string', 'max:30'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'array'],
            'address.type_master_id' => ['required_with:address', 'integer', 'exists:type_masters,id'],
            'address.street' => ['required_with:address', 'string', 'max:255'],
            'address.pin_code' => ['nullable', 'string', 'max:20'],
            'address.area_town_id' => ['required_with:address', 'integer', 'exists:city_pin_codes,id'],
            'address.city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'address.state_id' => ['nullable', 'integer', 'exists:states,id'],
            'address.country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'address.is_primary' => ['nullable', 'boolean'],
            'addresses' => ['nullable', 'array'],
            'addresses.*.type_master_id' => ['required', 'integer', 'exists:type_masters,id'],
            'addresses.*.street' => ['required', 'string', 'max:255'],
            'addresses.*.pin_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.area_town_id' => ['required', 'integer', 'exists:city_pin_codes,id'],
            'addresses.*.city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'addresses.*.state_id' => ['nullable', 'integer', 'exists:states,id'],
            'addresses.*.country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'addresses.*.is_primary' => ['nullable', 'boolean'],
            'gst_detail' => ['nullable', 'array'],
            'gst_detail.address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'gst_detail.gst_number' => ['required_with:gst_detail', 'string', 'max:255'],
            'gst_detail.state_name' => ['nullable', 'string', 'max:255'],
            'gst_detail.state_code' => ['nullable', 'string', 'max:255'],
            'gst_detail.gst_type' => ['nullable', 'string', 'max:255'],
            'gst_detail.gst_status' => ['nullable'],
            'gst_detail.pan_number' => ['nullable', 'string', 'max:255'],
            'gst_detail.remark' => ['nullable', 'string'],
            'credit_detail' => ['nullable', 'array'],
            'credit_detail.credit_type' => ['required_with:credit_detail', Rule::in(AccountMasterCreditType::values())],
            'credit_detail.credit_days' => ['nullable', 'integer', 'min:0'],
            'credit_detail.credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_detail.credit_status' => ['nullable'],
            'credit_detail.credit_review_date' => ['nullable', 'date'],
            'credit_detail.credit_terms' => ['nullable', 'string'],
            'credit_detail.remark' => ['nullable', 'string'],
            'statutory_detail' => ['nullable', 'array'],
            'statutory_detail.tan_number' => ['nullable', 'string', 'max:255'],
            'statutory_detail.cin' => ['nullable', 'string', 'max:255'],
            'statutory_detail.tds_parameters' => ['nullable', 'string', 'max:255'],
            'statutory_detail.tds_section' => ['nullable', 'string', 'max:255'],
            'statutory_detail.tds_rate' => ['nullable', 'string', 'max:255'],
            'statutory_detail.tds_type' => ['nullable', 'string', 'max:255'],
            'statutory_detail.tds_status' => ['nullable'],
            'statutory_detail.is_tds_deduct' => ['nullable', 'string', 'max:255'],
            'statutory_detail.is_tds_compulsory' => ['nullable', 'string', 'max:255'],
            'statutory_detail.tds_remark' => ['nullable', 'string', 'max:255'],
            'bank_detail' => ['nullable', 'array'],
            'bank_detail.bank_name' => ['required_with:bank_detail', 'string', 'max:255'],
            'bank_detail.bank_account_number' => ['required_with:bank_detail', 'string', 'max:255'],
            'bank_detail.bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_detail.bank_account_ifsc_code' => ['nullable', 'string', 'max:255'],
            'bank_detail.bank_account_branch' => ['nullable', 'string', 'max:255'],
            'bank_detail.bank_account_swift_code' => ['nullable', 'string', 'max:255'],
            'bank_detail.bank_account_country' => ['nullable', 'string', 'max:255'],
            'bank_detail.bank_account_currency' => ['nullable', 'string', 'max:255'],
            'bank_detail.bank_account_status' => ['nullable'],
            'bank_detail.is_primary' => ['nullable', 'boolean'],
            'bank_detail.remark' => ['nullable', 'string'],
            'bank_details' => ['nullable', 'array'],
            'bank_details.*.bank_name' => ['required', 'string', 'max:255'],
            'bank_details.*.bank_account_number' => ['required', 'string', 'max:255'],
            'bank_details.*.bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_details.*.bank_account_ifsc_code' => ['nullable', 'string', 'max:255'],
            'bank_details.*.bank_account_branch' => ['nullable', 'string', 'max:255'],
            'bank_details.*.bank_account_country' => ['nullable', 'string', 'max:255'],
            'bank_details.*.bank_account_currency' => ['nullable', 'string', 'max:255'],
            'bank_details.*.bank_account_status' => ['nullable'],
            'bank_details.*.is_primary' => ['nullable', 'boolean'],
            'bank_details.*.remark' => ['nullable', 'string'],
        ];
    }
}
