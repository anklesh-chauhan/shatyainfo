<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SalesDocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesDocumentActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $module = SalesDocumentKind::forRequest($this)->module();

        return $this->user()?->can(["ViewAny:{$module}", "View:{$module}"]) ?? false;
    }

    public function rules(): array
    {
        $actions = ['delete', 'submit', ...match (SalesDocumentKind::forRequest($this)) {
            SalesDocumentKind::Quote => ['sales_order', 'sales_invoice'],
            SalesDocumentKind::Order => ['sales_invoice'],
            SalesDocumentKind::Invoice => [],
        }];

        return [
            'action' => ['required', Rule::in($actions)],
            'ids' => ['required', 'array', 'list', 'min:1', ...(
                in_array($this->input('action'), ['sales_order', 'sales_invoice'], true) ? ['size:1'] : ['max:50']
            )],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        $label = SalesDocumentKind::forRequest($this)->label();

        return ['ids.max' => "Select at most 50 {$label}s.", 'ids.size' => "Select one {$label} to convert."];
    }
}
