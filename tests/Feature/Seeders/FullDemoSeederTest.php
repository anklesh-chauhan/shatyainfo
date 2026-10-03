<?php

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesTourPlan;
use App\Models\User;
use App\Models\Visit;
use App\Services\SalespersonAi\DayRouteOrderService;
use App\Services\SalespersonAi\SgipSuggestionService;
use App\Services\SalespersonAi\TodayPriorityService;
use App\Services\SalespersonAi\TourPlanDraftService;
use Database\Seeders\FullDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds a full field and sales walkthrough', function () {
    $this->seed(FullDemoSeeder::class);

    $user = User::query()->where('email', FullDemoSeeder::SALES_EMAIL)->first();

    expect($user)->not->toBeNull()
        ->and($user->employee_id)->not->toBeNull()
        ->and($user->employee)->not->toBeNull();

    expect(
        SalesTourPlan::query()
            ->where('user_id', $user->id)
            ->where('approval_status', 'approved')
            ->count()
    )->toBe(2)
        ->and(
            SalesTourPlan::query()
                ->where('user_id', $user->id)
                ->where('month', 'like', now()->addMonth()->format('Y-m').'%')
                ->exists()
        )->toBeFalse();

    $kinds = collect(app(TodayPriorityService::class)->forUser($user, today())['items'])->pluck('kind');

    expect($kinds)->toContain('overdue_follow_up', 'planned_today', 'coverage_gap');

    expect(app(TourPlanDraftService::class)->forUser($user, now()->addMonth()->format('Y-m'))['days'])->not->toBeEmpty();

    $route = app(DayRouteOrderService::class)->forUser($user, today());

    expect($route['stops'])->toHaveCount(2)
        ->and($route['method'])->toBe('nearest_neighbor');

    $visit = Visit::query()->where('document_number', 'DEMO-VIS-MEERA-TODAY')->first();
    $suggestions = app(SgipSuggestionService::class)->forVisit($visit);

    expect(collect($suggestions['suggestions'])->pluck('item_name'))->toContain('Demo Strip');

    expect(Lead::query()->where('owner_id', $user->id)->count())->toBe(3)
        ->and(Deal::query()->where('owner_id', $user->id)->count())->toBe(2)
        ->and(Quote::query()->where('document_number', 'DEMO-QT-1001')->exists())->toBeTrue()
        ->and(SalesOrder::query()->where('document_number', 'DEMO-SO-1001')->exists())->toBeTrue()
        ->and(SalesInvoice::query()->where('document_number', 'DEMO-INV-1001')->exists())->toBeTrue();
});
