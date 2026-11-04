<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRcpaObservationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Capture:Rcpa') ?? false;
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
            'captured_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'observed_on' => ['required', 'date_format:Y-m-d'],
            'chemist_id' => ['required', 'integer', 'exists:account_masters,id'],
            'therapy_area_id' => ['required', 'integer', 'exists:account_reporting_masters,id'],
            'item_master_id' => ['required', 'integer', 'exists:item_masters,id'],
            'stock_status' => ['required', 'in:available,low,out_of_stock'],
            'doctor_rx_share' => ['required', 'numeric', 'between:0,100'],
            'offtake_quantity' => ['required', 'numeric', 'between:0,999999999'],
            'offtake_days' => ['required', 'integer', 'in:7,14'],
            'competitors' => ['required', 'array', 'size:5'],
            'competitors.*' => ['array:competitor_id,present'],
            'competitors.*.competitor_id' => ['required', 'integer', 'distinct'],
            'competitors.*.present' => ['required', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'data_flag' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->header('Idempotency-Key') !== $this->input('event_id')) {
                $validator->errors()->add('event_id', 'Use the event UUID as the Idempotency-Key header.');
            }
        });
    }
}
