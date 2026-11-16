<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationalUnit extends BaseModel
{
    use HasApprovalWorkflow;

    protected $fillable = ['name', 'code', 'type_master_id', 'description', 'parent_id', 'is_active'];

    // Parent OU (for hierarchy)
    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'parent_id');
    }

    // Child OUs (for hierarchy)
    public function children(): HasMany
    {
        return $this->hasMany(OrganizationalUnit::class, 'parent_id');
    }

    public function typeMaster()
    {
        return $this->belongsTo(TypeMaster::class, 'type_master_id');
    }

    public function scopeActiveDivisions(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('typeMaster',
            fn (Builder $types): Builder => $types->where('name', 'Division')->where('typeable_type', self::class));
    }

    public function parentType(): ?TypeMaster
    {
        return $this->typeMaster?->parent;
    }

    // Users assigned to this OU
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // Leads associated with this OU
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    // Deals associated with this OU
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function territories()
    {
        return $this->belongsToMany(
            Territory::class,
            'territory_division_pivot',
            'division_ou_id',
            'territory_id'
        );
    }

    public function employees()
    {
        return $this->belongsToMany(
            EmployeeDetail::class,
            'employment_detail_ou_pivot',
            'organizational_unit_id',
            'employment_detail_id',
        );
    }

    public static function hierarchicalOptions(?int $parentId = null, string $prefix = ''): array
    {
        $options = [];

        $units = self::query()
            ->where('parent_id', $parentId)
            ->orderBy('name')
            ->get();

        foreach ($units as $unit) {
            $options[$unit->id] = $prefix.$unit->name;

            $options += self::hierarchicalOptions(
                $unit->id,
                $prefix.'— '
            );
        }

        return $options;
    }
}
