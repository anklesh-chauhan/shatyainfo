<?php

use App\Filament\Resources\FieldDevices\Pages\ManageFieldDevices;
use App\Models\FieldDcrEntry;
use App\Models\FieldDevice;
use App\Models\SalesDcr;
use App\Models\VisitPreference;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rep = reportingUser('DeviceRep');
    $this->manager = reportingUser('DeviceManager');
    Permission::findOrCreate('Review:FieldSyncRecovery', 'web');
    $this->manager->givePermissionTo('Review:FieldSyncRecovery');
    VisitPreference::current()->update(['allow_backdated_dcr' => false, 'enforce_geo_fencing' => false]);
    $this->phone = (string) Str::uuid();
    $this->tablet = (string) Str::uuid();
    $this->today = today()->toDateString();
    $this->dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => $this->today, 'approval_status' => 'draft']);
    FieldDcrEntry::query()->create(['event_id' => (string) Str::uuid(), 'user_id' => $this->rep->id, 'sales_dcr_id' => $this->dcr->id,
        'category' => 'meeting', 'remarks' => 'Synchronized from the tablet', 'captured_at' => now()->subHour(), 'received_at' => now()]);
    Sanctum::actingAs($this->rep);
});

function reportDevice($test, string $device, string $name, array $pending): FieldDevice
{
    $test->putJson('/api/v1/field-devices/'.$device, ['name' => $name, 'platform' => 'android', 'pending' => $pending], ['X-Field-Device' => $device])->assertSuccessful();

    return FieldDevice::query()->where('device_id', $device)->sole();
}

it('blocks DCR closure while another registered device reports unsynchronized work for that date', function () {
    reportDevice($this, $this->phone, 'Field phone', [['date' => $this->today, 'count' => 2, 'oldest_captured_at' => now()->subHours(3)->toIso8601String()]]);
    $tablet = ['X-Field-Device' => $this->tablet];
    $this->getJson('/api/v1/dcrs/'.$this->dcr->id, $tablet)->assertSuccessful()
        ->assertJsonPath('data.other_device_pending', true)->assertJsonPath('data.other_devices.0.name', 'Field phone')->assertJsonPath('data.other_devices.0.pending_for_date', 2);
    $this->postJson('/api/v1/dcrs/'.$this->dcr->id.'/submit', [], $tablet)->assertUnprocessable()->assertJsonValidationErrors('field_devices');
    expect(fn () => $this->dcr->fresh()->prepareOperationalApproval())->toThrow(ValidationException::class);
    $this->getJson('/api/v1/dcrs/'.$this->dcr->id, ['X-Field-Device' => $this->phone])->assertSuccessful()->assertJsonPath('data.other_device_pending', false);
    reportDevice($this, $this->phone, 'Field phone', [['date' => today()->subDay()->toDateString(), 'count' => 1]]);
    $this->getJson('/api/v1/dcrs/'.$this->dcr->id, $tablet)->assertSuccessful()->assertJsonPath('data.other_device_pending', false)->assertJsonPath('data.other_devices.0.pending_for_date', 0);
    $this->postJson('/api/v1/dcrs/'.$this->dcr->id.'/submit', [], $tablet)->assertSuccessful()->assertJsonPath('data.approval_status', 'approved');
    expect(FieldDevice::query()->where('device_id', $this->phone)->sole()->pending_count)->toBe(1);
});

