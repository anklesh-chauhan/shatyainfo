<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TypeMaster extends BaseModel
{
    use HasApprovalWorkflow, HasFactory;

    protected $fillable = ['name', 'description', 'typeable_id', 'typeable_type', 'parent_id', 'creates_company', 'activation_requirements'];

    protected function casts(): array
    {
        return ['creates_company' => 'boolean', 'activation_requirements' => 'array'];
    }

    /* ================== POLYMORPHIC ================== */
    public function typeable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ================== SCOPES ================== */
    public function scopeOfType($query, string $model)
    {
        return $query->where('typeable_type', $model);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /* ================== HIERARCHY ================== */
    public function parent()
    {
        return $this->belongsTo(TypeMaster::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(TypeMaster::class, 'parent_id');
    }
}
