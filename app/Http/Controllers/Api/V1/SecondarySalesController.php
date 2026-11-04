<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewSecondarySalesRequest;
use App\Http\Requests\Api\V1\StoreSecondarySalesRequest;
use App\Models\SecondarySalesStatement;
use App\Models\User;
use App\Services\Field\SecondarySalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecondarySalesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(SecondarySalesStatement::query()->where(fn ($query) => $query
            ->where('created_by', $user->id)->orWhere('reviewer_id', $user->id))
            ->latest('id')->paginate(30));
    }

    public function store(StoreSecondarySalesRequest $request, SecondarySalesService $service): JsonResponse
    {
        return response()->json(['data' => $service->ingest($request->user(), $request->file('file'), $request->validated())], 201);
    }

    public function show(Request $request, int $statement): JsonResponse
    {
        return response()->json(['data' => $this->owned($request, $statement)->load('lines')]);
    }

    public function source(Request $request, int $statement): StreamedResponse
    {
        $record = $this->owned($request, $statement);

        return Storage::disk('local')->download($record->source_path, basename($record->source_path));
    }

    public function template(Request $request): Response
    {
        abort_unless($request->user()->can('Create:SecondarySales'), 403);

        return response(implode(',', SecondarySalesService::HEADERS)."\r\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="secondary-sales-template.csv"']);
    }

    public function reviewers(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('Create:SecondarySales'), 403);

        return response()->json(['data' => User::query()->orderBy('name')->get()->filter(fn ($user) => $user->id !== $request->user()->id && $user->can('Review:SecondarySales'))->map->only(['id', 'name'])->values()]);
    }

    public function review(ReviewSecondarySalesRequest $request, int $statement, SecondarySalesService $service): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $service->review($request->user(), $this->owned($request, $statement), $data['decision'], $data['note'], $data['physically_verified_line_ids'] ?? [])]);
    }

    private function owned(Request $request, int $id): SecondarySalesStatement
    {
        return SecondarySalesStatement::query()->where(fn ($query) => $query->where('created_by', $request->user()->id)->orWhere('reviewer_id', $request->user()->id))->findOrFail($id);
    }
}
