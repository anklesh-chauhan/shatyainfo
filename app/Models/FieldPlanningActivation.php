<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldPlanningActivation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['field_planning_revision_id', 'created_by', 'effective_on'];

    protected function casts(): array
    {
        return ['effective_on' => 'date'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(FieldPlanningRevision::class, 'field_planning_revision_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('Activations are immutable. Activate a new revision.');
        });
        static::deleting(function (): void {
            throw new \LogicException('Activations cannot be deleted.');
        });
    }
}
