<?php

namespace App\Services\Field;

use App\Enums\ItemType;
use App\Models\ItemMaster;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FieldItemService
{
    /**
     * SGIP catalog: sample / gift / promotional_input items (same pool as Filament).
     *
     * @return LengthAwarePaginator<int, ItemMaster>|Collection<int, ItemMaster>
     */
    public function catalog(?string $type = null, ?string $search = null, int $perPage = 50): LengthAwarePaginator|Collection
    {
        $query = ItemMaster::query()
            ->whereNotNull('item_type')
            ->orderBy('item_name');

        if (filled($type)) {
            $itemType = ItemType::tryFrom($type);

            if ($itemType === null) {
                return ItemMaster::query()->whereRaw('1 = 0')->paginate($perPage);
            }

            $query->where('item_type', $itemType);
        }

        if (filled($search)) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('item_name', 'like', '%'.$search.'%')
                    ->orWhere('item_code', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%');
            });
        }

        $perPage = max(1, min($perPage, 100));

        return $query->paginate($perPage);
    }
}
