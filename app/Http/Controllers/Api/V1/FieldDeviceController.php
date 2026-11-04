<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportFieldDeviceRequest;
use App\Http\Requests\Api\V1\RetireFieldDeviceRequest;
use App\Models\FieldDevice;
use App\Services\Field\FieldDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldDeviceController extends Controller
{
    public function __construct(private FieldDeviceService $devices) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) $user->id;
        if ($request->filled('user_id')) {
            abort_unless($user->can(FieldDeviceService::REVIEW_PERMISSION), 403);
            $ownerId = (int) $request->query('user_id');
        }
        $records = FieldDevice::query()->where('user_id', $ownerId)->with('closedBy:id,name')->orderByDesc('reported_at')->get();

        return response()->json(['data' => $records->map(fn (FieldDevice $device) => $this->present($device, $request))->values()]);
    }

    public function report(ReportFieldDeviceRequest $request, string $device): JsonResponse
    {
        return response()->json(['data' => $this->present($this->devices->report($request->user(), $device, $request->validated()), $request)]);
    }

    public function close(Request $request, string $device): JsonResponse
    {
        $request->validate(['pending' => ['present', 'array']]);

        return response()->json(['data' => $this->present($this->devices->close($request->user(), $device, $request->only('pending')), $request)]);
    }

    public function retire(RetireFieldDeviceRequest $request, int $registration): JsonResponse
    {
        $device = FieldDevice::query()->findOrFail($registration);
        abort_unless($this->devices->canView($request->user(), $device), 404);

        return response()->json(['data' => $this->present($this->devices->retire($request->user(), $device, $request->validated('reason')), $request)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FieldDevice $device, Request $request): array
    {
        return [...$device->toArray(), 'closed_by_name' => $device->closedBy?->name,
            'is_current' => $this->devices->currentDeviceId() === strtolower($device->device_id),
            'can_retire' => $this->devices->canRetire($request->user(), $device)];
    }
}
