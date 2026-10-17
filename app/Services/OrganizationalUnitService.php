<?php

namespace App\Services;

use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class OrganizationalUnitService
{
    public static function normalizeDivision(Model $record): void
    {
        if ($record->isDirty('division_ou_id') && ! $record->isDirty('organizational_unit_id') && $record->organizational_unit_id !== null
            && self::divisionId($record->organizational_unit_id) !== ($record->division_ou_id === null ? null : (int) $record->division_ou_id)) {
            $record->organizational_unit_id = null;
        }
        if ($record->isDirty('organizational_unit_id')) {
            $derived = $record->organizational_unit_id === null
                ? ($record->division_ou_id === null ? null : (int) $record->division_ou_id)
                : self::divisionId($record->organizational_unit_id);
            if ($record->isDirty('division_ou_id') && $record->division_ou_id !== null && (int) $record->division_ou_id !== $derived) {
                throw ValidationException::withMessages(['division_ou_id' => 'Division must match the organizational unit hierarchy.']);
            }
            $record->division_ou_id = $derived;
        }
        if ($record->isDirty('division_ou_id') && $record->division_ou_id !== null) {
            $valid = OrganizationalUnit::whereKey($record->division_ou_id)
                ->whereHas('typeMaster', fn ($query) => $query->where('name', 'Division'))->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['division_ou_id' => 'Choose an organizational unit of type Division.']);
            }
        }
    }

    public static function validateSelection(?int $divisionId, array $unitIds): void
    {
        $allowed = $divisionId === null ? [] : self::divisionUnitIds($divisionId);
        if (array_diff($unitIds, $allowed) !== []) {
            throw ValidationException::withMessages(['organizationalUnits' => 'Choose organizational units within the selected Division.']);
        }
    }

    public static function divisionId(?int $unitId): ?int
    {
        $visited = [];
        while ($unitId !== null) {
            if (isset($visited[$unitId])) {
                throw ValidationException::withMessages(['organizational_unit_id' => 'The organizational unit hierarchy contains a cycle.']);
            }
            $visited[$unitId] = true;
            $unit = OrganizationalUnit::with('typeMaster')->findOrFail($unitId);
            if ($unit->typeMaster?->name === 'Division') {
                return $unit->id;
            }
            $unitId = $unit->parent_id;
        }

        return null;
    }

    /** @return list<int> */
    public static function divisionUnitIds(int $divisionId): array
    {
        $ids = [$divisionId];
        $frontier = $ids;
        while ($frontier !== []) {
            $children = OrganizationalUnit::whereIn('parent_id', $frontier)
                ->whereDoesntHave('typeMaster', fn ($query) => $query->where('name', 'Division'))
                ->pluck('id')->all();
            $frontier = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    public static function getUserOuIds($user): array
    {
        $primaryOuIds = $user->employee
            ?->employmentDetail
            ?->organizationalUnits
            ?->pluck('id')
            ?->filter()
            ?->values()
            ?->all() ?? [];

        if (empty($primaryOuIds)) {
            return [];
        }

        return OrganizationalUnit::whereIn('id', $primaryOuIds)
            ->orWhereIn('parent_id', $primaryOuIds)
            ->pluck('id')
            ->unique()
            ->toArray();
    }
}
