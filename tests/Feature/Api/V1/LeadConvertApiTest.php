<?php

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function leadConvertGrant(User $user, array $extra = []): void
{
    $user->givePermissionTo(array_map(
        fn (string $name) => Permission::findOrCreate($name, 'web'),
        ['ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead', ...$extra],
    ));
}

function leadConvertLead(User $owner, array $attributes = []): Lead
{
    return Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Convert me',
        'annual_revenue' => 1500,
        'status_id' => reportingLeadStatus('Open')->id,
        'status_type' => LeadStatus::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
        ...$attributes,
    ]);
}

it('converts a visible lead into a deal without changing the account', function () {
    $user = reportingUser('LeadConvertAllowed');
    leadConvertGrant($user, ['Update:Lead', 'Create:Deal', 'ViewAny:Deal', 'View:Deal', 'ViewOwn:Deal']);
    $converted = LeadStatus::query()->create(['name' => 'Converted']);
    $stage = DealStage::query()->create(['name' => 'Negotiation/Review']);
    $account = reportingDoctor($user, 'Dr Convert');
    $accountBefore = $account->only(['type_master_id', 'account_code']);
    $contact = reportingContact('Convert', 'Contact');
    $lead = leadConvertLead($user, [
        'account_master_id' => $account->id,
        'contact_detail_id' => $contact->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$lead->id)
        ->assertSuccessful()
        ->assertJsonPath('data.can_convert', true);

    $response = $this->postJson('/api/v1/leads/'.$lead->id.'/convert')
        ->assertSuccessful()
        ->assertJsonPath('data.lead.status_id', $converted->id)
        ->assertJsonPath('data.lead.can_convert', false)
        ->assertJsonPath('data.deal_visible', true)
        ->assertJsonPath('data.deal.deal_name', 'Dr Convert')
        ->assertJsonPath('data.deal.account_master_id', $account->id);

    $deal = Deal::query()->findOrFail($response->json('data.deal.id'));

    expect((int) $deal->owner_id)->toBe($user->id)
        ->and((int) $deal->contact_detail_id)->toBe($contact->id)
        ->and((float) $deal->amount)->toBe(1500.0)
        ->and((int) $deal->status_id)->toBe($stage->id)
        ->and($deal->status_type)->toBe(DealStage::class)
        ->and($account->fresh()->only(['type_master_id', 'account_code']))->toBe($accountBefore);

    $this->assertDatabaseHas('lead_activities', [
        'lead_id' => $lead->id,
        'user_id' => $user->id,
        'activity_type' => 'Lead Converted',
    ]);
});

it('requires both Update:Lead and Create:Deal', function (array $extra) {
    $user = reportingUser('LeadConvertDenied');
    leadConvertGrant($user, $extra);
    LeadStatus::query()->create(['name' => 'Converted']);
    $lead = leadConvertLead($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads/'.$lead->id.'/convert')->assertForbidden();

    expect(Deal::query()->count())->toBe(0);
})->with([
    'missing Create:Deal' => [['Update:Lead']],
    'missing Update:Lead' => [['Create:Deal']],
]);

it('returns 404 when converting a hidden lead', function () {
    $user = reportingUser('LeadConvertHidden');
    $other = reportingUser('LeadConvertHiddenOther');
    leadConvertGrant($user, ['Update:Lead', 'Create:Deal']);
    LeadStatus::query()->create(['name' => 'Converted']);
    $lead = leadConvertLead($other);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads/'.$lead->id.'/convert')->assertNotFound();

    expect(Deal::query()->count())->toBe(0);
});

it('refuses to convert a lead twice or without the Converted status and deal stage', function () {
    $user = reportingUser('LeadConvertTwice');
    leadConvertGrant($user, ['Update:Lead', 'Create:Deal']);
    $lead = leadConvertLead($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads/'.$lead->id.'/convert')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lead']);

    LeadStatus::query()->create(['name' => 'Converted']);

    $this->postJson('/api/v1/leads/'.$lead->id.'/convert')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lead']);
    expect(Deal::query()->count())->toBe(0);

    DealStage::query()->create(['name' => 'Negotiation/Review']);

    $this->postJson('/api/v1/leads/'.$lead->id.'/convert')
        ->assertSuccessful()
        ->assertJsonPath('data.deal_visible', false)
        ->assertJsonPath('data.deal.deal_name', 'Unnamed Deal');

    $this->postJson('/api/v1/leads/'.$lead->id.'/convert')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lead']);

    expect(Deal::query()->count())->toBe(1)
        ->and(LeadActivity::query()->where('activity_type', 'Lead Converted')->count())->toBe(1);
});
