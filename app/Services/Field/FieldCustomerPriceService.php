<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\Approval;
use App\Models\ApprovalOverride;
use App\Models\CustomerPrice;
use App\Models\ItemMaster;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FieldCustomerPriceService
{
    private const RELATIONS = ['approval', 'customer:id,name', 'item.parent:id,item_name'];

    public function canRead(User $actor): bool
    {
        return $actor->can(['ViewAny:CustomerPrice', 'View:CustomerPrice']);
    }

    public function canCreate(User $actor): bool
    {
        return $actor->can('create', CustomerPrice::class) && $this->canSelectCustomers($actor);
    }

    public function canChange(User $actor, CustomerPrice $price, string $ability): bool
    {
        return $actor->can($ability, $price)
            && $price->approval?->approval_status !== 'pending'
            && ($price->approval?->approval_status !== 'approved' || $actor->can('overrideApproval', $price));
    }

    public function customers(User $actor, ?Builder $query = null): Builder
    {
        $query ??= AccountMaster::query();

        if (! $this->canSelectCustomers($actor)) {
            return $query->whereKey([]);
        }

        return $query->applyVisibility('AccountMaster', $actor);
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        abort_unless($this->canRead($actor), 403);
        $search = $filters['search'] ?? null;

        return CustomerPrice::query()->forRegister($actor)->with(self::RELATIONS)
            ->when(filled($search), fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match->whereHas('customer', fn (Builder $customer): Builder => $customer->whereLike('name', '%'.$search.'%'))
                    ->orWhereHas('item', fn (Builder $item): Builder => $this->matchingItems($item, $search))
            ))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, $id): Builder => $query->where('customer_id', $id))
            ->when($filters['item_master_id'] ?? null, fn (Builder $query, $id): Builder => $query->where('item_master_id', $id))
            ->latest('id')->paginate($filters['per_page'] ?? 50)
            ->through(fn (CustomerPrice $price): array => $this->data($actor, $price));
    }

    public function find(User $actor, int $id): CustomerPrice
    {
        abort_unless($this->canRead($actor), 403);

        return CustomerPrice::query()->forRegister($actor)->with(self::RELATIONS)->findOrFail($id);
    }

    public function options(User $actor, string $type, array $filters): LengthAwarePaginator
    {
        abort_unless($this->canRead($actor)
            && ($actor->can('create', CustomerPrice::class) || $actor->can('Update:CustomerPrice')), 403);
        $search = $filters['search'] ?? null;
        $perPage = $filters['per_page'] ?? 50;

        return match ($type) {
            'customers' => $this->customers($actor)
                ->when(filled($search), fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
                ->orderBy('name')->orderBy('id')->paginate($perPage, ['id', 'name as label']),
            'items' => ItemMaster::query()->with('parent:id,item_name')
                ->when(filled($search), fn (Builder $query): Builder => $this->matchingItems($query, $search))
                ->orderBy('item_name')->orderBy('id')->paginate($perPage)
                ->through(fn (ItemMaster $item): array => ['id' => $item->id, 'label' => $this->itemLabel($item)]),
            default => abort(404),
        };
    }

    public function data(User $actor, CustomerPrice $price): array
    {
        return [
            ...$price->only(['id', 'customer_id', 'item_master_id', 'price', 'discount']),
            'customer_name' => $price->customer?->name,
            'item_name' => $price->item ? $this->itemLabel($price->item) : null,
            'approval_status' => $price->approval?->approval_status,
            'can_update' => $this->canChange($actor, $price, 'update'),
            'can_delete' => $this->canChange($actor, $price, 'delete'),
        ];
    }

    public function save(User $actor, ?int $id, array $input): CustomerPrice
    {
        try {
            return $actor->getConnection()->transaction(function () use ($actor, $id, $input): CustomerPrice {
                if ($id === null) {
                    abort_unless($this->canCreate($actor), 403);
                    $price = new CustomerPrice(['discount' => 0]);
                } else {
                    $price = $this->locked($actor, [$id], 'update')->sole();
                }

                $price->fill($input);
                if ($price->isDirty(['customer_id', 'item_master_id'])) {
                    $this->validatePair($actor, $price);
                }

                if ($price->isDirty()) {
                    $this->auditOverride($actor, $price, 'updated');
                    abort_unless($price->save(), 409, 'Could not save the customer price.');
                }

                return $price->load(self::RELATIONS);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['item_master_id' => 'An active price already exists for this customer and item.']);
        }
    }

    /** @param list<int> $ids */
    public function delete(User $actor, array $ids): int
    {
        return $actor->getConnection()->transaction(function () use ($actor, $ids): int {
            $prices = $this->locked($actor, $ids, 'delete');
            foreach ($prices as $price) {
                $this->auditOverride($actor, $price, 'deleted');
                abort_unless($price->delete(), 409, 'Could not delete the selected prices.');
            }

            return $prices->count();
        }, attempts: 3);
    }

    private function canSelectCustomers(User $actor): bool
    {
        return $actor->can(['ViewAny:AccountMaster', 'View:AccountMaster']);
    }

    private function validatePair(User $actor, CustomerPrice $price): void
    {
        if (! $this->customers($actor)->whereKey($price->customer_id)->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Select an available customer.']);
        }
        if (! ItemMaster::query()->whereKey($price->item_master_id)->exists()) {
            throw ValidationException::withMessages(['item_master_id' => 'Select an available item or variant.']);
        }
    }

    private function matchingItems(Builder $query, string $search): Builder
    {
        $pattern = '%'.$search.'%';

        return $query->where(fn (Builder $match): Builder => $match
            ->whereLike('item_name', $pattern)->orWhereLike('item_code', $pattern)
            ->orWhereLike('sku', $pattern)->orWhereLike('variant_name', $pattern)
            ->orWhereHas('parent', fn (Builder $parent): Builder => $parent->whereLike('item_name', $pattern)));
    }

    public function itemLabel(ItemMaster $item): string
    {
        return $item->parent && filled($item->variant_name) ? $item->parent->item_name.' – '.$item->variant_name : $item->item_name;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, CustomerPrice>
     */
    private function locked(User $actor, array $ids, string $ability): Collection
    {
        $prices = CustomerPrice::query()->forRegister($actor)->whereKey($ids)->with('approval')->orderBy('id')->lockForUpdate()->get();
        abort_unless($prices->count() === count($ids), 404, 'One or more prices are unavailable.');

        $approvals = Approval::query()->whereKey($prices->pluck('approval.id')->filter()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($prices as $price) {
            $price->setRelation('approval', $approvals->get($price->approval?->id));
            abort_unless($actor->can($ability, $price), 403);
            abort_unless($this->canChange($actor, $price, $ability), 422, 'This price is locked by its approval status.');
        }

        return $prices;
    }

    private function auditOverride(User $actor, CustomerPrice $price, string $action): void
    {
        if ($price->approval?->approval_status === 'approved') {
            ApprovalOverride::query()->create([
                'model_type' => CustomerPrice::class,
                'model_id' => $price->id,
                'user_id' => $actor->id,
                'reason' => "Customer price {$action} using approval override.",
            ]);
        }
    }
}