it('does not let the submitting device block itself and rejects malformed pending reports', function () {
    reportDevice($this, $this->phone, 'Field phone', [['date' => $this->today, 'count' => 3]]);
    $this->putJson('/api/v1/field-devices/'.$this->phone, ['name' => 'Field phone', 'pending' => [['date' => '2026-99-99', 'count' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('pending.0.date');
    $this->putJson('/api/v1/field-devices/'.$this->phone, ['name' => 'Field phone'])->assertUnprocessable()->assertJsonValidationErrors('pending');
    $this->putJson('/api/v1/field-devices/not-a-uuid', ['name' => 'Field phone', 'pending' => []])->assertNotFound();
    $this->postJson('/api/v1/dcrs/'.$this->dcr->id.'/submit', [], ['X-Field-Device' => $this->phone])->assertSuccessful();
});

it('requires an empty queue to close, a manager to retire a device with pending work, and reactivates reconnecting devices', function () {
    $phone = reportDevice($this, $this->phone, 'Field phone', [['date' => $this->today, 'count' => 1]]);
    $this->postJson('/api/v1/field-devices/'.$this->phone.'/close', ['pending' => [['date' => $this->today, 'count' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('pending');
    $this->postJson('/api/v1/field-devices/'.$this->tablet.'/close', ['pending' => []])->assertNotFound();
    $this->postJson('/api/v1/field-devices/'.$this->phone.'/close', ['pending' => []])->assertSuccessful()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.pending_count', 0);
    $this->postJson('/api/v1/dcrs/'.$this->dcr->id.'/submit', [], ['X-Field-Device' => $this->tablet])->assertSuccessful();

    $dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => today()->subDay()->toDateString(), 'approval_status' => 'draft']);
    FieldDcrEntry::query()->create(['event_id' => (string) Str::uuid(), 'user_id' => $this->rep->id, 'sales_dcr_id' => $dcr->id,
        'category' => 'training', 'remarks' => 'Yesterday', 'captured_at' => now()->subDay(), 'received_at' => now()]);
    VisitPreference::current()->update(['allow_backdated_dcr' => true, 'dcr_backdate_days' => 7]);
    reportDevice($this, $this->phone, 'Field phone', [['date' => $dcr->dcr_date->toDateString(), 'count' => 4]]);
    expect($phone->fresh()->status)->toBe('active')->and($phone->fresh()->history)->toHaveCount(2)->and($phone->fresh()->history[1]['event'])->toBe('reactivated');
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit', [], ['X-Field-Device' => $this->tablet])->assertUnprocessable()->assertJsonValidationErrors('field_devices');

    $this->getJson('/api/v1/field-devices')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_retire', false);
    $this->postJson('/api/v1/field-devices/'.$phone->id.'/retire', ['reason' => 'Phone was lost on the route'])->assertForbidden();
    $this->getJson('/api/v1/field-devices?user_id='.$this->rep->id)->assertForbidden();

    Sanctum::actingAs(reportingUser('UnrelatedDeviceUser'));
    $this->getJson('/api/v1/field-devices')->assertSuccessful()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/field-devices/'.$phone->id.'/retire', ['reason' => 'Trying another account'])->assertNotFound();

    Sanctum::actingAs($this->manager);
    $this->getJson('/api/v1/field-devices?user_id='.$this->rep->id)->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_retire', true);
    $this->postJson('/api/v1/field-devices/'.$phone->id.'/retire', ['reason' => 'Rep'])->assertUnprocessable();
    $this->postJson('/api/v1/field-devices/'.$phone->id.'/retire', ['reason' => 'Phone lost on the rural route; samples reconciled physically'])->assertSuccessful()->assertJsonPath('data.status', 'retired');
    $this->postJson('/api/v1/field-devices/'.$phone->id.'/retire', ['reason' => 'Retire the same device again'])->assertConflict();
    expect($phone->fresh()->close_reason)->toContain('rural route')->and($phone->fresh()->closed_by)->toBe($this->manager->id)
        ->and($phone->fresh()->history[2]['pending_dates'][0]['count'])->toBe(4);

    Sanctum::actingAs($this->rep);
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit', [], ['X-Field-Device' => $this->tablet])->assertSuccessful();
    $tablet = reportDevice($this, $this->tablet, 'Spare tablet', []);
    $this->postJson('/api/v1/field-devices/'.$tablet->id.'/retire', ['reason' => 'Returned the spare tablet to the office'])->assertSuccessful()->assertJsonPath('data.status', 'retired');
    expect(fn () => $tablet->fresh()->delete())->toThrow(HttpException::class);
});

it('renders the device registry in the ERP scoped to the signed-in representative', function () {
    reportDevice($this, $this->phone, 'Field phone', [['date' => $this->today, 'count' => 2]]);
    Sanctum::actingAs(reportingUser('OtherDeviceRep'));
    reportDevice($this, $this->tablet, 'Other tablet', []);
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageFieldDevices::class)->assertSuccessful()->assertCanSeeTableRecords(FieldDevice::query()->where('user_id', $this->rep->id)->get())
        ->assertCanNotSeeTableRecords(FieldDevice::query()->where('user_id', '!=', $this->rep->id)->get());
    $this->actingAs($this->manager);
    Livewire::test(ManageFieldDevices::class)->assertSuccessful()->assertCanSeeTableRecords(FieldDevice::query()->get());
});
