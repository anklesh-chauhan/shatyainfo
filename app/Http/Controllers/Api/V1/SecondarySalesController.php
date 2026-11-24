<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewSecondarySalesRequest;
use App\Http\Requests\Api\V1\SecondarySalesRegisterRequest;
use App\Http\Requests\Api\V1\StoreSecondarySalesRequest;
use App\Models\AccountMaster;
use App\Models\SecondaryOcrSource;
use App\Models\SecondarySalesStatement;
use App\Models\User;
use App\Services\Field\SecondarySalesBalanceService;
use App\Services\Field\SecondarySalesCorrectionService;
use App\Services\Field\SecondarySalesService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecondarySalesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->authorizeRegister($user);
        $page = $this->visible($user)->with('stockist:id,name')->latest('id')->paginate(30);

        return response()->json($page->toArray() + [
            'register' => ['can_import' => $user->can('Create:SecondarySales'), 'month' => now()->format('Y-m')],
        ]);
    }

    public function store(StoreSecondarySalesRequest $request, SecondarySalesService $service): JsonResponse
    {
        return response()->json(['data' => $service->ingest($request->user(), $request->file('file'), $request->validated())], 201);
    }

    public function show(Request $request, int $statement, SecondarySalesCorrectionService $corrections): JsonResponse
    {
        $record = $this->owned($request, $statement);
        if (! $request->boolean('register')) {
            return response()->json(['data' => $record->load(['lines', 'balances'])]);
        }

        $actor = $request->user();
        $record->load('stockist:id,name');
        $canReview = $actor->can('Review:SecondarySales') && (int) $record->reviewer_id === (int) $actor->id
            && (int) $record->created_by !== (int) $actor->id && in_array($record->status, ['pending_review', 'reconciliation'], true);
        $canCorrect = $corrections->canRequest($actor, $record)
            && $record->stockist !== null && $actor->can('view', $record->stockist)
            && AccountMaster::query()->applyVisibility('AccountMaster', $actor)->whereKey($record->stockist_id)->exists();

        return response()->json(['data' => [
            ...$record->makeHidden(['source_path', 'source_sha256'])->toArray(),
            'source_extension' => SecondarySalesService::sourceExtension($record),
            'can_review' => $canReview,
            'can_request_correction' => $canCorrect,
            'adjustments_count' => $record->lines()->where('transaction_type', '!=', 'sale')->count(),
        ]]);
    }

    public function evidence(SecondarySalesRegisterRequest $request, int $statement, string $section): JsonResponse
    {
        return response()->json(SecondarySalesService::evidence($this->owned($request, $statement), $section));
    }

    public function stockists(SecondarySalesRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can(['Create:SecondarySales', 'View:AccountMaster']), 403);
        $search = $request->validated('search');
        $page = AccountMaster::query()->applyVisibility('AccountMaster', $actor)->where('accept_secondary_statements', true)
            ->whereHas('territory')->with('territory:id,name')
            ->when(filled($search), fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(30)
            ->through(fn (AccountMaster $stockist): array => [
                'id' => $stockist->id, 'label' => $stockist->name,
                'territory_id' => $stockist->territory_id, 'territory' => $stockist->territory?->name,
            ]);

        return $this->optionPage($page);
    }

    public function ocrOptions(SecondarySalesRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('Create:SecondarySales'), 403);
        $filters = $request->safe()->only(['stockist_id', 'territory_id', 'reviewer_id', 'period']);
        abort_unless(count($filters) === 4, 422, 'Choose the stockist, reviewer and month first.');
        $search = trim((string) $request->validated('search'));
        $page = SecondaryOcrSource::query()->where('created_by', $actor->id)->where($filters)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match->whereLike('status', '%'.$search.'%')
                    ->when(ctype_digit(ltrim($search, '#')) && strlen(ltrim($search, '#')) < 19,
                        fn (Builder $id): Builder => $id->orWhere('id', (int) ltrim($search, '#')))
            ))
            ->where(fn (Builder $query): Builder => $query->whereNull('secondary_sales_statement_id')
                ->orWhereHas('statement', fn (Builder $linked): Builder => $linked->where('status', 'rejected')))
            ->latest('id')->paginate(30)
            ->through(fn (SecondaryOcrSource $source): array => [
                'id' => $source->id, 'label' => "#{$source->id} Â· {$source->period} Â· {$source->status}",
            ]);

        return $this->optionPage($page);
    }

    private function optionPage(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json(['data' => $page->items(), 'meta' => ['last_page' => $page->lastPage()]]);
    }

    public function source(Request $request, int $statement): StreamedResponse
    {
        return SecondarySalesService::downloadSource($this->owned($request, $statement));
    }

    public function template(Request $request): Response
    {
        abort_unless($request->user()->can('Create:SecondarySales'), 403);
        $kind = $request->query('kind', 'transactions');
        abort_unless(in_array($kind, ['transactions', 'stock_balance'], true), 422, 'Choose the invoice or stock-balance template.');
        $headers = $kind === 'stock_balance' ? SecondarySalesBalanceService::HEADERS : SecondarySalesService::HEADERS;
        $name = $kind === 'stock_balance' ? 'secondary-sales-balance-template.csv' : 'secondary-sales-template.csv';

        return response(implode(',', $headers)."\r\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
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
        $actor = $request->user();
        $record = $this->visible($actor)->findOrFail($id);
        $this->authorizeRegister($actor);

        return $record;
    }

    private function visible(User $actor): Builder
    {
        return SecondarySalesStatement::query()->where(fn (Builder $query): Builder => $query
            ->where('created_by', $actor->id)->orWhere('reviewer_id', $actor->id));
    }

    private function authorizeRegister(User $actor): void
    {
        abort_unless($actor->can('viewAny', SecondarySalesStatement::class)
            || $actor->can('Create:SecondarySales') || $actor->can('Review:SecondarySales'), 403);
    }
}
