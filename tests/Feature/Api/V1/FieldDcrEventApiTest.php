<?php

use App\Models\FieldDcrEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('records non-visit work once and permits a reporting-only DCR without claiming leave approval', function () {
    $rep = reportingUser('DayReportRep');
    Sanctum::actingAs($rep);
    $event = ['event_id' => (string) Str::uuid(), 'date' => today()->toDateString(),
        'category' => 'training', 'remarks' => 'Product training at HQ', 'captured_at' => now()->utc()->toIso8601String()];
    $send = fn () => $this->postJson('/api/v1/field-dcr-events', $event, ['Idempotency-Key' => $event['event_id']]);
    $id = $send()->assertSuccessful()->assertJsonPath('data.field_entries.0.category', 'training')->json('data.id');
    $send()->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(FieldDcrEntry::query()->count())->toBe(1);
    $this->postJson('/api/v1/dcrs/'.$id.'/submit')->assertSuccessful()->assertJsonPath('data.approval_status', 'approved');
    $event['event_id'] = (string) Str::uuid();
    $this->postJson('/api/v1/field-dcr-events', $event, ['Idempotency-Key' => $event['event_id']])->assertUnprocessable();
    expect(FieldDcrEntry::query()->count())->toBe(1);
});

it('rejects day-report date tampering and missing replay headers', function () {
    Sanctum::actingAs(reportingUser('InvalidDayReportRep'));
    $event = ['event_id' => (string) Str::uuid(), 'date' => today()->subDay()->toDateString(),
        'category' => 'meeting', 'remarks' => 'Meeting', 'captured_at' => now()->utc()->toIso8601String()];
    $this->postJson('/api/v1/field-dcr-events', $event)->assertUnprocessable();
    $this->postJson('/api/v1/field-dcr-events', $event, ['Idempotency-Key' => $event['event_id']])->assertUnprocessable();
    expect(FieldDcrEntry::query()->count())->toBe(0);
});
