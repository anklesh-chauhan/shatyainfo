<?php

use App\Filament\Resources\Approvals\Pages\ViewApproval;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\SalesTourPlan;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders approval snapshots as readable details', function (?array $snapshots) {
    $user = grantPagePermissions(reportingUser('Approval Requester'), 'ViewAny:Approval', 'View:Approval');
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $record = SalesTourPlan::query()->create(['user_id' => $user->id, 'month' => '2026-11-01']);
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan']);
    $approval = Approval::query()->create([
        'approvable_type' => SalesTourPlan::class,
        'approvable_id' => $record->id,
        'approval_flow_id' => $flow->id,
        'module' => 'SalesTourPlan',
        'record_type' => SalesTourPlan::class,
        'record_id' => $record->id,
        'requested_by' => $user->id,
        'selected_steps' => $snapshots['steps'] ?? $snapshots,
        'selected_approvers' => $snapshots['approvers'] ?? $snapshots,
        'submitted_record_summary' => $snapshots['summary'] ?? $snapshots,
    ]);

    $page = Livewire::test(ViewApproval::class, ['record' => $approval->id])
        ->assertSuccessful()
        ->assertSee('Selected Routing')
        ->assertSee('Submitted Record');

    if (filled($snapshots)) {
        $page->assertSee('Reporting Manager')
            ->assertSee('All territories')
            ->assertSee('Optional step')
            ->assertSee('No')
            ->assertSee('24 hours')
            ->assertSee('Manager & Reviewer')
            ->assertSee('Original Manager')
            ->assertSee('Explicit reporting employee')
            ->assertSee('Reporting manager approvals')
            ->assertSee('November 2026')
            ->assertSee('Draft')
            ->assertSee('Résumé')
            ->assertSee('250')
            ->assertSee('Yes')
            ->assertSee('<script>alert(1)</script>')
            ->assertDontSee('approval_flow_step_id')
            ->assertDontSeeText('selected_approvers')
            ->assertDontSee('"attributes"')
            ->assertDontSee('App\\Models\\SalesTourPlan');
    } else {
        $page->assertSee('No routing steps recorded')
            ->assertSee('No approvers recorded')
            ->assertSee('No submitted details recorded');
    }

    $page->assertDontSee('<script>alert(1)</script>', escape: false);
})->with([
    'nested snapshots' => [[
        'steps' => [['approval_flow_step_id' => 91, 'step_order' => 1, 'routing_type' => 'reporting_manager', 'territory_scope' => 'all', 'can_skip' => false, 'sla_hours' => 24, 'reason' => 'Explicit reporting employee']],
        'approvers' => [['step_order' => 1, 'user_id' => 42, 'name' => 'Manager & Reviewer', 'original_name' => 'Original Manager', 'reason' => 'Explicit reporting employee', 'delegated_from_user_id' => 43]],
        'summary' => [
            'class' => SalesTourPlan::class,
            'id' => 123,
            'document_number' => null,
            'flow_name' => 'Reporting manager approvals',
            'employee_id' => 7,
            'attributes' => ['id' => 123, 'user_id' => 42, 'month' => '2026-11', 'approval_status' => 'draft', 'notes' => '<script>alert(1)</script>', 'details' => ['amount' => 250, 'label' => 'Résumé', 'required' => true]],
        ],
    ]],
    'empty snapshots' => [[]],
    'legacy null snapshots' => [null],
]);
