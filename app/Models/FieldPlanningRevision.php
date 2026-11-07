<?php

namespace App\Models;

use App\Enums\FieldPlanningMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FieldPlanningRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'version', 'previous_revision_id', 'mode', 'rules',
        'proposed_effective_on', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'mode' => FieldPlanningMode::class,
            'rules' => 'array',
            'proposed_effective_on' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function previousRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_revision_id');
    }

    /** @return array<string, mixed> */
    public function proposal(): array
    {
        return [
            'mode' => $this->mode->value,
            'rules' => $this->rules,
            'proposed_effective_on' => $this->proposed_effective_on?->toDateString(),
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Saved planning revisions cannot be edited. Save a new proposal.');
        });
        static::deleting(function (): void {
            throw new LogicException('Saved planning revisions cannot be deleted.');
        });
    }
}
