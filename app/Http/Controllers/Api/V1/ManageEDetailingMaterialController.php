<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EDetailingMaterialRegisterRequest;
use App\Http\Requests\Api\V1\SaveEDetailingMaterialRequest;
use App\Models\EDetailingMaterial;
use App\Models\ItemMaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageEDetailingMaterialController extends Controller
{
    private const FIELDS = ['title', 'description', 'item_master_id', 'sort_order', 'is_active'];

    public function index(EDetailingMaterialRegisterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', EDetailingMaterial::class);
        $search = trim((string) $request->validated('search'));
        $publication = $request->validated('publication');
        $page = EDetailingMaterial::query()->with('item:id,item_name')->withCount('presentations')
            ->when($publication !== null, fn (Builder $query): Builder => $query->where('is_active', $publication === 'published'))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match
                ->whereLike('title', '%'.$search.'%')
                ->orWhereHas('item', fn (Builder $item): Builder => $item->whereLike('item_name', '%'.$search.'%'))))
            ->orderBy('sort_order')->orderBy('title')->orderBy('id')->paginate(30)
            ->through(fn (EDetailingMaterial $record): array => $this->data($record));

        return response()->json($page->toArray() + ['can_create' => Gate::allows('create', EDetailingMaterial::class)]);
    }

    public function products(EDetailingMaterialRegisterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', EDetailingMaterial::class);
        $actor = $request->user();
        abort_unless($actor->can('create', EDetailingMaterial::class) || $actor->can('Update:EDetailingMaterial'), 403);
        $search = trim((string) $request->validated('search'));
        $page = $this->productsFor($request)
            ->when($search !== '', fn (Builder $query): Builder => $query->whereLike('item_name', '%'.$search.'%'))
            ->orderBy('item_name')->orderBy('id')->paginate(30, ['id', 'item_name as label']);

        return response()->json(['data' => $page->items(), 'meta' => ['last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, int $material): JsonResponse
    {
        Gate::authorize('viewAny', EDetailingMaterial::class);
        $record = EDetailingMaterial::query()->with('item:id,item_name')->withCount('presentations')->findOrFail($material);
        Gate::authorize('view', $record);

        return response()->json(['data' => $this->data($record)]);
    }

    public function store(SaveEDetailingMaterialRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', EDetailingMaterial::class);
        Gate::authorize('create', EDetailingMaterial::class);
        $record = $this->save($request, null);

        return response()->json(['data' => $this->data($record)], 201);
    }

    public function update(SaveEDetailingMaterialRequest $request, int $material): JsonResponse
    {
        Gate::authorize('viewAny', EDetailingMaterial::class);
        $record = $this->save($request, $material);

        return response()->json(['data' => $this->data($record)]);
    }

    private function save(SaveEDetailingMaterialRequest $request, ?int $id): EDetailingMaterial
    {
        $path = null;
        try {
            return (new EDetailingMaterial)->getConnection()->transaction(function () use ($request, $id, &$path): EDetailingMaterial {
                $record = $id === null ? new EDetailingMaterial : EDetailingMaterial::query()->lockForUpdate()->findOrFail($id);
                if ($record->exists) {
                    Gate::authorize('update', $record);
                    abort_unless(hash_equals($this->revision($record), $request->validated('revision')), 409, 'This material changed. Reload it before saving.');
                }
                $product = $request->validated('item_master_id');
                if ($product !== null && (int) $product !== (int) $record->item_master_id && ! $this->productsFor($request)->whereKey((int) $product)->exists()) {
                    throw ValidationException::withMessages(['item_master_id' => 'Choose an available product.']);
                }
                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store(EDetailingMaterial::uploadDirectory(), 'local');
                    abort_unless(is_string($path), 500, 'Could not store the presentation.');
                    $record->file_path = $path;
                }
                $record->fill($request->safe()->only(self::FIELDS) + ['description' => null, 'item_master_id' => null]);
                abort_unless($record->save(), 409, 'Could not save this material.');

                return $record->load('item:id,item_name')->loadCount('presentations');
            });
        } catch (Throwable $error) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }

            throw $error;
        }
    }

    private function productsFor(Request $request): Builder
    {
        $actor = $request->user();
        $query = ItemMaster::query()->applyVisibility('ItemMaster', $actor);

        return $actor->can(['ViewAny:ItemMaster', 'View:ItemMaster']) ? $query : $query->whereKey([]);
    }

    private function data(EDetailingMaterial $record): array
    {
        return [
            ...$record->only(['id', ...self::FIELDS, 'mime_type', 'file_size', 'content_version', 'presentations_count']),
            'product' => $record->item?->item_name,
            'can_view' => Gate::allows('view', $record),
            'can_update' => Gate::allows('update', $record),
            'can_preview' => Gate::allows('view', $record) && ($record->is_active || Gate::allows('update', $record)),
            'revision' => $this->revision($record),
        ];
    }

    private function revision(EDetailingMaterial $record): string
    {
        return hash('sha256', json_encode($record->only([...self::FIELDS, 'content_version', 'file_path', 'updated_at']), JSON_THROW_ON_ERROR));
    }
}
