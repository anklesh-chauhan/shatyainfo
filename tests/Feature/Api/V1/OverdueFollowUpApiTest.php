<?php

use App\Filament\Pages\Reports\OverdueFollowUps;
use App\Models\FollowUp;
use App\Models\FollowUpStatus;
use App\Models\Lead;
use App\Services\Mobile\MobileNavigationService;
use App\Services\Reports\OverdueFollowUpQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('shares overdue criteria visibility and employee choices with the report', function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->startOfDay());
    $actor = grantPagePermissions(reportingUser('Overdue reader'), 'View:OverdueFollowUps');
    $other = reportingUser('Hidden assignee');
    $status = reportingLeadStatus('Open');
    $lead = Lead::forceCreate([
        'owner_id' => $actor->id, 'transaction_date' => today(),
        'status_id' => $status->id, 'status_type' => $status::class,
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $make = fn (array $attributes = []) => FollowUp::forceCreate(array_replace([
        'followupable_type' => Lead::class, 'followupable_id' => $lead->id,
        'user_id' => $actor->id, 'follow_up_date' => '2026-10-01 10:00:00',
        'interaction' => 'Old overdue', 'created_by' => $actor->id, 'updated_by' => $actor->id,
    ], $attributes));
    $old = $make();
    $recent = $make(['next_follow_up_date' => '2026-11-23 10:00:00', 'interaction' => 'Recent overdue']);
    $make(['next_follow_up_date' => '2026-11-24 00:00:00']);
    $make(['next_follow_up_date' => '2026-11-25 10:00:00']);
    $make(['user_id' => $other->id, 'created_by' => $other->id, 'updated_by' => $other->id]);
    foreach (OverdueFollowUpQuery::CLOSED_STATUSES as $name) {
        $closed = FollowUpStatus::query()->create(['name' => $name]);
        $make(['follow_up_status_id' => $closed->id]);
    }
    $deleted = $make();
    $deleted->delete();
    $this->actingAs($actor);
    \Pest\Livewire\livewire(OverdueFollowUps::class)
        ->assertCanSeeTableRecords([$old, $recent])
        ->filterTable('period', ['from' => '2026-10-05', 'until' => '2026-11-01'])
        ->assertCanNotSeeTableRecords([$old, $recent]);
    Sanctum::actingAs($actor);
    $response = $this->getJson('/api/v1/reports/overdue-follow-ups')->assertSuccessful();
    expect(array_column($response->json('data'), 'id'))->toBe([$old->id, $recent->id]);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->getJson('/api/v1/reports/overdue-follow-ups?from=2026-11-01&until=2026-11-23')
        ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $recent->id);
    $this->getJson('/api/v1/reports/overdue-follow-ups?from=2026-10-05&until=2026-11-01')
        ->assertSuccessful()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/reports/overdue-follow-ups?search=Recent')
        ->assertSuccessful()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/reports/overdue-follow-ups?user_id='.$other->id)
        ->assertSuccessful()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/reports/overdue-follow-ups/employees')
        ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $actor->id);
});

it('requires report permission for direct reads and negotiated navigation', function () {
    $actor = reportingUser('Overdue permissions');
    Sanctum::actingAs($actor);
    $this->getJson('/api/v1/reports/overdue-follow-ups')->assertForbidden();
    $this->getJson('/api/v1/reports/overdue-follow-ups/employees')->assertForbidden();
    $actor = grantPagePermissions($actor, 'View:OverdueFollowUps');
    Sanctum::actingAs($actor);
    $this->getJson('/api/v1/reports/overdue-follow-ups')->assertSuccessful();
    $items = collect(app(MobileNavigationService::class)->forUser($actor, true)['groups'])->flatMap(fn (array $group) => $group['items']);
    expect($items->firstWhere('key', 'g09-12')['screen_key'])->toBe('overdue-follow-ups');
    $actor->revokePermissionTo('View:OverdueFollowUps');
    Sanctum::actingAs($actor->fresh());
    $this->getJson('/api/v1/reports/overdue-follow-ups')->assertForbidden();
});

