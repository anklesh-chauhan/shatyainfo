<?php

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\Company;
use App\Models\ContactDetail;
use App\Models\GstPan;
use App\Models\IndustryType;
use App\Models\TypeMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('scopes directory search, profile editing and industry options', function () {
    $other = reportingUser('Company Outsider');
    Sanctum::actingAs($other);
    $hidden = Company::query()->create(['name' => 'Hidden Clinic']);
    $hiddenIndustry = IndustryType::query()->create(['name' => 'Hidden Industry']);
    $actor = grantPagePermissions(reportingUser('Company Editor'), 'ViewAny:Company', 'View:Company', 'Create:Company', 'Update:Company',
        'ViewAny:IndustryType', 'View:IndustryType');
    Sanctum::actingAs($actor);
    $industry = IndustryType::query()->create(['name' => 'Medical']);
    $url = '/api/v1/companies';
    $input = ['name' => 'Visible Clinic', 'phone_number' => '9000012345', 'email' => 'clinic@example.test', 'secondary_email' => 'backup@example.test'];
    $this->getJson($url.'/options/industries')->assertOk()->assertJsonPath('data', [['id' => $industry->id, 'label' => 'Medical']]);
    $this->postJson($url, ['name' => 'Missing details'])->assertUnprocessable()->assertJsonValidationErrors(['phone_number', 'email', 'secondary_email']);
    $this->postJson($url, [...$input, 'industry_type_id' => $hiddenIndustry->id])->assertUnprocessable()->assertJsonValidationErrors('industry_type_id');
    $id = $this->postJson($url, [...$input, 'industry_type_id' => $industry->id, 'description' => 'Private detail'])->assertCreated()
        ->assertJsonPath('data.industry.label', 'Medical')->json('data.id');
    $this->getJson($url.'?search=backup')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.description')->assertJsonPath('register.can_create', true);
    $this->getJson($url.'?search=Hidden')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url.'/'.$hidden->id)->assertNotFound();
    $this->patchJson($url.'/'.$hidden->id, ['name' => 'Invalid'])->assertNotFound();
    $this->patchJson($url.'/'.$id, ['description' => 'Revised'])->assertOk()
        ->assertJsonPath('data.description', 'Revised')->assertJsonPath('data.secondary_email', $input['secondary_email']);
});

it('separates company-owned addresses from account addresses stamped with its company id', function () {
    $actor = grantPagePermissions(fieldSalesUser('Linked Company Rep'), 'ViewAny:Company', 'View:Company', 'Update:Company', 'Delete:Company');
    Sanctum::actingAs($actor);
    $type = TypeMaster::query()->create(['name' => 'Company Dealer', 'typeable_type' => AccountMaster::class, 'creates_company' => true]);
    $account = AccountMaster::query()->create(['name' => 'Account Name', 'type_master_id' => $type->id, 'owner_id' => $actor->id]);
    $company = Company::query()->where('account_master_id', $account->id)->sole();
    $accountAddress = $account->addresses()->create(['street' => 'Account address', 'pin_code' => 380001, 'company_id' => $company->id]);
    $url = '/api/v1/companies/'.$company->id;
    $this->getJson($url)->assertOk()->assertJsonPath('data.account.name', 'Account Name')->assertJsonCount(0, 'data.addresses');
    $this->patchJson($url, ['account_master_id' => null])->assertUnprocessable()->assertJsonValidationErrors('account_master_id');
    $this->patchJson($url, ['name' => 'Company Name'])->assertOk()->assertJsonPath('data.can_delete', false);
    expect($account->fresh()->name)->toBe('Account Name');
    $this->patchJson($url.'/addresses/'.$accountAddress->id, ['street' => 'Wrong owner'])->assertNotFound();
    $this->deleteJson($url.'/addresses/'.$accountAddress->id)->assertNotFound();
    $first = $this->postJson($url.'/addresses', ['street' => 'Company North', 'pin_code' => '380003'])->assertCreated()->json('data.id');
    $second = $this->postJson($url.'/addresses', ['street' => 'Company South', 'pin_code' => '380004'])->assertCreated()->json('data.id');
    expect(Address::findOrFail($first)->address_type)->toBe('Company');
    $this->postJson($url.'/address-order', ['ids' => [$first, $accountAddress->id]])->assertConflict();
    $this->postJson($url.'/address-order', ['ids' => [$second, $first]])->assertOk();
    $this->getJson($url)->assertOk()->assertJsonPath('data.addresses.0.id', $second)->assertJsonPath('data.addresses.1.id', $first);
    $this->patchJson($url.'/addresses/'.$first, ['street' => 'Company West'])->assertOk()->assertJsonPath('data.street', 'Company West');
    $this->deleteJson($url.'/addresses/'.$first)->assertOk();
    GstPan::query()->create(['company_id' => $company->id, 'address_id' => $second, 'pan_number' => 'ABCDE1234F', 'gst_number' => '24ABCDE1234F1Z5']);
    $this->deleteJson($url.'/addresses/'.$second)->assertUnprocessable();
    expect($account->addresses()->sole()->id)->toBe($accountAddress->id);
    $this->postJson('/api/v1/companies/register-actions', ['ids' => [$company->id]])->assertUnprocessable();
});

it('creates a company from Contacts and protects linked and approved records in atomic batches', function () {
    $actor = grantPagePermissions(reportingUser('Company Contact'), 'ViewAny:Company', 'View:Company', 'Create:Company', 'Update:Company', 'Delete:Company');
    Sanctum::actingAs($actor);
    $inline = '/api/v1/contacts/options/companies';
    $this->postJson($inline, ['name' => 'Contact Company'])->assertForbidden();
    Sanctum::actingAs(grantPagePermissions($actor, 'ViewAny:ContactDetail', 'View:ContactDetail', 'Create:ContactDetail')->fresh());
    $this->getJson('/api/v1/contacts?register=1')->assertOk()->assertJsonFragment(['create_options' => ['companies']]);
    $linked = $this->postJson($inline, ['name' => 'Contact Company'])->assertCreated()->assertJsonPath('data.label', 'Contact Company')->json('data.id');
    $contact = $this->postJson('/api/v1/contacts', ['first_name' => 'Asha', 'mobile_number' => '9000000123', 'email' => 'asha@example.test', 'company_id' => $linked])
        ->assertCreated()->assertJsonPath('data.company.id', $linked)->json('data.id');
    $free = Company::query()->create(['name' => 'Unused Company']);
    $locked = Company::query()->create(['name' => 'Approved Company']);
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'active' => false]);
    $locked->approval()->create(['approval_flow_id' => $flow->id, 'requested_by' => $actor->id, 'module' => 'Company',
        'record_type' => Company::class, 'record_id' => $locked->id, 'approval_status' => 'approved']);
    $delete = '/api/v1/companies/register-actions';
    $this->postJson($delete, ['ids' => [$free->id, $linked]])->assertUnprocessable();
    $this->postJson($delete, ['ids' => [$free->id, $locked->id]])->assertUnprocessable();
    $this->patchJson('/api/v1/companies/'.$locked->id, ['name' => 'Blocked'])->assertUnprocessable();
    expect($free->fresh()->trashed())->toBeFalse();
    $this->postJson($delete, ['ids' => [$free->id]])->assertOk()->assertJsonPath('data.updated', 1);
    expect(ContactDetail::findOrFail($contact)->company_id)->toBe($linked)
        ->and(ApprovalSetting::supportsModule(Company::class))->toBeFalse();
});
