<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AccountMaster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountMaster
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'account_code' => $this->account_code,
            'alias' => $this->alias,
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'secondary_email' => $this->secondary_email,
            'website' => $this->website,
            'no_of_employees' => $this->no_of_employees,
            'twitter' => $this->twitter,
            'linked_in' => $this->linked_in,
            'annual_revenue' => $this->annual_revenue,
            'sic_code' => $this->sic_code,
            'ticker_symbol' => $this->ticker_symbol,
            'description' => $this->description,
            'commission' => $this->commission,
            'owner_id' => $this->owner_id,
            'type_master_id' => $this->type_master_id,
            'industry_type_id' => $this->industry_type_id,
            'rating_type_id' => $this->rating_type_id,
            'territory_id' => $this->territory_id,
            'parent_id' => $this->parent_id,
            'ref_dealer_contact' => $this->ref_dealer_contact,
            'approval_status' => $this->approval_status,
            'type' => $this->whenLoaded('typeMaster', fn (): ?array => $this->typeMaster ? [
                'id' => $this->typeMaster->id,
                'name' => $this->typeMaster->name,
            ] : null),
            'territory' => $this->whenLoaded('territory', fn (): ?array => $this->territory ? [
                'id' => $this->territory->id,
                'name' => $this->territory->name,
                'code' => $this->territory->code,
            ] : null),
            'patches' => $this->whenLoaded('patches', fn () => $this->patches->map(fn ($patch): array => [
                'id' => $patch->id,
                'name' => $patch->name,
                'code' => $patch->code,
            ])->values()),
            'contacts' => $this->whenLoaded('contactDetails', fn () => $this->contactDetails->map(fn ($contact): array => [
                'id' => $contact->id,
                'salutation' => $contact->salutation,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'mobile_number' => $contact->mobile_number,
                'whatsapp_number' => $contact->whatsapp_number,
                'email' => $contact->email,
            ])->values()),
            'addresses' => $this->whenLoaded('addresses', fn () => AddressResource::collection($this->addresses)),
            'gst_detail' => $this->whenLoaded('gstDetail', fn (): ?array => $this->gstDetail ? [
                'id' => $this->gstDetail->id,
                'address_id' => $this->gstDetail->address_id,
                'gst_number' => $this->gstDetail->gst_number,
                'state_name' => $this->gstDetail->state_name,
                'state_code' => $this->gstDetail->state_code,
                'gst_type' => $this->gstDetail->gst_type,
                'gst_status' => $this->gstDetail->gst_status,
                'pan_number' => $this->gstDetail->pan_number,
                'remark' => $this->gstDetail->remark,
            ] : null),
            'credit_detail' => $this->whenLoaded('creditDetail', fn (): ?array => $this->creditDetail ? [
                'id' => $this->creditDetail->id,
                'credit_type' => $this->creditDetail->credit_type?->value ?? $this->creditDetail->credit_type,
                'credit_days' => $this->creditDetail->credit_days,
                'credit_limit' => $this->creditDetail->credit_limit,
                'credit_status' => $this->creditDetail->credit_status,
                'credit_review_date' => $this->creditDetail->credit_review_date?->toDateString(),
                'credit_terms' => $this->creditDetail->credit_terms,
                'remark' => $this->creditDetail->remark,
            ] : null),
            'statutory_detail' => $this->whenLoaded('statutoryDetail', fn (): ?array => $this->statutoryDetail ? [
                'id' => $this->statutoryDetail->id,
                'tan_number' => $this->statutoryDetail->tan_number,
                'cin' => $this->statutoryDetail->cin,
                'tds_parameters' => $this->statutoryDetail->tds_parameters,
                'tds_section' => $this->statutoryDetail->tds_section,
                'tds_rate' => $this->statutoryDetail->tds_rate,
                'tds_type' => $this->statutoryDetail->tds_type,
                'tds_status' => $this->statutoryDetail->tds_status,
                'is_tds_deduct' => $this->statutoryDetail->is_tds_deduct,
                'is_tds_compulsory' => $this->statutoryDetail->is_tds_compulsory,
                'tds_remark' => $this->statutoryDetail->tds_remark,
            ] : null),
            'bank_details' => $this->whenLoaded('bankDetail', fn () => $this->bankDetail->map(fn ($bank): array => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'bank_account_number' => $bank->bank_account_number,
                'bank_account_name' => $bank->bank_account_name,
                'bank_account_ifsc_code' => $bank->bank_account_ifsc_code,
                'bank_account_branch' => $bank->bank_account_branch,
                'bank_account_swift_code' => $bank->bank_account_swift_code,
                'bank_account_iban' => $bank->bank_account_iban,
                'bank_account_bic' => $bank->bank_account_bic,
                'bank_account_phone' => $bank->bank_account_phone,
                'bank_account_email' => $bank->bank_account_email,
                'bank_account_address' => $bank->bank_account_address,
                'bank_account_city' => $bank->bank_account_city,
                'bank_account_state' => $bank->bank_account_state,
                'bank_account_country' => $bank->bank_account_country,
                'bank_account_zip' => $bank->bank_account_zip,
                'bank_account_tax_id' => $bank->bank_account_tax_id,
                'bank_account_micr_code' => $bank->bank_account_micr_code,
                'bank_account_rtgs_code' => $bank->bank_account_rtgs_code,
                'bank_account_ecs_code' => $bank->bank_account_ecs_code,
                'bank_account_code' => $bank->bank_account_code,
                'bank_account_type' => $bank->bank_account_type,
                'bank_account_currency' => $bank->bank_account_currency,
                'bank_account_status' => $bank->bank_account_status,
                'is_primary' => (bool) $bank->is_primary,
                'remark' => $bank->remark,
            ])->values()),
        ];
    }
}
