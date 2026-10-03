<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SalespersonAiFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApplyVisitVoiceNoteRequest;
use App\Http\Requests\Api\V1\SalespersonAiDateRequest;
use App\Http\Requests\Api\V1\SalespersonAiMonthRequest;
use App\Http\Requests\Api\V1\VisitVoiceNoteRequest;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\Field\FieldVisitService;
use App\Services\SalespersonAi\DayRouteOrderService;
use App\Services\SalespersonAi\FollowUpScriptService;
use App\Services\SalespersonAi\NextActionService;
use App\Services\SalespersonAi\PreCallBriefingService;
use App\Services\SalespersonAi\SgipSuggestionService;
use App\Services\SalespersonAi\TodayPriorityService;
use App\Services\SalespersonAi\TourPlanDraftService;
use App\Services\SalespersonAi\VisitVoiceNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalespersonAiController extends Controller
{
    public function __construct(private FieldVisitService $visits) {}

    public function roadmap(): JsonResponse
    {
        return response()->json([
            'data' => SalespersonAiFeature::roadmap(),
        ]);
    }

    public function briefing(Request $request, int $visit): JsonResponse
    {
        $record = $this->visits->findOwned($this->user($request), $visit);

        return response()->json([
            'data' => app(PreCallBriefingService::class)->forVisit($record),
        ]);
    }

    public function voiceNote(VisitVoiceNoteRequest $request, int $visit): JsonResponse
    {
        $this->visits->findOwned($this->user($request), $visit);

        return response()->json([
            'data' => app(VisitVoiceNoteService::class)->draft($request->validated('transcript')),
        ]);
    }

    public function applyVoiceNote(ApplyVisitVoiceNoteRequest $request, int $visit): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->visits->findOwned($user, $visit);

        return response()->json([
            'data' => app(VisitVoiceNoteService::class)->apply(
                $user,
                $record,
                $request->validated('transcript'),
                (bool) $request->boolean('schedule_follow_up'),
            ),
        ]);
    }

    public function priorities(SalespersonAiDateRequest $request): JsonResponse
    {
        return response()->json([
            'data' => app(TodayPriorityService::class)->forUser($this->user($request), $request->onDate()),
        ]);
    }

    public function tourPlanDraft(SalespersonAiMonthRequest $request): JsonResponse
    {
        return response()->json([
            'data' => app(TourPlanDraftService::class)->forUser($this->user($request), $request->month()),
        ]);
    }

    public function sgipSuggestions(Request $request, int $visit): JsonResponse
    {
        $record = $this->visits->findOwned($this->user($request), $visit);

        return response()->json([
            'data' => app(SgipSuggestionService::class)->forVisit($record),
        ]);
    }

    public function followUpScript(Request $request, int $followUp): JsonResponse
    {
        $record = $this->ownedFollowUp($this->user($request), $followUp);

        return response()->json([
            'data' => app(FollowUpScriptService::class)->forFollowUp($record),
        ]);
    }

    public function dayRoute(SalespersonAiDateRequest $request): JsonResponse
    {
        return response()->json([
            'data' => app(DayRouteOrderService::class)->forUser($this->user($request), $request->onDate()),
        ]);
    }

    public function leadNextAction(Request $request, int $lead): JsonResponse
    {
        $record = $this->ownedLead($this->user($request), $lead);

        return response()->json([
            'data' => app(NextActionService::class)->forLead($record),
        ]);
    }

    public function dealNextAction(Request $request, int $deal): JsonResponse
    {
        $record = $this->ownedDeal($this->user($request), $deal);

        return response()->json([
            'data' => app(NextActionService::class)->forDeal($record),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function ownedFollowUp(User $user, int $id): FollowUp
    {
        $followUp = FollowUp::query()->find($id);
        abort_unless($followUp && (int) $followUp->user_id === (int) $user->id, 404, 'Follow-up not found.');

        return $followUp;
    }

    private function ownedLead(User $user, int $id): Lead
    {
        $lead = Lead::query()->find($id);
        abort_unless($lead && (int) $lead->owner_id === (int) $user->id, 404, 'Lead not found.');

        return $lead;
    }

    private function ownedDeal(User $user, int $id): Deal
    {
        $deal = Deal::query()->find($id);
        abort_unless($deal && (int) $deal->owner_id === (int) $user->id, 404, 'Deal not found.');

        return $deal;
    }
}
