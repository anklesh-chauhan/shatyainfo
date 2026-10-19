<?php

namespace App\Models;

use App\Traits\HasHrApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HrChangeRequest extends BaseModel
{
    use HasHrApproval, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = ['employee_id', 'request_type', 'target_type', 'target_id', 'previous_values', 'proposed_values', 'reason'];

    protected function casts(): array
    {
        return ['previous_values' => 'array', 'proposed_values' => 'array', 'applied_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    protected function resolveApprovalAmount(): float
    {
        if ($this->request_type !== 'salary') {
            return 0.0;
        }
        $values = array_merge($this->target?->getAttributes() ?? [], $this->proposed_values);

        return (float) collect(['basic_salary', 'hra', 'conveyance', 'special_allowance', 'medical_allowance', 'other_allowances'])
            ->sum(fn (string $field): float => (float) ($values[$field] ?? 0));
    }
}
