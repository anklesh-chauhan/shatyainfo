<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OverdueFollowUpRequest;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\Reports\OverdueFollowUpQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OverdueFollowUpController extends Controller
{
    public function index(OverdueFollowUpRequest $request, OverdueFollowUpQuery $reports): JsonResponse
    {
        $query = $this->filtered($request, $reports);

        $page = $query->orderByRaw('COALESCE(next_follow_up_date, follow_up_date)')->orderBy('id')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (FollowUp $row): array => [
                'id' => $row->id,
                'can_open' => $request->user()->can('viewAny', FollowUp::class)
                    && $request->user()->can('view', $row),
                'follow_up_date' => $row->follow_up_date,
                'next_follow_up_date' => $row->next_follow_up_date,
                'due_at' => ($row->next_follow_up_date ?? $row->follow_up_date),
                'assigned_to' => $row->user?->name,
                'related_type' => class_basename((string) $row->followupable_type),
                'contact' => $row->contactDetail?->full_name,
                'status' => $row->status?->name ?? 'Open',
                'priority' => $row->priority?->name,
                'interaction' => $row->interaction,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'fetched_at' => now()->toIso8601String(),
            'today' => today()->toDateString(),
            'can_export' => $request->user()->canAccessBusinessPage('Export:Reports'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function employees(OverdueFollowUpRequest $request, OverdueFollowUpQuery $reports): JsonResponse
    {
        $page = User::query()->whereIn('id', $this->visible($request, $reports)->reorder()->select('user_id'))
            ->when($request->validated('search'), fn (Builder $query, string $search): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $user): array => ['id' => $user->id, 'label' => $user->name]),
            'meta' => ['last_page' => $page->lastPage()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function export(OverdueFollowUpRequest $request, OverdueFollowUpQuery $reports): StreamedResponse
    {
        abort_unless($request->user()->canAccessBusinessPage('Export:Reports'), 403);
        $query = $this->filtered($request, $reports);
        abort_if((clone $query)->count() > 10000, 422, 'Choose narrower filters to export up to 10,000 overdue follow-ups.');

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', 'Due at', 'Follow-up date', 'Next follow-up', 'Assigned to', 'Related type', 'Contact', 'Status', 'Priority', 'Interaction'], ',', '"', '');
            foreach ($query->orderByRaw('COALESCE(next_follow_up_date, follow_up_date)')->orderBy('id')->lazy(200) as $row) {
                $values = [
                    $row->id, $row->next_follow_up_date ?? $row->follow_up_date,
                    $row->follow_up_date, $row->next_follow_up_date, $row->user?->name,
                    class_basename((string) $row->followupable_type), $row->contactDetail?->full_name,
                    $row->status?->name ?? 'Open', $row->priority?->name, $row->interaction,
                ];
                fputcsv($stream, array_map($this->csvValue(...), $values), ',', '"', '');
            }
            fclose($stream);
        }, 'overdue-follow-ups.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[\s]*[=+\-@]|^[\t\r\n]/u', $value) === 1 ? "'".$value : $value;
    }

    private function filtered(OverdueFollowUpRequest $request, OverdueFollowUpQuery $reports): Builder
    {
        $query = $this->visible($request, $reports)->with('priority');
        $reports->period($query, $request->validated('from'), $request->validated('until'));
        $query->when($request->validated('user_id'), fn (Builder $query, int $userId): Builder => $query->where('user_id', $userId));
        if (filled($search = $request->validated('search'))) {
            $query->where(function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';
                $query->whereLike('interaction', $term)
                    ->orWhereHas('user', fn (Builder $user): Builder => $user->whereLike('name', $term))
                    ->orWhereHas('contactDetail', fn (Builder $contact): Builder => $contact
                        ->whereLike('first_name', $term)->orWhereLike('last_name', $term));
            });
        }

        return $query;
    }

    private function visible(OverdueFollowUpRequest $request, OverdueFollowUpQuery $reports): Builder
    {
        return $reports->builder()->applyVisibility('FollowUp', $request->user());
    }
}
