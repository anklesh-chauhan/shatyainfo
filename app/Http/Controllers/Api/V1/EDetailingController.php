<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEDetailingPresentationRequest;
use App\Models\EDetailingMaterial;
use App\Services\Field\FieldVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EDetailingController extends Controller
{
    public function __construct(private FieldVisitService $visits) {}

    public function index(Request $request, int $visit): JsonResponse
    {
        $record = $this->visits->findOwned($request->user(), $visit);
        $materials = EDetailingMaterial::query()->where('is_active', true)->with('item:id,item_name')->orderBy('sort_order')->orderBy('title')->get();

        return response()->json([
            'data' => $materials->map(fn (EDetailingMaterial $material): array => [
                'id' => $material->id, 'title' => $material->title, 'description' => $material->description,
                'product' => $material->item?->item_name, 'mime_type' => $material->mime_type,
                'file_size' => $material->file_size, 'content_version' => $material->content_version,
            ]),
            'presentations' => $record->eDetailingPresentations()->latest('presented_at')->get(),
            'can_record' => $record->start_time !== null && $record->end_time === null && $record->visit_status === 'started',
        ]);
    }

    public function file(Request $request, int $visit, int $material): BinaryFileResponse
    {
        $this->visits->findOwned($request->user(), $visit);
        $record = EDetailingMaterial::query()->where('is_active', true)->findOrFail($material);
        abort_unless(hash_equals($record->content_version, (string) $request->query('version')), 409, 'This material changed. Refresh the library.');

        return $this->fileResponse($record);
    }

    public function preview(EDetailingMaterial $material): BinaryFileResponse
    {
        Gate::authorize('view', $material);

        return $this->fileResponse($material);
    }

    private function fileResponse(EDetailingMaterial $material): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        abort_unless(str_starts_with($material->file_path, EDetailingMaterial::uploadDirectory().'/') && ! str_contains($material->file_path, '..') && ! str_contains($material->file_path, '\\'), 404);
        abort_unless($disk->exists($material->file_path), 404, 'The presentation file is unavailable.');

        return response()->file($disk->path($material->file_path), [
            'Content-Type' => $material->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function store(StoreEDetailingPresentationRequest $request, int $visit): JsonResponse
    {
        $record = $this->visits->findOwned($request->user(), $visit);
        $data = $request->validated();

        return $record->getConnection()->transaction(function () use ($record, $request, $data): JsonResponse {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            $material = EDetailingMaterial::query()->where('is_active', true)->lockForUpdate()->findOrFail($data['material_id']);
            abort_unless(hash_equals($material->content_version, $data['content_version']), 409, 'This material changed. Reopen it before recording.');
            $existing = $record->eDetailingPresentations()->where('e_detailing_material_id', $material->id)->where('content_version', $data['content_version'])->first();
            if ($existing) {
                return response()->json(['data' => $existing]);
            }
            abort_unless($record->start_time !== null && $record->end_time === null && $record->visit_status === 'started', 422, 'Record presentations during an active, checked-in visit.');
            $presentation = $record->eDetailingPresentations()->create([
                'e_detailing_material_id' => $material->id, 'user_id' => $request->user()->id,
                'material_title' => $material->title, 'content_version' => $material->content_version,
                'notes' => $data['notes'] ?? null, 'presented_at' => now(),
            ]);

            return response()->json(['data' => $presentation], 201);
        });
    }
}
