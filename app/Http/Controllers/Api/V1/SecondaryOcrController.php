<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSecondaryOcrRequest;
use App\Models\SecondaryOcrSource;
use App\Services\Field\SecondaryOcrService;
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
        return response()->json(SecondaryOcrSource::query()->where(fn ($query) => $query->where('created_by', $request->user()->id)->orWhere('reviewer_id', $request->user()->id))->latest('id')->paginate(30));
    }

    public function show(Request $request, int $source): JsonResponse
    {
        return response()->json(['data' => $this->owned($request, $source)]);
    }

    public function file(Request $request, int $source): StreamedResponse
    {
        return Storage::disk('local')->download($this->owned($request, $source)->source_path);
    }

    private function owned(Request $request, int $source): SecondaryOcrSource
    {
        return SecondaryOcrSource::query()->where(fn ($query) => $query->where('created_by', $request->user()->id)->orWhere('reviewer_id', $request->user()->id))->findOrFail($source);
    }
}
