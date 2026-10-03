<?php

use App\Filament\Pages\SalesAssistant;
use App\Filament\Widgets\VisitPreCallBriefingWidget;
use App\Models\AccountMaster;
use App\Models\CityPinCode;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\SgipDistribution;
use App\Models\SgipDistributionItem;
use App\Models\SgipLimit;
use App\Models\Visit;
use App\Models\VisitOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('lists the salesperson assistant roadmap', function () {
    Sanctum::actingAs(reportingUser('Roadmap Rep'));

    $this->getJson('/api/v1/salesperson-ai/roadmap')
        ->assertSuccessful()
        ->assertJsonCount(8, 'data')
        ->assertJsonPath('data.0.key', 'pre_call_briefing')
        ->assertJsonPath('data.0.status', 'available')
        ->assertJsonPath('data.7.key', 'next_action');
});

it('briefs a salesperson from the last completed visit', function () {
    $user = reportingUser('Briefing Rep');
    $territory = reportingTerritory('Briefing Terr');
    $patch = reportingPatch($territory, 'Briefing Patch');
    $doctor = reportingDoctor($user, 'Dr Brief');
    $this->actingAs($user);

    $past = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'VIS-PAST-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today()->subWeek(),
        'visit_type' => 'unplanned',
        'visit_status' => 'completed',
        'approval_status' => 'draft',
        'remarks' => 'Asked about samples',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));
    $past->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);

    $visit = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'VIS-NOW-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'draft',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));
    $visit->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/visits/'.$visit->id.'/briefing')
        ->assertSuccessful()
        ->assertJsonPath('data.account.name', 'Dr Brief')
        ->assertJsonPath('data.last_visits.0.remarks', 'Asked about samples');

    Sanctum::actingAs(reportingUser('Briefing Other'));

    $this->getJson('/api/v1/visits/'.$visit->id.'/briefing')->assertNotFound();
});

