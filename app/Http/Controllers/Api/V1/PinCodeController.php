<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Field\FieldAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PinCodeController extends Controller
{
    public function __invoke(Request $request, FieldAccountService $accountService): JsonResponse
    {
        $validated = $request->validate([
            'pin_code' => ['required', 'string', 'max:20'],
        ]);

        $areas = $accountService->lookupPinCode($validated['pin_code']);

        return response()->json([
            'data' => $areas->map(fn ($area): array => [
                'id' => $area->id,
                'pin_code' => $area->pin_code,
                'area_town' => $area->area_town,
                'city_id' => $area->city_id,
                'state_id' => $area->state_id,
                'country_id' => $area->country_id,
                'city' => $area->city ? [
                    'id' => $area->city->id,
                    'name' => $area->city->name,
                ] : null,
                'state' => $area->state ? [
                    'id' => $area->state->id,
                    'name' => $area->state->name,
                    'gst_code' => $area->state->gst_code ?? null,
                ] : null,
                'country' => $area->country ? [
                    'id' => $area->country->id,
                    'name' => $area->country->name,
                ] : null,
            ])->values(),
        ]);
    }
}
