<?php

use App\Models\Organization;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires sign-in, document permission, record visibility and the CRM module for PDFs', function (string $action) {
    $owner = grantPagePermissions(reportingUser('PDF Owner'), 'ViewAny:Quote', 'View:Quote');
    $other = grantPagePermissions(reportingUser('PDF Other'), 'ViewAny:Quote', 'View:Quote');
    $document = Quote::withoutEvents(fn (): Quote => Quote::forceCreate([
        'document_number' => 'PDF-TEST-1',
        'account_master_id' => reportingDoctor($owner, 'PDF Account')->id,
        'contact_detail_id' => reportingContact('PDF', 'Contact')->id,
        'date' => today(),
        'status' => 'draft',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]));
    $url = route('sales-documents.'.$action, ['type' => 'quote', 'id' => $document->id]);

    $this->get($url)->assertUnauthorized();
    $this->actingAs(reportingUser('PDF No Permission'))->get($url)->assertForbidden();
    $this->actingAs($other)->get($url)->assertNotFound();

    $organization = Organization::query()->create([
        'name' => 'PDF Organization',
        'metadata' => ['company_setup' => ['organization_saved' => true]],
    ]);
    $this->actingAs($owner)->get($url)->assertForbidden();

    $organization->update(['metadata' => [
        'company_setup' => ['organization_saved' => true],
        'optional_modules' => ['crm' => ['enabled' => true]],
    ]]);
    $this->get($url)->assertSuccessful()->assertHeader('Content-Type', 'application/pdf');
})->with(['preview', 'download']);
