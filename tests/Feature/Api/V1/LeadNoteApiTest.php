<?php

use App\Models\Attachment;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadNote;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function leadNoteApiGrant(User $user): void
{
    $user->givePermissionTo(array_map(
        fn (string $name) => Permission::findOrCreate($name, 'web'),
        ['ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead'],
    ));
}

function leadNoteApiLead(User $owner): Lead
{
    return Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Notes lead',
        'status_id' => reportingLeadStatus('LN Lead')->id,
        'status_type' => LeadStatus::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function leadNoteApiRecord(Lead $lead, User $author, string $text, string $createdAt): LeadNote
{
    $note = LeadNote::query()->create([
        'lead_id' => $lead->id,
        'user_id' => $author->id,
        'note' => $text,
    ]);
    $note->forceFill(['created_at' => $createdAt])->save();

    return $note;
}

it('lists all notes of a visible lead newest first with attachments', function () {
    $user = reportingUser('LeadNoteApiViewer');
    $other = reportingUser('LeadNoteApiOther');
    leadNoteApiGrant($user);
    $lead = leadNoteApiLead($user);
    $otherLead = leadNoteApiLead($other);
    $older = leadNoteApiRecord($lead, $user, 'First visit notes', '2026-11-01 09:00:00');
    $newer = leadNoteApiRecord($lead, $other, 'Manager comment', '2026-11-02 09:00:00');
    $elsewhere = leadNoteApiRecord($otherLead, $user, 'Elsewhere', '2026-11-03 09:00:00');
    (new Attachment)->forceFill([
        'file_name' => 'brochure.pdf',
        'file_path' => 'lead-notes/brochure.pdf',
        'file_type' => 'pdf',
        'attachable_id' => $older->id,
        'attachable_type' => LeadNote::class,
    ])->save();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$lead->id.'/notes')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.note', 'Manager comment')
        ->assertJsonPath('data.0.user_name', $other->name)
        ->assertJsonPath('data.0.attachments', [])
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.1.created_at', '2026-11-01 09:00:00')
        ->assertJsonPath('data.1.attachments.0.file_name', 'brochure.pdf')
        ->assertJsonMissingPath('data.1.attachments.0.file_path')
        ->assertJsonMissing(['id' => $elsewhere->id]);

    $url = '/api/v1/leads/'.$lead->id.'/notes?per_page=1';
    $this->getJson($url.'&page=1')->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('meta.last_page', 2);
    $this->getJson($url.'&page=2')->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $older->id)
        ->assertJsonPath('data.0.attachments.0.file_name', 'brochure.pdf')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('links.next', null);
});

it('forbids lead notes without lead permissions', function () {
    $user = reportingUser('LeadNoteApiDenied');
    $lead = leadNoteApiLead($user);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$lead->id.'/notes')->assertForbidden();
});

it('adds a note as the caller and logs Note Added', function () {
    $user = reportingUser('LeadNoteApiCreate');
    $other = reportingUser('LeadNoteApiCreateOther');
    leadNoteApiGrant($user);
    $user->givePermissionTo(Permission::findOrCreate('Update:Lead', 'web'));
    $lead = leadNoteApiLead($user);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/leads/'.$lead->id.'/notes', [
        'note' => 'Doctor asked for samples',
        'user_id' => $other->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.lead_id', $lead->id)
        ->assertJsonPath('data.note', 'Doctor asked for samples')
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.user_name', $user->name)
        ->assertJsonPath('data.attachments', []);

    expect((int) LeadNote::query()->findOrFail($response->json('data.id'))->user_id)->toBe($user->id);

    $this->assertDatabaseHas('lead_activities', [
        'lead_id' => $lead->id,
        'user_id' => $user->id,
        'activity_type' => 'Note Added',
        'description' => "Note 'Doctor asked for samples' has been added to the lead",
    ]);
});

it('rejects notes without Update:Lead, on hidden leads, or when empty', function () {
    $user = reportingUser('LeadNoteApiCreateGuard');
    $other = reportingUser('LeadNoteApiCreateGuardOther');
    leadNoteApiGrant($user);
    $lead = leadNoteApiLead($user);
    $hiddenLead = leadNoteApiLead($other);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads/'.$lead->id.'/notes', ['note' => 'No update'])->assertForbidden();

    $user->givePermissionTo(Permission::findOrCreate('Update:Lead', 'web'));

    $this->postJson('/api/v1/leads/'.$hiddenLead->id.'/notes', ['note' => 'Hidden'])->assertNotFound();
    $this->postJson('/api/v1/leads/'.$hiddenLead->id.'/notes', [])->assertNotFound();

    $this->postJson('/api/v1/leads/'.$lead->id.'/notes', ['note' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['note' => 'Write a note.']);

    expect(LeadNote::query()->count())->toBe(0)
        ->and(LeadActivity::query()->count())->toBe(0);
});

it('returns 404 for notes of a hidden lead', function () {
    $user = reportingUser('LeadNoteApiHidden');
    $other = reportingUser('LeadNoteApiHiddenOther');
    leadNoteApiGrant($user);
    $hiddenLead = leadNoteApiLead($other);
    leadNoteApiRecord($hiddenLead, $other, 'Secret', '2026-11-01 09:00:00');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$hiddenLead->id.'/notes?page=2')->assertNotFound();
});
