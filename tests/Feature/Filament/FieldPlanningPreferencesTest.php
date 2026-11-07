<?php

use App\Enums\FieldPlanningMode;
use App\Filament\Pages\FieldPlanningPreferences;
use App\Models\FieldPlanningRevision;
use App\Models\VisitPreference;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = reportingUser('PlanningAdmin');
    Permission::findOrCreate(FieldPlanningSettingsService::PERMISSION, 'web');
    $this->admin->givePermissionTo(FieldPlanningSettingsService::PERMISSION);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->settings = app(FieldPlanningSettingsService::class);
    $this->proposal = [...$this->settings->initialProposal(), 'reason' => 'Simplify small-team onboarding'];
});

it('previews a preset without saving or losing the draft reason and date', function () {
    $date = today()->addDays(5)->toDateString();
    Livewire::test(FieldPlanningPreferences::class)
        ->assertSuccessful()
        ->fillForm(['reason' => 'Keep this reason', 'proposed_effective_on' => $date])
        ->callAction('preset_formal_mtp')
        ->assertFormSet([
            'mode' => 'formal_mtp', 'rules.require_plan' => true,
            'rules.unplanned_calls' => 'block', 'reason' => 'Keep this reason',
            'proposed_effective_on' => $date,
        ]);
    expect(FieldPlanningRevision::query()->count())->toBe(0);
});

it('saves a revision through the page and reloads it with history', function () {
    Livewire::test(FieldPlanningPreferences::class)
        ->fillForm($this->proposal)->call('save')->assertHasNoFormErrors()->assertNotified()
        ->assertSet('version', 1)->assertSee('Revision 1')->assertSee($this->proposal['reason']);
    $first = FieldPlanningRevision::query()->sole();
    expect($first->created_by)->toBe($this->admin->id)
        ->and($first->mode)->toBe(FieldPlanningMode::DirectDcr)
        ->and($first->previous_revision_id)->toBeNull();

    Livewire::test(FieldPlanningPreferences::class)
        ->assertSet('version', 1)->assertFormSet(['mode' => 'direct_dcr', 'reason' => ''])
        ->fillForm(['reason' => 'Add a reason for unplanned calls', 'rules.unplanned_calls' => 'allow_with_reason'])
        ->call('save')->assertHasNoFormErrors();
    $second = $this->settings->latest($this->admin);
    expect($second->version)->toBe(2)->and($second->previous_revision_id)->toBe($first->id)
        ->and($first->fresh()->rules['unplanned_calls'])->toBe('allow')
        ->and($second->rules['unplanned_calls'])->toBe('allow_with_reason');
});

it('rejects a stale page without overwriting the newer proposal', function () {
    $stale = Livewire::test(FieldPlanningPreferences::class)->fillForm($this->proposal);
    $new = $this->settings->saveProposal($this->admin, $this->proposal, 0);
    $stale->call('save')->assertHasFormErrors(['mode']);
    expect(FieldPlanningRevision::query()->count())->toBe(1)
        ->and($this->settings->latest($this->admin)->id)->toBe($new->id);
});

it('rejects inconsistent and malformed proposals without writing a revision', function (array $overrides) {
    $proposal = array_replace_recursive($this->proposal, $overrides);
    expect(fn () => $this->settings->saveProposal($this->admin, $proposal, 0))
        ->toThrow(ValidationException::class);
    expect(FieldPlanningRevision::query()->count())->toBe(0);
})->with([
    'approval without a plan' => [['rules' => ['require_approved_plan' => true]]],
    'patch without territory' => [['rules' => ['require_patch' => true]]],
    'direct requires a plan' => [['rules' => ['require_plan' => true]]],
    'formal without a plan' => [['mode' => 'formal_mtp']],
    'wrong lightweight mode' => [['rules' => ['enable_flexible_plans' => true]]],
    'unknown mode' => [['mode' => 'unknown']],
    'legacy is not a proposed preset' => [['mode' => 'legacy']],
    'unknown rule' => [['rules' => ['activate_now' => true]]],
    'invalid boolean' => [['rules' => ['require_plan' => 'yes']]],
    'invalid exception policy' => [['rules' => ['unplanned_calls' => 'anything']]],
    'blank reason' => [['reason' => '   ']],
    'past requested date' => [['proposed_effective_on' => '2000-01-01']],
]);

it('checks permission at render and again at save after revocation', function () {
    $page = Livewire::test(FieldPlanningPreferences::class)->fillForm($this->proposal);
    $this->admin->revokePermissionTo(FieldPlanningSettingsService::PERMISSION);
    $this->admin->unsetRelation('permissions');
    $page->call('save')->assertForbidden();
    Livewire::test(FieldPlanningPreferences::class)->assertForbidden();
    expect(fn () => $this->settings->saveProposal($this->admin, $this->proposal, 0))->toThrow(HttpException::class);
    expect(FieldPlanningRevision::query()->count())->toBe(0);
});

it('does not activate a proposal or modify existing visit controls when its requested date arrives', function () {
    $preference = VisitPreference::current();
    $preference->update(['allow_backdated_dcr' => true, 'dcr_backdate_days' => 7]);
    $before = $preference->fresh()->getAttributes();
    $capabilities = app(FieldPlanningService::class)->capabilities();
    $date = today()->addDays(2);
    $this->settings->saveProposal($this->admin, [...$this->proposal, 'proposed_effective_on' => $date->toDateString()], 0);
    $this->travelTo($date->addDay());
    expect(app(FieldPlanningService::class)->capabilities())->toBe($capabilities)
        ->and($preference->fresh()->getAttributes())->toBe($before);
});

it('keeps saved history immutable through model edits and deletions', function () {
    $revision = $this->settings->saveProposal($this->admin, $this->proposal, 0);
    expect(fn () => $revision->update(['reason' => 'Rewrite history']))->toThrow(LogicException::class);
    expect(fn () => $revision->fresh()->delete())->toThrow(LogicException::class);
    expect($revision->fresh()->reason)->toBe($this->proposal['reason']);
});

it('enforces unique revision versions at the database boundary', function () {
    $first = $this->settings->saveProposal($this->admin, $this->proposal, 0);
    expect(fn () => FieldPlanningRevision::query()->create([
        ...$this->proposal, 'version' => 1, 'created_by' => $this->admin->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
    expect(FieldPlanningRevision::query()->count())->toBe(1)
        ->and($this->settings->latest($this->admin)->id)->toBe($first->id);
});

it('activates only the saved revision through the Admin page action', function () {
    $page = Livewire::test(FieldPlanningPreferences::class)->fillForm($this->proposal)->call('save')->assertHasNoFormErrors();
    $page->callAction('activate')->assertNotified();
    expect(app(FieldPlanningService::class)->active()->version)->toBe(1)
        ->and(app(FieldPlanningService::class)->capabilities()['supports_direct_calls'])->toBeTrue();
});
