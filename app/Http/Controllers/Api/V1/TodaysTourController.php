<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TourPlanDetailResource;
use App\Services\Field\FieldTourService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TodaysTourController extends Controller
{
    public function __invoke(Request $request, FieldTourService $tourService): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAccessBusinessPage('View:TodaysTour'), 403);

        $date = $request->query('date');

        $details = $tourService->forDate(
            $request->user(),
            is_string($date) && $date !== '' ? $date : null,
        );

        return TourPlanDetailResource::collection($details);
    }
}
