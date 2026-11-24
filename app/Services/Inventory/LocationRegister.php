<?php

namespace App\Services\Inventory;

use App\Http\Requests\Api\V1\LocationRegisterRequest;
use App\Http\Requests\Api\V1\SaveContactAddressRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Models\Address;
use App\Models\ApprovalOverride;
use App\Models\ContactDetail;
use App\Models\DeliveryChallan;
use App\Models\InventoryAdjustment;
use App\Models\InventoryTransfer;
use App\Models\LocationMaster;
use App\Models\NumberSeries;
use App\Models\SampleIssue;
use App\Models\SampleRequest;
use App\Models\SgipDistribution;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\Field\FieldAddressPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class LocationRegister
{
    public const FIELDS = ['name', 'description', 'parent_id', 'typeable_id', 'latitude', 'longitude', 'is_active'];

    public function canRead(User $actor): bool
    {
        return $actor->can(['ViewAny:LocationMaster', 'View:LocationMaster']);
    }

    public function authorize(User $actor): void
    {
        abort_unless($this->canRead($actor), 403);
    }

    public function visible(User $actor): Builder
    {
        return LocationMaster::query()->applyVisibility('LocationMaster', $actor)
            ->when(! $this->canRead($actor), fn (Builder $query) => $query->whereKey([]));
    }

    public function find(User $actor, int $id, bool $lock = false): LocationMaster
    {
        $this->authorize($actor);

        return $this->visible($actor)->with('approval')->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function canChange(User $actor, LocationMaster $record, string $ability = 'update'): bool
    {
        $states = [$record->approval_status, $record->getApprovalStatus()];

        return $this->canRead($actor) && ! $record->trashed() && $actor->can($ability, $record)
            && ! in_array('pending', $states, true)
            && (! in_array('approved', $states, true) || $actor->can('overrideApproval', $record));
    }

    public function catalog(User $actor, string $kind, ?int $id = null, ?int $parentType = null): Builder
    {
        $query = match ($kind) {
            'parents' => $this->visible($actor),
            'types', 'subtypes' => TypeMaster::query()->applyVisibility('TypeMaster', $actor)->where('typeable_type', LocationMaster::class),
            'territories' => Territory::query()->applyVisibility('Territory', $actor)
                ->when(! $actor->can(['ViewAny:Territory', 'View:Territory']), fn (Builder $query) => $query->whereKey([])),
            'contacts' => ContactDetail::query()->applyVisibility('ContactDetail', $actor)
                ->when(! $actor->can(['ViewAny:ContactDetail', 'View:ContactDetail', 'Update:ContactDetail']), fn (Builder $query) => $query->whereKey([]))
                ->where(fn (Builder $query) => $query->where(fn (Builder $empty) => $empty->whereNull('contactable_type')->whereNull('contactable_id'))
                    ->when($id, fn (Builder $query) => $query->orWhere(fn (Builder $own) => $own->where('contactable_type', (new LocationMaster)->getMorphClass())->where('contactable_id', $id)))),
        };
        if ($kind === 'types') {
            $query->whereNull('parent_id');
        } elseif ($kind === 'subtypes') {
            $query->where('parent_id', $parentType ?? 0)->whereHas('parent', fn (Builder $parent) => $parent->applyVisibility('TypeMaster', $actor)->whereNull('parent_id')->where('typeable_type', LocationMaster::class));
        } elseif ($kind === 'parents' && $id !== null) {
            $graph = LocationMaster::withTrashed()->pluck('parent_id', 'id')->all();
            $query->whereKeyNot(array_values(array_filter(array_keys($graph), fn (int $candidate): bool => $this->wouldCycle($graph, $candidate, $id))));
        }

        return $query;
    }

    private function wouldCycle(array $graph, ?int $parent, int $id): bool
    {
        $seen = [$id => true];
        while ($parent !== null) {
            if (isset($seen[$parent])) {
                return true;
            }
            $seen[$parent] = true;
            $parent = isset($graph[$parent]) ? (int) $graph[$parent] : null;
        }

        return false;
    }

    public function options(User $actor, string $kind, array $filters): array
    {
        $this->authorize($actor);
        $id = isset($filters['location_id']) ? (int) $filters['location_id'] : null;
        if ($id !== null) {
            $this->find($actor, $id);
        }
        $query = $this->catalog($actor, $kind, $id, $filters['parent_type_id'] ?? null);
        if ($kind === 'types') {
            $query->withCount(['children' => fn (Builder $children) => $children->where('typeable_type', LocationMaster::class)]);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($kind === 'contacts') {
            $query->when($search !== '', fn (Builder $query) => $query->search($search))->orderBy('first_name');
        } else {
            $query->when($search !== '', fn (Builder $query) => $query->whereLike('name', '%'.$search.'%'))->orderBy('name');
        }
        $page = $query->orderBy('id')->paginate(30);

        return ['data' => $page->getCollection()->map(fn ($option): array => ['id' => $option->id, 'label' => $kind === 'contacts' ? $option->full_name : $option->name,
            ...($kind === 'types' ? ['has_children' => (int) $option->children_count > 0] : [])])->all(),
            'meta' => ['last_page' => $page->lastPage()]];
    }

    public function revision(LocationMaster $record): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive([
            $record->getAttributes(), $record->approval()->first()?->getAttributes(),
            $record->territories()->orderBy('territories.id')->pluck('territories.id')->all(),
            $record->contactDetail()->withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            $record->addresses()->withTrashed()->with('approval')->orderBy('id')->get()->map(fn (Address $address): array => [$address->getAttributes(), $address->approval?->getAttributes()])->all(),
        ]), JSON_THROW_ON_ERROR));
    }

    public function summary(User $actor, LocationMaster $record): array
    {
        return [...$record->only(['id', 'name', 'location_code', 'parent_id', 'latitude', 'longitude']),
            'is_active' => (bool) $record->is_active, 'approval_status' => $record->getApprovalStatus(),
            'actions' => array_values(array_filter(['submit', 'delete'], fn (string $action): bool => $this->canAct($actor, $record, $action)))];
    }

    public function detail(User $actor, LocationMaster $record): array
    {
        $type = TypeMaster::query()->applyVisibility('TypeMaster', $actor)->where('typeable_type', LocationMaster::class)->find($record->typeable_id);
        $parent = $record->parent_id === null ? null : $this->visible($actor)->find($record->parent_id);
        $contact = $record->contactDetail;
        $canContact = $actor->can(['ViewAny:ContactDetail', 'View:ContactDetail']) && $contact !== null
            && ContactDetail::query()->applyVisibility('ContactDetail', $actor)->whereKey($contact->id)->exists();
        $territories = $record->territories()->whereIn('territories.id', $this->catalog($actor, 'territories')->select('territories.id'))->get();

        return [...$this->summary($actor, $record), ...$record->only(self::FIELDS),
            'is_active' => (bool) $record->is_active, 'revision' => $this->revision($record), 'can_edit' => $this->canChange($actor, $record),
            'parent' => $parent?->name, 'type' => $type?->name, 'parent_type_id' => $type?->parent_id ?? $type?->id,
            'contact_id' => $contact?->id, 'contact' => $canContact ? $contact->full_name : null,
            'territory_ids' => $record->territories()->pluck('territories.id')->all(), 'territories' => $territories->map->only(['id', 'name'])->all(),
            'has_image' => filled($record->image), 'relations' => ['stocks' => app(StockRegister::class)->canRead($actor),
                'addresses' => $actor->can(['ViewAny:Address', 'View:Address'])],
            'can_create_address' => $this->canChange($actor, $record) && $actor->can(['ViewAny:Address', 'View:Address', 'Create:Address'])];
    }

    public function save(User $actor, ?int $id, array $input): LocationMaster
    {
        $this->authorize($actor);
        $input = Validator::make($input, LocationRegisterRequest::saveRules($id !== null))->validate();
        $stored = null;
        try {
            return (new LocationMaster)->getConnection()->transaction(function () use ($actor, $id, $input, &$stored): LocationMaster {
                $this->lockHierarchy();
                $record = $id === null ? new LocationMaster : $this->find($actor, $id, true);
                abort_unless($id === null ? $actor->can('create', LocationMaster::class) : $this->canChange($actor, $record), 403);
                if ($id !== null) {
                    $this->checkRevision($record, $input['revision']);
                }
                $parentId = $input['parent_id'] ?? null;
                if ($parentId !== null && (int) $parentId !== (int) $record->parent_id && ! $this->visible($actor)->whereKey($parentId)->exists()) {
                    throw ValidationException::withMessages(['parent_id' => 'Choose a parent location you can access.']);
                }
                if ($this->wouldCycle(LocationMaster::withTrashed()->pluck('parent_id', 'id')->all(), $parentId, $id ?? 0)) {
                    throw ValidationException::withMessages(['parent_id' => 'The parent location would create a hierarchy cycle.']);
                }
                if ((int) $input['typeable_id'] !== (int) $record->typeable_id) {
                    $type = TypeMaster::query()->applyVisibility('TypeMaster', $actor)->where('typeable_type', LocationMaster::class)->find($input['typeable_id']);
                    if ($type === null || TypeMaster::query()->where('typeable_type', LocationMaster::class)->where('parent_id', $type->id)->exists()
                        || ($type->parent_id && ! TypeMaster::query()->applyVisibility('TypeMaster', $actor)->where('typeable_type', LocationMaster::class)->whereNull('parent_id')->whereKey($type->parent_id)->exists())) {
                        throw ValidationException::withMessages(['typeable_id' => 'Choose a location type without subtypes, or its valid subtype.']);
                    }
                }
                $existingIds = $record->exists ? $record->territories()->pluck('territories.id')->all() : [];
                $ids = array_map(intval(...), $input['territory_ids']);
                $newIds = array_diff($ids, $existingIds);
                $visibleIds = $this->catalog($actor, 'territories')->pluck('id')->all();
                if (array_diff($newIds, $visibleIds) !== []) {
                    throw ValidationException::withMessages(['territory_ids' => 'Choose territories you can access.']);
                }
                $ids = array_unique([...$ids, ...array_diff($existingIds, $visibleIds)]);
                $oldContact = $record->exists ? $record->contactDetail()->with('approval')->first() : null;
                $contactId = $input['contact_id'] ?? null;
                if ((int) $contactId !== (int) $oldContact?->id) {
                    foreach (array_filter([$oldContact?->id, $contactId]) as $changeId) {
                        $contact = $this->catalog($actor, 'contacts', $id)->with('approval')->lockForUpdate()->find($changeId);
                        if ($contact === null || $contact->getApprovalStatus() === 'pending' || ($contact->getApprovalStatus() === 'approved' && ! $actor->can('OverrideApproval:ContactDetail'))) {
                            throw ValidationException::withMessages(['contact_id' => 'Choose an available, editable contact.']);
                        }
                    }
                }
                $record->fill(Arr::only($input, self::FIELDS) + ['description' => null, 'parent_id' => null, 'latitude' => null, 'longitude' => null]);
                $record->typeable_type = TypeMaster::class;
                if (filled($input['image_base64'] ?? null)) {
                    $bytes = base64_decode($input['image_base64'], true);
                    $info = $bytes === false ? false : @getimagesizefromstring($bytes);
                    if ($info === false || strlen($bytes) > 2 * 1024 * 1024 || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 4096 || $info[1] > 4096) {
                        throw ValidationException::withMessages(['image_base64' => 'Choose a JPEG, PNG or WebP image up to 2 MB and 4096 pixels per side.']);
                    }
                    $stored = 'location-images/'.Str::uuid().'.'.match ($info['mime']) {
                        'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'webp'
                    };
                    abort_unless(Storage::put($stored, $bytes, 'private'), 500, 'Could not store the location image.');
                    $record->image = $stored;
                } elseif ($input['remove_image'] ?? false) {
                    $record->image = null;
                }
                $this->logOverride($actor, $record);
                $record->save();
                $record->territories()->sync($ids);
                if ((int) $contactId !== (int) $oldContact?->id) {
                    $oldContact?->update(['contactable_type' => null, 'contactable_id' => null]);
                    if ($contactId !== null) {
                        $record->contactDetail()->save(ContactDetail::query()->findOrFail($contactId));
                    }
                }

                return $record->refresh();
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                Storage::delete($stored);
            }
            throw $exception;
        }
    }

    public function canAct(User $actor, LocationMaster $record, string $action): bool
    {
        return match ($action) {
            'submit' => $this->canChange($actor, $record) && $record->canStartApproval(),
            'delete' => $this->canChange($actor, $record, 'delete'),
            default => false,
        };
    }

    public function act(User $actor, array $ids, string $action, ?string $revision = null, bool $bulk = false): void
    {
        $this->authorize($actor);
        if ($bulk) {
            abort_unless($actor->can(($action === 'delete' ? 'DeleteAny' : 'Update').':LocationMaster'), 403);
        }
        (new LocationMaster)->getConnection()->transaction(function () use ($actor, $ids, $action, $revision): void {
            $this->lockHierarchy();
            $records = $this->visible($actor)->whereKey($ids)->orderBy('id')->with('approval')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            foreach ($records as $record) {
                if ($revision !== null) {
                    $this->checkRevision($record, $revision);
                }
                abort_unless($this->canAct($actor, $record, $action), 403);
                if ($action === 'delete') {
                    $this->assertUnlinked($record);
                }
            }
            foreach ($records as $record) {
                $this->logOverride($actor, $record);
                try {
                    $action === 'delete' ? $record->delete() : $record->startApprovalFromRules();
                } catch (LogicException $exception) {
                    throw ValidationException::withMessages(['approval' => $exception->getMessage()]);
                }
            }
        }, 3);
    }

    private function lockHierarchy(): void
    {
        NumberSeries::getNextNumber(LocationMaster::class);
        NumberSeries::query()->where('model_type', LocationMaster::class)->whereNull('type_master_id')->lockForUpdate()->firstOrFail();
    }

    private function assertUnlinked(LocationMaster $record): void
    {
        foreach (['subLocations', 'inventoryStocks', 'inventoryMovements', 'purchaseOrders', 'goodsReceiptNotes', 'employmentDetails', 'contactDetail', 'addresses'] as $relation) {
            abort_if($record->{$relation}()->withTrashed()->exists(), 409, 'This location has linked records or history and must be retained.');
        }
        foreach ([InventoryAdjustment::class => ['location_master_id'], InventoryTransfer::class => ['from_location_master_id', 'to_location_master_id'],
            DeliveryChallan::class => ['location_master_id'], SampleRequest::class => ['location_master_id'], SampleIssue::class => ['from_location_id', 'to_location_id'], SgipDistribution::class => ['inventory_source_location_id']] as $model => $columns) {
            abort_if($model::withTrashed()->where(function (Builder $query) use ($columns, $record): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, $record->id);
                }
            })->exists(), 409, 'This location has linked records or history and must be retained.');
        }
    }

    public function related(User $actor, int $id, string $relation, array $filters): array
    {
        $record = $this->find($actor, $id);
        if ($relation === 'stocks') {
            $page = app(StockRegister::class)->list($actor, $filters + ['location_master_id' => $record->id]);

            return $page->toArray();
        }
        abort_unless($actor->can(['ViewAny:Address', 'View:Address']), 403);
        $page = $record->addresses()->with([...FieldAddressPayload::RELATIONS, 'approval'])->orderBy('id')->paginate(30);

        return $page->through(fn (Address $address): array => (new AddressResource($address))->resolve() + [
            'can_edit' => $this->canChange($actor, $record) && $this->addressUnlocked($actor, $address) && $actor->can('Update:Address'),
            'can_delete' => $this->canChange($actor, $record) && $this->addressUnlocked($actor, $address) && $actor->can('Delete:Address'),
        ])->toArray() + ['can_create' => $this->canChange($actor, $record) && $actor->can('Create:Address')];
    }

    public function changeAddress(User $actor, int $id, ?int $addressId, ?array $input, string $revision): ?Address
    {
        return (new LocationMaster)->getConnection()->transaction(function () use ($actor, $id, $addressId, $input, $revision): ?Address {
            $record = $this->find($actor, $id, true);
            abort_unless($this->canChange($actor, $record) && $actor->can(['ViewAny:Address', 'View:Address']), 403);
            $this->checkRevision($record, $revision);
            $address = $addressId === null ? null : $record->addresses()->lockForUpdate()->findOrFail($addressId);
            abort_unless($actor->can(($address === null ? 'Create' : ($input === null ? 'Delete' : 'Update')).':Address'), 403);
            if ($input !== null) {
                $input = Validator::make($input, (new SaveContactAddressRequest)->rules())->validate();
            }
            $this->logOverride($actor, $record);

            return app(FieldAddressPayload::class)->change($actor, $record->addresses(), $addressId, $input);
        }, 3);
    }

    private function addressUnlocked(User $actor, Address $address): bool
    {
        $states = [$address->approval_status, $address->getApprovalStatus()];

        return ! in_array('pending', $states, true) && (! in_array('approved', $states, true) || $actor->can('OverrideApproval:Address'));
    }

    private function logOverride(User $actor, LocationMaster $record): void
    {
        if ($record->getApprovalStatus() === 'approved' && $actor->can('overrideApproval', $record)) {
            ApprovalOverride::query()->create(['model_type' => LocationMaster::class, 'model_id' => $record->id, 'user_id' => $actor->id, 'reason' => 'Authorized location maintenance']);
        }
    }

    private function checkRevision(LocationMaster $record, string $revision): void
    {
        abort_unless(hash_equals($this->revision($record), $revision), 409, 'The location changed. Reload it before continuing.');
    }
}