it('validates overdue report dates and bounded pagination', function () {
    Sanctum::actingAs(grantPagePermissions(reportingUser('Overdue validation'), 'View:OverdueFollowUps'));
    $this->getJson('/api/v1/reports/overdue-follow-ups?from=2026-11-23&until=2026-11-01&page=0')
        ->assertUnprocessable()->assertJsonValidationErrors(['until', 'page']);
    $this->getJson('/api/v1/reports/overdue-follow-ups?from=invalid&user_id=-1&search='.str_repeat('x', 101))
        ->assertUnprocessable()->assertJsonValidationErrors(['from', 'user_id', 'search']);
});

it('exports all filtered overdue rows with an independent export grant and safe csv cells', function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->startOfDay());
    $actor = grantPagePermissions(reportingUser('Overdue exporter'), 'View:OverdueFollowUps');
    $other = reportingUser('Hidden export owner');
    $status = reportingLeadStatus('Open');
    $lead = Lead::forceCreate([
        'owner_id' => $actor->id, 'transaction_date' => today(),
        'status_id' => $status->id, 'status_type' => $status::class,
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $base = [
        'followupable_type' => Lead::class, 'followupable_id' => $lead->id,
        'user_id' => $actor->id, 'follow_up_date' => '2026-10-01 10:00:00',
        'next_follow_up_date' => '2026-11-23 10:00:00',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ];
    $rows = collect(range(1, 26))->map(fn (int $index) => FollowUp::forceCreate($base + ['interaction' => '=DANGER,"line '.$index.'"']));
    FollowUp::forceCreate(array_replace($base, ['user_id' => $other->id, 'created_by' => $other->id, 'updated_by' => $other->id, 'interaction' => 'Hidden row']));
    FollowUp::forceCreate(array_replace($base, ['next_follow_up_date' => '2026-10-20 10:00:00', 'interaction' => 'Outside date filter']));
    Sanctum::actingAs($actor);
    $url = '/api/v1/reports/overdue-follow-ups/export?from=2026-11-01&until=2026-11-23&search=DANGER&user_id='.$actor->id.'&page=2';
    $this->getJson($url)->assertForbidden();
    $this->getJson('/api/v1/reports/overdue-follow-ups')->assertSuccessful()->assertJsonPath('can_export', false)->assertJsonPath('data.0.can_open', false);
    $actor = grantPagePermissions($actor, 'Export:Reports');
    Sanctum::actingAs($actor);
    $response = $this->get($url)->assertSuccessful()->assertDownload('overdue-follow-ups.csv');
    $csv = $response->streamedContent();
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect(substr_count($csv, 'DANGER'))->toBe(26);
    expect($csv)->not->toContain('Hidden row', 'Outside date filter');
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    fgetcsv($stream, null, ',', '"', '');
    $first = fgetcsv($stream, null, ',', '"', '');
    fclose($stream);
    expect($first[9])->toBe('\'=DANGER,"line 1"');
    $this->getJson('/api/v1/reports/overdue-follow-ups')->assertJsonPath('can_export', true)->assertJsonCount(25, 'data');
    $actor = grantPagePermissions($actor, 'ViewAny:FollowUp', 'View:FollowUp');
    Sanctum::actingAs($actor);
    $this->getJson('/api/v1/reports/overdue-follow-ups')->assertJsonPath('data.0.can_open', true);
    $this->getJson('/api/v1/follow-ups/'.$rows->first()->id)->assertSuccessful();
    $actor->revokePermissionTo('View:FollowUp');
    Sanctum::actingAs($actor->fresh());
    $this->getJson('/api/v1/follow-ups/'.$rows->first()->id)->assertForbidden();
    $this->getJson('/api/v1/reports/overdue-follow-ups')->assertJsonPath('data.0.can_open', false);
    $actor->revokePermissionTo('Export:Reports');
    Sanctum::actingAs($actor->fresh());
    $this->getJson($url)->assertForbidden();
    $actor = grantPagePermissions($actor, 'Export:Reports');
    $actor->revokePermissionTo('View:OverdueFollowUps');
    Sanctum::actingAs($actor->fresh());
    $this->getJson($url)->assertForbidden();
});
