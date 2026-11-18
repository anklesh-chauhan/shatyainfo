<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TopbarLinks;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMobileQuickLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'links' => ['present', 'nullable', 'array', 'list', 'max:'.TopbarLinks::MAX_LINKS],
            'links.*' => ['required', 'string', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['links.present' => 'Send your selected links or restore the defaults.'];
    }
}
