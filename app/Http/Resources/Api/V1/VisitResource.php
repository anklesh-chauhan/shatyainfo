<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Visit
 */
class VisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'employee_id' => $this->employee_id,
            'territory_id' => $this->territory_id,
            'patch_id' => $this->patch_id,
            'sales_dcr_id' => $this->sales_dcr_id,
            'sales_tour_plan_detail_id' => $this->sales_tour_plan_detail_id,
            'visit_date' => optional($this->visit_date)?->toDateString(),
            'start_time' => optional($this->start_time)?->toIso8601String(),
            'end_time' => optional($this->end_time)?->toIso8601String(),
            'visit_type' => $this->visit_type,
            'visit_status' => $this->visit_status,
            'reschedule_state' => $this->reschedule_state,
            'rescheduled_for' => optional($this->rescheduled_for)?->toDateString(),
            'cancel_reason' => $this->cancel_reason,
            'workspace' => $this->when($this->relationLoaded('feedbacks'), function (): array {
                $customer = $this->primaryCompany();
                $lastFollowUp = $this->followUps->sortByDesc('id')->first();

                return [
                    'remarks' => $this->remarks,
                    'visit_outcome_id' => $this->visit_outcome_id,
                    'outcome_label' => $this->outcome?->label,
                    'visit_purpose_ids' => $this->visitPurposes->pluck('id')->values(),
                    'visit_purpose_names' => $this->visitPurposes->pluck('name')->values(),
                    'is_joint_work' => (bool) $this->is_joint_work,
                    'joint_user_ids' => $this->jointUsers->pluck('id')->values(),
                    'joint_user_names' => $this->jointUsers->pluck('name')->values(),
                    'next_follow_up_date' => $lastFollowUp?->next_follow_up_date
                        ? substr((string) $lastFollowUp->next_follow_up_date, 0, 10) : null,
                    'patch_name' => $this->patch?->name,
                    'feedback' => $this->feedbacks->map(fn ($feedback): array => [
                        'question_id' => $feedback->visit_feedback_question_id,
                        'question' => $feedback->question?->question,
                        'answer' => $feedback->answer,
                    ])->values(),
                    'customer' => $customer ? [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'account_code' => $customer->account_code,
                        'type_name' => $customer->typeMaster?->name,
                        'rating' => $customer->ratingType?->name,
                        'phone_number' => $customer->phone_number,
                        'email' => $customer->email,
                        'contact_name' => $customer->contactDetails()->first()?->full_name,
                        'contact_mobile' => $customer->contactDetails()->first()?->mobile_number,
                        'last_visit_date' => $customer->visits()->whereKeyNot($this->id)
                            ->latest('visit_date')->value('visit_date'),
                    ] : null,
                    'dcr' => $this->salesDcr ? [
                        'id' => $this->salesDcr->id,
                        'date' => $this->salesDcr->dcr_date?->toDateString(),
                        'total_expense' => (float) $this->salesDcr->total_expense,
                        'expenses' => SalesDcrExpenseResource::collection($this->salesDcr->expenses),
                    ] : null,
                    'documents' => $this->visitDocumentLinks->map(function ($link): array {
                        $document = $link->documentable;
                        $type = class_basename($link->documentable_type) === 'Quote' ? 'quote' : 'sales_order';

                        return [
                            'id' => $document?->id,
                            'type' => $type,
                            'document_number' => $document?->document_number,
                            'status' => $document?->status,
                            'total' => (float) ($document?->total ?? 0),
                            'web_path' => '/admin/'.($type === 'quote' ? 'quotes' : 'sales-orders').'/'.$document?->id.'/edit',
                        ];
                    })->values(),
                    'sales_document_ready' => $customer && $customer->contactDetails()->exists(),
                    'quote_summary' => $this->quoteSummary(),
                    'sales_order_summary' => $this->salesOrderSummary(),
                ];
            }),
            'checkin_latitude' => $this->checkin_latitude !== null ? (float) $this->checkin_latitude : null,
            'checkin_longitude' => $this->checkin_longitude !== null ? (float) $this->checkin_longitude : null,
            'checkout_latitude' => $this->checkout_latitude !== null ? (float) $this->checkout_latitude : null,
            'checkout_longitude' => $this->checkout_longitude !== null ? (float) $this->checkout_longitude : null,
            'needs_check_in_image' => $this->when(
                isset($this->needs_check_in_image),
                $this->needs_check_in_image,
            ),
            'needs_check_out_image' => $this->when(
                isset($this->needs_check_out_image),
                $this->needs_check_out_image,
            ),
            'primary_company' => $this->when(
                $this->relationLoaded('visitables'),
                function (): ?array {
                    $company = $this->primaryCompany();

                    if (! $company) {
                        return null;
                    }

                    return [
                        'id' => $company->id,
                        'name' => $company->name,
                    ];
                },
            ),
            'sgip' => $this->when(
                $this->relationLoaded('sgipDistribution'),
                function (): ?array {
                    $sgip = $this->sgipDistribution;

                    if (! $sgip) {
                        return null;
                    }

                    return [
                        'id' => $sgip->id,
                        'approval_status' => $sgip->approval_status,
                        'total_value' => $sgip->total_value !== null ? (float) $sgip->total_value : 0.0,
                        'items_count' => $sgip->relationLoaded('items') ? $sgip->items->count() : null,
                    ];
                },
            ),
            'media' => MediaResource::collection($this->whenLoaded('media')),
        ];
    }
}
