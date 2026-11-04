<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewGeoExceptionRequest;
use App\Models\VisitGeoAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeoExceptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(VisitGeoAssessment::query()->with('visit:id,employee_id,document_number,sales_dcr_id')
            ->where('assigned_to', $request->user()->id)->where('decision', 'pending')->orderBy('id')->paginate(50));
    }

    public function review(ReviewGeoExceptionRequest $request, int $assessment): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $assessment, $data): JsonResponse {
            $record = VisitGeoAssessment::query()->lockForUpdate()->findOrFail($assessment);
            abort_unless((int) $record->assigned_to === (int) $request->user()->id
                && (int) $record->visit->employee_id !== (int) $request->user()->id, 403);
            abort_unless($record->decision === 'pending', 409, 'This exception has already been reviewed.');
            $record->forceFill(['decision' => $data['decision'], 'review_note' => $data['note'],
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();

            return response()->json(['data' => $record]);
        });
    }
}
