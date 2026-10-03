<?php

use App\Filament\Pages\Reports\DealPipeline;
use App\Filament\Pages\Reports\LeadPipeline;
use App\Filament\Pages\Reports\OverdueFollowUps;
use App\Filament\Pages\Reports\ReportsOverview;
use App\Filament\Pages\Reports\SalesDocumentRegister;
use App\Models\Deal;
use App\Models\EmploymentDetail;
use App\Models\FollowUp;
use App\Models\FollowUpStatus;
use App\Models\JobRole;
use App\Models\Lead;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\TypeMaster;
use App\Services\Reports\LeadPipelineQuery;
use App\Services\Reports\OverdueFollowUpQuery;
use App\Services\Reports\SalesDocumentRegisterQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('lists visible leads on the pipeline', function () {
    $territory = reportingTerritory('Pipeline North');
    $rep = reportingUser('LeadRep');
    $other = reportingUser('HiddenLead');
    $status = reportingLeadStatus('Qualified');

    $this->actingAs($rep);

    $visibleAccount = reportingDoctor($rep, 'Visible Pipeline Account');
    $hiddenAccount = reportingDoctor($other, 'Hidden Pipeline Account');

    Lead::forceCreate([
        'owner_id' => $rep->id,
        'transaction_date' => today(),
        'territory_id' => $territory->id,
        'account_master_id' => $visibleAccount->id,
        'annual_revenue' => 75000,
        'status_id' => $status->id,
        'status_type' => $status::class,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    Lead::forceCreate([
        'owner_id' => $other->id,
        'transaction_date' => today(),
        'territory_id' => $territory->id,
        'account_master_id' => $hiddenAccount->id,
        'annual_revenue' => 12000,
        'status_id' => $status->id,
        'status_type' => $status::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    expect(app(LeadPipelineQuery::class)->builder()->applyVisibility('Lead')->count())->toBe(1);

    livewire(LeadPipeline::class)
        ->assertSuccessful()
        ->assertSee('Visible Pipeline Account')
        ->assertSee($status->name)
        ->assertDontSee('Hidden Pipeline Account');
});

it('includes owned leads when territory and ou visibility add an exists clause', function () {
    $rep = reportingUser('OwnedLeadRep');
    $other = reportingUser('OtherLeadOwner');
    $status = reportingLeadStatus('Open');

    foreach (['ViewOwn:Lead', 'ViewOwnTerritory:Lead', 'ViewOwnOU:Lead'] as $permission) {
        $rep->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $type = TypeMaster::query()->create([
        'name' => 'Organisation Unit',
        'description' => 'Lead visibility OU',
    ]);

    $ou = OrganizationalUnit::query()->create([
        'name' => 'Lead Division',
        'code' => 'LEAD-DIV',
        'type_master_id' => $type->id,
        'is_active' => true,
    ]);

    $employment = EmploymentDetail::query()->create([
        'employee_id' => $rep->employee->id,
    ]);
    $employment->organizationalUnits()->attach($ou->id, ['is_primary' => true]);

    $territory = reportingTerritory('Owned Lead Territory');
    $territory->update(['division_ou_id' => $ou->id]);

    $role = JobRole::query()->create(['name' => 'Representative', 'code' => 'LDR', 'level' => 1]);
    $position = Position::query()->create([
        'name' => 'Lead Rep Position',
        'code' => 'LDRPOS',
        'job_role_id' => $role->id,
    ]);
    $position->territories()->attach($territory->id);
    $rep->employee->positions()->attach($position->id, ['is_primary' => true]);

    $ownedAccount = reportingDoctor($rep, 'Owned Pipeline Account');
    $hiddenAccount = reportingDoctor($other, 'Unrelated Pipeline Account');

    $this->actingAs($rep->fresh());

    Lead::forceCreate([
        'owner_id' => $rep->id,
        'transaction_date' => today(),
        'territory_id' => $territory->id,
        'account_master_id' => $ownedAccount->id,
        'annual_revenue' => 41000,
        'status_id' => $status->id,
        'status_type' => $status::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    Lead::forceCreate([
        'owner_id' => $other->id,
        'transaction_date' => today(),
        'account_master_id' => $hiddenAccount->id,
        'annual_revenue' => 900,
        'status_id' => $status->id,
        'status_type' => $status::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    expect(app(LeadPipelineQuery::class)->builder()->applyVisibility('Lead')->count())->toBe(1);

    livewire(LeadPipeline::class)
        ->assertSuccessful()
        ->assertSee('Owned Pipeline Account')
        ->assertDontSee('Unrelated Pipeline Account');
});

it('lists visible deals on the pipeline', function () {
    $rep = reportingUser('DealRep');
    $other = reportingUser('HiddenDeal');
    $stage = reportingDealStage('Negotiation');

    $this->actingAs($rep);

    Deal::forceCreate([
        'owner_id' => $rep->id,
        'deal_name' => 'Visible Hospital Deal',
        'transaction_date' => today(),
        'amount' => 250000,
        'expected_revenue' => 250000,
        'expected_close_date' => today()->addDays(21),
        'status_id' => $stage->id,
        'status_type' => $stage::class,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    Deal::forceCreate([
        'owner_id' => $other->id,
        'deal_name' => 'Hidden Clinic Deal',
        'transaction_date' => today(),
        'amount' => 9000,
        'expected_revenue' => 9000,
        'expected_close_date' => today()->addDays(10),
        'status_id' => $stage->id,
        'status_type' => $stage::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    livewire(DealPipeline::class)
        ->assertSuccessful()
        ->assertSee('Visible Hospital Deal')
        ->assertSee($stage->name)
        ->assertDontSee('Hidden Clinic Deal');
});

it('lists visible quotes and orders on the sales document register', function () {
    $rep = reportingUser('DocRep');
    $other = reportingUser('HiddenDoc');
    $account = reportingDoctor($rep, 'Visible Clinic Account');
    $contact = reportingContact('Priya', 'Shah');

    $this->actingAs($rep);

    $visibleQuoteNumber = 'QT-VIS-'.fake()->unique()->numerify('####');
    $hiddenQuoteNumber = 'QT-HID-'.fake()->unique()->numerify('####');
    $visibleOrderNumber = 'SO-VIS-'.fake()->unique()->numerify('####');

    Quote::forceCreate([
        'document_number' => $visibleQuoteNumber,
        'contact_detail_id' => $contact->id,
        'account_master_id' => $account->id,
        'date' => today(),
        'status' => 'sent',
        'total' => 18000,
        'sales_person_id' => $rep->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    Quote::withoutEvents(function () use ($hiddenQuoteNumber, $contact, $account, $other): void {
        Quote::forceCreate([
            'document_number' => $hiddenQuoteNumber,
            'contact_detail_id' => $contact->id,
            'account_master_id' => $account->id,
            'date' => today(),
            'status' => 'draft',
            'total' => 44000,
            'sales_person_id' => $other->id,
            'created_by' => $other->id,
            'updated_by' => $other->id,
        ]);
    });

    SalesOrder::forceCreate([
        'document_number' => $visibleOrderNumber,
        'contact_detail_id' => $contact->id,
        'account_master_id' => $account->id,
        'date' => today(),
        'status' => 'accepted',
        'total' => 22000,
        'sales_person_id' => $rep->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    expect(app(SalesDocumentRegisterQuery::class)->builder()->count())->toBe(2);

    livewire(SalesDocumentRegister::class)
        ->assertSuccessful()
        ->assertSee($visibleQuoteNumber)
        ->assertSee($visibleOrderNumber)
        ->assertDontSee($hiddenQuoteNumber);
});

it('lists overdue open follow-ups and hides closed ones', function () {
    $rep = reportingUser('FollowRep');
    $other = reportingUser('HiddenFollow');
    $status = reportingLeadStatus('Open');

    $this->actingAs($rep);

    $ownLead = Lead::forceCreate([
        'owner_id' => $rep->id,
        'transaction_date' => today()->subWeek(),
        'status_id' => $status->id,
        'status_type' => $status::class,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $closed = FollowUpStatus::query()->create([
        'name' => 'Completed',
    ]);

    FollowUp::forceCreate([
        'followupable_id' => $ownLead->id,
        'followupable_type' => Lead::class,
        'user_id' => $rep->id,
        'follow_up_date' => now()->subDays(3),
        'next_follow_up_date' => now()->subDay(),
        'interaction' => 'Visible overdue call',
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    FollowUp::forceCreate([
        'followupable_id' => $ownLead->id,
        'followupable_type' => Lead::class,
        'user_id' => $rep->id,
        'follow_up_date' => now()->subDays(5),
        'next_follow_up_date' => now()->subDays(2),
        'interaction' => 'Closed overdue call',
        'follow_up_status_id' => $closed->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    FollowUp::forceCreate([
        'followupable_id' => $ownLead->id,
        'followupable_type' => Lead::class,
        'user_id' => $other->id,
        'follow_up_date' => now()->subDays(4),
        'next_follow_up_date' => now()->subDay(),
        'interaction' => 'Hidden overdue call',
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    expect(app(OverdueFollowUpQuery::class)->builder()->applyVisibility('FollowUp')->count())->toBe(1);

    livewire(OverdueFollowUps::class)
        ->assertSuccessful()
        ->assertSee('Visible overdue call')
        ->assertDontSee('Closed overdue call')
        ->assertDontSee('Hidden overdue call');
});

it('exposes phase 3 reports on the overview catalog', function () {
    $user = reportingUser('SalesCatalog');

    $this->actingAs($user);

    livewire(ReportsOverview::class)
        ->assertSuccessful()
        ->assertSee('Lead pipeline')
        ->assertSee('Deal pipeline')
        ->assertSee('Sales document register')
        ->assertSee('Overdue follow-ups');
});
