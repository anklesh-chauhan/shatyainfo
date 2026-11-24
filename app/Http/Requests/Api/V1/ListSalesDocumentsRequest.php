<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SalesDocumentKind;
use App\Services\Field\FieldSalesDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSalesDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $module = SalesDocumentKind::forRequest($this)->module();

        return $this->user()?->can(["ViewAny:{$module}", "View:{$module}"]) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(FieldSalesDocumentService::STATUSES)],
            'account_master_id' => ['nullable', 'integer', 'min:1'],
            'contact_detail_id' => ['nullable', 'integer', 'min:1'],
            'sales_person_id' => ['nullable', 'integer', 'min:1'],
            'min_total' => ['nullable', 'numeric', 'min:0'],
            'max_total' => ['nullable', 'numeric', 'min:0', ...($this->filled('min_total') ? ['gte:min_total'] : [])],
            'sent' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'max_total.gte' => 'The maximum total must be at least the minimum total.',
        ];
    }
}
