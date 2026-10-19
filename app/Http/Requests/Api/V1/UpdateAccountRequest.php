<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountMasterCreditType;
use App\Models\AccountMaster;
use App\Traits\AccountReportingRequestRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    use AccountReportingRequestRules;

    public function authorize(): bool
    {
        $accountId = (int) $this->route('account');

        $account = AccountMaster::query()->find($accountId);

        if (! $account) {
            return false;
        }

        return $this->user()?->can('update', $account) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->accountReportingRules(),
            'name' => ['sometimes', 'string', 'max:255'],
            'type_master_id' => ['sometimes', 'integer', 'exists:type_masters,id'],
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
            'rating_type_id' => ['nullable', 'integer', Rule::exists('rating_types', 'id')->where('is_lifecycle', false)->whereNull('deleted_at')],
            'account_status_id' => ['nullable', 'integer', Rule::exists('account_statuses', 'id')->whereNull('deleted_at')],
            'parent_id' => ['nullable', 'integer', 'exists:account_masters,id'],
            'ref_dealer_contact' => ['nullable', 'integer', 'exists:contact_details,id'],
            'commission' => ['nullable', 'numeric', 'between:0,100'],
            'description' => ['nullable', 'string'],
            'patch_ids' => ['sometimes', 'nullable', 'array'],
            'patch_ids.*' => ['integer', 'exists:patches,id'],
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
            'bank_detail.id' => ['nullable', 'integer', 'exists:bank_details,id'],
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
            'bank_details.*.id' => ['nullable', 'integer', 'exists:bank_details,id'],
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