it('keeps a voice note as a draft until the salesperson applies it', function () {
    $user = reportingUser('Voice Rep');
    $territory = reportingTerritory('Voice Terr');
    $patch = reportingPatch($territory, 'Voice Patch');
    $this->actingAs($user);

    VisitOutcome::query()->create([
        'label' => 'Interested',
        'code' => 'INTERESTED-'.fake()->unique()->numerify('###'),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $visit = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'VIS-VOICE-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'started',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    Sanctum::actingAs($user);
    $transcript = 'Doctor was Interested. Follow up tomorrow.';

    $this->postJson('/api/v1/visits/'.$visit->id.'/voice-note', ['transcript' => $transcript])
        ->assertSuccessful()
        ->assertJsonPath('data.remarks', $transcript)
        ->assertJsonPath('data.visit_outcome_label', 'Interested')
        ->assertJsonPath('data.next_follow_up_date', today()->addDay()->toDateString());

    expect($visit->fresh()->remarks)->toBeNull()
        ->and(FollowUp::query()->count())->toBe(0);

    $this->postJson('/api/v1/visits/'.$visit->id.'/voice-note/apply', [
        'transcript' => $transcript,
        'schedule_follow_up' => true,
    ])->assertSuccessful()
        ->assertJsonPath('data.remarks', $transcript);

    $followUp = FollowUp::query()->first();

    expect($visit->fresh()->remarks)->toBe($transcript)
        ->and($visit->fresh()->outcome?->label)->toBe('Interested')
        ->and($followUp)->not->toBeNull()
        ->and(Carbon::parse($followUp->next_follow_up_date)->toDateString())->toBe(today()->addDay()->toDateString());
});

it('ranks overdue follow-ups ahead of unfinished calls', function () {
    $user = reportingUser('Priority Rep');
    $territory = reportingTerritory('Priority Terr');
    $patch = reportingPatch($territory, 'Priority Patch');
    $doctor = reportingDoctor($user, 'Dr Planned');
    $patch->companies()->attach($doctor->id, ['sequence_no' => 1]);
    $contact = reportingContact('Anil', 'Shah');
    $this->actingAs($user);

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $user->id,
        'month' => now()->format('Y-m'),
        'approval_status' => 'approved',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $detail = SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => today(),
        'territory_id' => $territory->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $detail->patches()->attach($patch->id);

    FollowUp::query()->create([
        'followupable_type' => Visit::class,
        'followupable_id' => Visit::withoutEvents(fn () => Visit::query()->create([
            'document_number' => 'VIS-FU-'.fake()->unique()->numerify('####'),
            'employee_id' => $user->id,
            'territory_id' => $territory->id,
            'patch_id' => $patch->id,
            'visit_date' => today()->subDays(2),
            'visit_type' => 'unplanned',
            'visit_status' => 'completed',
            'approval_status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]))->id,
        'user_id' => $user->id,
        'to_whom' => $contact->id,
        'follow_up_date' => now()->subDays(3),
        'interaction' => 'Asked for a price list',
        'next_follow_up_date' => now()->subDay(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/todays-priorities')
        ->assertSuccessful()
        ->assertJsonPath('data.items.0.kind', 'overdue_follow_up')
        ->assertJsonPath('data.items.0.title', 'Anil Shah')
        ->assertJsonPath('data.items.1.kind', 'planned_today')
        ->assertJsonPath('data.items.1.title', 'Dr Planned');
});

it('drafts next month from the previous tour plan without creating it', function () {
    $user = reportingUser('Draft Rep');
    $territory = reportingTerritory('Draft Terr');
    $patch = reportingPatch($territory, 'Draft Patch');
    $doctor = reportingDoctor($user, 'Dr Missed');
    $patch->companies()->attach($doctor->id, ['sequence_no' => 1]);
    $this->actingAs($user);

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $user->id,
        'month' => '2026-08',
        'approval_status' => 'approved',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $detail = SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => '2026-08-10',
        'territory_id' => $territory->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $detail->patches()->attach($patch->id);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/tour-plan-draft?month=2026-09')
        ->assertSuccessful()
        ->assertJsonPath('data.based_on_month', '2026-08')
        ->assertJsonPath('data.days.0.date', '2026-09-10')
        ->assertJsonPath('data.uncovered_accounts.0.name', 'Dr Missed');

    expect(SalesTourPlan::query()->where('month', 'like', '2026-09%')->count())->toBe(0);
});

it('suggests a sample quantity that stays inside the account limit', function () {
    $user = reportingUser('Sample Rep');
    $territory = reportingTerritory('Sample Terr');
    $patch = reportingPatch($territory, 'Sample Patch');
    $doctor = reportingDoctor($user, 'Dr Sample');
    $item = reportingSampleItem('SAMPLE', 'Demo Strip');
    $this->actingAs($user);

    SgipLimit::query()->create([
        'applies_to' => 'account',
        'applies_to_id' => $doctor->id,
        'item_type' => 'sample',
        'period' => 'monthly',
        'max_quantity' => 5,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $past = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'VIS-SGIP-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today()->subDays(2),
        'visit_type' => 'unplanned',
        'visit_status' => 'completed',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));
    $past->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);

    $distribution = SgipDistribution::query()->create([
        'user_id' => $user->id,
        'employee_id' => $user->employee->id,
        'account_master_id' => $doctor->id,
        'territory_id' => $territory->id,
        'visit_id' => $past->id,
        'visit_date' => today()->subDays(2),
        'approval_status' => 'approved',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    SgipDistributionItem::query()->create([
        'sgip_distribution_id' => $distribution->id,
        'item_master_id' => $item->id,
        'quantity' => 4,
        'unit_value' => 10,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $visit = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'VIS-SUG-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'draft',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));
    $visit->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/visits/'.$visit->id.'/sgip/suggestions')
        ->assertSuccessful()
        ->assertJsonPath('data.suggestions.0.item_name', 'Demo Strip')
        ->assertJsonPath('data.suggestions.0.quantity', 1)
        ->assertJsonPath('data.limits.0.used_quantity', 4)
        ->assertJsonPath('data.limits.0.remaining_quantity', 1);
});

it('writes a follow-up script from the recorded interaction', function () {
    $user = reportingUser('Script Rep');
    $contact = reportingContact('Meera', 'Patel');
    $this->actingAs($user);
    $status = reportingLeadStatus('Open');
    $lead = Lead::query()->create([
        'reference_code' => 'LD-'.fake()->unique()->numerify('####'),
        'owner_id' => $user->id,
        'transaction_date' => today(),
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $followUp = FollowUp::query()->create([
        'followupable_type' => Lead::class,
        'followupable_id' => $lead->id,
        'user_id' => $user->id,
        'to_whom' => $contact->id,
        'follow_up_date' => now()->subDays(3),
        'interaction' => 'Asked for a price list',
        'outcome' => 'Will call back',
        'next_follow_up_date' => now()->subDay(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/follow-ups/'.$followUp->id.'/script')
        ->assertSuccessful()
        ->assertJsonPath('data.script', 'Hello Meera Patel. Following up on: Asked for a price list. Last outcome: Will call back.')
        ->assertJsonPath('data.suggested_date', today()->addDays(2)->toDateString());

    Sanctum::actingAs(reportingUser('Script Other'));
    $this->getJson('/api/v1/follow-ups/'.$followUp->id.'/script')->assertNotFound();
});

it('orders today stops from the first sequence then the nearest coordinates', function () {
    $user = reportingUser('Route Rep');
    $territory = reportingTerritory('Route Terr');
    $this->actingAs($user);

    $stops = [
        ['name' => 'Dr Bee', 'sequence' => 1, 'latitude' => 19.00, 'longitude' => 72.10],
        ['name' => 'Dr Aye', 'sequence' => 2, 'latitude' => 19.00, 'longitude' => 72.00],
        ['name' => 'Dr Cee', 'sequence' => 3, 'latitude' => 19.00, 'longitude' => 73.50],
    ];

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $user->id,
        'month' => now()->format('Y-m'),
        'approval_status' => 'approved',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $detail = SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => today(),
        'territory_id' => $territory->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    foreach ($stops as $stop) {
        $pin = CityPinCode::query()->create([
            'pin_code' => fake()->unique()->numberBetween(100000, 999999),
            'area_town' => $stop['name'],
            'latitude' => $stop['latitude'],
            'longitude' => $stop['longitude'],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $patch = reportingPatch($territory, $stop['name'].' Patch');
        $patch->update(['city_pin_code_id' => $pin->id]);
        $doctor = reportingDoctor($user, $stop['name']);
        $patch->companies()->attach($doctor->id, ['sequence_no' => $stop['sequence']]);
        $detail->patches()->attach($patch->id);
    }

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/day-route')
        ->assertSuccessful()
        ->assertJsonPath('data.method', 'nearest_neighbor')
        ->assertJsonPath('data.stops.0.name', 'Dr Bee')
        ->assertJsonPath('data.stops.1.name', 'Dr Aye')
        ->assertJsonPath('data.stops.2.name', 'Dr Cee');
});

it('tells the owner to call a new lead and visit a deal that closes soon', function () {
    $user = reportingUser('Action Rep');
    $this->actingAs($user);
    $leadStatus = reportingLeadStatus('Open');
    $stage = reportingDealStage('Negotiation');

    $lead = Lead::query()->create([
        'reference_code' => 'LD-'.fake()->unique()->numerify('####'),
        'owner_id' => $user->id,
        'transaction_date' => today(),
        'status_id' => $leadStatus->id,
        'status_type' => LeadStatus::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $deal = Deal::query()->create([
        'reference_code' => 'DL-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Clinic Order',
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'amount' => 1000,
        'expected_revenue' => 1000,
        'expected_close_date' => today()->addDays(3),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$lead->id.'/next-action')
        ->assertSuccessful()
        ->assertJsonPath('data.action', 'call');

    $this->getJson('/api/v1/deals/'.$deal->id.'/next-action')
        ->assertSuccessful()
        ->assertJsonPath('data.action', 'visit')
        ->assertJsonPath('data.suggested_date', today()->addDays(3)->toDateString());

    Sanctum::actingAs(reportingUser('Action Other'));
    $this->getJson('/api/v1/leads/'.$lead->id.'/next-action')->assertNotFound();
});

it('shows today priorities on the sales assistant page', function () {
    $user = reportingUser('Assistant Rep');
    $territory = reportingTerritory('Assistant Terr');
    $patch = reportingPatch($territory, 'Assistant Patch');
    $doctor = reportingDoctor($user, 'Dr Planned');
    $patch->companies()->attach($doctor->id, ['sequence_no' => 1]);
    $this->actingAs($user);

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $user->id,
        'month' => now()->format('Y-m'),
        'approval_status' => 'approved',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $detail = SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => today(),
        'territory_id' => $territory->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $detail->patches()->attach($patch->id);

    livewire(SalesAssistant::class)
        ->assertSee('Dr Planned')
        ->assertSee('Pre-call briefing');

    $visit = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'VIS-WID-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today()->subDay(),
        'visit_type' => 'unplanned',
        'visit_status' => 'completed',
        'approval_status' => 'draft',
        'remarks' => 'Asked about samples',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));
    $visit->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);
    $current = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'VIS-CUR-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'draft',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));
    $current->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);

    livewire(VisitPreCallBriefingWidget::class, ['visitId' => $current->id])
        ->assertSee('Asked about samples');
});
