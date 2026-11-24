<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSecondaryOcrRequest;
use App\Models\SecondaryOcrSource;
use App\Models\User;
use App\Services\Field\SecondaryOcrService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecondaryOcrController extends Controller
{
    public function store(StoreSecondaryOcrRequest $request, SecondaryOcrService $service): JsonResponse
    {
        return response()->json(['data' => $service->capture($request->user(), $request->file('file'), $request->validated())], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeRegister($actor);
        $query = $this->visible($actor)->latest('id');
        if (! $request->boolean('register')) {
            return response()->json($query->paginate(30));
        }

        $page = $query->select(['id', 'period', 'status', 'stockist_id', 'mean_confidence', 'secondary_sales_statement_id'])
            ->with('stockist:id,name')->paginate(30);

        return response()->json($page->toArray() + [
            'register' => ['can_upload' => $actor->can('Create:SecondarySales'), 'month' => now()->format('Y-m')],
        ]);
    }

    public function show(Request $request, int $source): JsonResponse
    {
        $record = $this->owned($request, $source);

        return response()->json(['data' => $request->boolean('register') ? $this->detail($record, $request->user()) : $record]);
    }

    public function file(Request $request, int $source): StreamedResponse
    {
        $path = $this->owned($request, $source)->source_path;
        abort_unless(Storage::disk('local')->exists($path), 404, 'The original scan is unavailable.');

        return Storage::disk('local')->download($path, basename($path), ['Cache-Control' => 'private, no-store']);
    }

    public function retry(Request $request, int $source, SecondaryOcrService $service): JsonResponse
    {
        $record = $this->owned($request, $source);
        $service->retry($request->user(), $record);

        return response()->json(['data' => $this->detail($record->refresh(), $request->user())]);
    }

    private function detail(SecondaryOcrSource $source, User $actor): array
    {
        $source->load(['stockist:id,name', 'territory:id,name', 'reviewer:id,name', 'statement:id,status']);
        $isUploader = (int) $source->created_by === (int) $actor->id && $actor->can('Create:SecondarySales');

        return [
            ...$source->makeHidden(['source_path', 'source_sha256', 'extraction_token', 'tokens', 'mapping_history'])->toArray(),
            'can_retry' => $isUploader && $source->canRetryExtraction(),
            'can_map' => $isUploader && ($source->secondary_sales_statement_id === null || $source->statement?->status === 'rejected'),
        ];
    }

    private function owned(Request $request, int $source): SecondaryOcrSource
    {
        $actor = $request->user();
        $record = $this->visible($actor)->findOrFail($source);
        $this->authorizeRegister($actor);

        return $record;
    }

    private function visible(User $actor): Builder
    {
        return SecondaryOcrSource::query()->where(fn (Builder $query): Builder => $query
            ->where('created_by', $actor->id)->orWhere('reviewer_id', $actor->id));
    }

    private function authorizeRegister(User $actor): void
    {
        abort_unless($actor->can('viewAny', SecondaryOcrSource::class)
            || $actor->can('Create:SecondarySales') || $actor->can('Review:SecondarySales'), 403);
    }
}
