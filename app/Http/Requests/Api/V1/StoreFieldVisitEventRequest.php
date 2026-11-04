<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFieldVisitEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'previous_event_id' => ['nullable', 'uuid'],
            'action' => ['required', Rule::in(['check_in', 'details', 'photo', 'check_out', 'samples', 'order'])],
            'captured_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'target' => ['required', 'array:visit_id,detail_id,patch_id,company_id'],
            'target.visit_id' => ['nullable', 'integer', 'min:1', 'required_without:target.detail_id', Rule::prohibitedIf($this->filled('target.detail_id'))],
            'target.detail_id' => ['nullable', 'integer', 'min:1', 'required_without:target.visit_id', Rule::prohibitedIf($this->filled('target.visit_id'))],
            'target.patch_id' => ['integer', 'min:1', 'required_with:target.detail_id', Rule::prohibitedIf($this->filled('target.visit_id'))],
            'target.company_id' => ['integer', 'min:1', 'required_with:target.detail_id', Rule::prohibitedIf($this->filled('target.visit_id'))],
            'payload' => ['present', 'array:remarks,visit_outcome_id,visit_purpose_ids,next_follow_up_date,feedback,latitude,longitude,accuracy_meters,tag,items,sample_issue_id,expected_version,order_id,changes'],
            'payload.order_id' => ['required_if:action,order', 'uuid'],
            'payload.changes' => ['required_if:action,order', 'array', 'min:1', 'max:102'],
            'payload.changes.*' => ['array:field,value,updated_at'],
            'payload.changes.*.field' => ['required', 'string', 'distinct', 'regex:/^(description|delivery_date|line:[1-9][0-9]*)$/'],
            'payload.changes.*.value' => ['present'],
            'payload.changes.*.updated_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'payload.expected_version' => ['present_if:action,samples', 'nullable', 'string', 'size:64'],
            'payload.sample_issue_id' => ['nullable', 'integer'],
            'payload.items' => ['required_if:action,samples', 'array', 'min:1', 'max:100'],
            'payload.items.*' => ['array:item_master_id,quantity'],
            'payload.items.*.item_master_id' => ['required', 'integer', 'distinct', 'exists:item_masters,id'],
            'payload.items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'payload.remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'payload.visit_outcome_id' => ['sometimes', 'nullable', 'integer'],
            'payload.visit_purpose_ids' => ['sometimes', 'array'],
            'payload.visit_purpose_ids.*' => ['integer', 'distinct'],
            'payload.next_follow_up_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'payload.feedback' => ['sometimes', 'array'],
            'payload.feedback.*.question_id' => ['required', 'integer', 'distinct'],
            'payload.feedback.*.answer' => ['nullable', 'integer', 'between:1,5'],
            'payload.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'payload.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'payload.accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'payload.tag' => ['required_if:action,photo', Rule::in(['check-in', 'check-out', 'general-visit'])],
            'photo' => ['required_if:action,photo', 'prohibited_unless:action,photo', 'file', 'image', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->header('Idempotency-Key') !== $this->input('event_id')) {
                $validator->errors()->add('event_id', 'Use the event UUID as the Idempotency-Key header.');
            }
        });
    }
}
