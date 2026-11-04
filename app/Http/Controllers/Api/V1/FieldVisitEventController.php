<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreFieldVisitEventRequest;
use App\Http\Resources\Api\V1\VisitResource;
use App\Services\Field\FieldVisitEventService;
use App\Services\Visit\VisitEnforcementService;

class FieldVisitEventController extends Controller
{
    public function store(StoreFieldVisitEventRequest $request, FieldVisitEventService $events,
        VisitEnforcementService $enforcement): VisitResource
    {
        $visit = $events->apply($request->user(), $request->validated(), $request->file('photo'));
        $visit->needs_check_in_image = $enforcement->needsCheckInImage($visit);
        $visit->needs_check_out_image = $enforcement->needsCheckOutImage($visit);

        return new VisitResource($visit);
    }
}
