<?php

use App\Filament\Resources\Approvals\Pages\ViewApproval;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\SalesTourPlan;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders approval snapshots without a syntax highlighting dependency', function (?array $snapshots) {
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

    foreach (['selected_steps', 'selected_approvers', 'submitted_record_summary'] as $name) {
        $page->assertSee(json_encode($approval->getAttribute($name) ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    $page->assertDontSee('<script>alert(1)</script>', escape: false);
})->with([
    'nested snapshots' => [[
        'steps' => [['step_order' => 1, 'routing' => ['scope' => 'self', 'required' => true]]],
        'approvers' => [['user_id' => 42, 'name' => 'Manager & Reviewer']],
        'summary' => ['id' => 123, 'notes' => '<script>alert(1)</script>', 'details' => ['amount' => 250, 'label' => 'Résumé']],
    ]],
    'empty snapshots' => [[]],
    'legacy null snapshots' => [null],
]);
