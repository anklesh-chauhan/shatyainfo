<?php

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Country;
use App\Models\State;
use App\Models\TypeMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{country: Country, state: State, city: City, pin: CityPinCode, addressType: TypeMaster}
 */
function fieldAddressLocation(): array
{
    $country = Country::query()->create(['name' => 'India']);
    $state = State::query()->create([
        'name' => 'Gujarat',
        'country_id' => $country->id,
        'gst_code' => '24',
    ]);
    $city = City::query()->create([
        'name' => 'Ahmedabad',
        'state_id' => $state->id,
        'country_id' => $country->id,
        'is_hill_station' => false,
    ]);
    $pin = CityPinCode::query()->create([
        'pin_code' => '380001',
        'area_town' => 'Navrangpura',
        'city_id' => $city->id,
        'state_id' => $state->id,
        'country_id' => $country->id,
    ]);
    $addressType = TypeMaster::query()->firstOrCreate(
        ['name' => 'Billing Address', 'typeable_type' => Address::class],
        ['parent_id' => null],
    );

    return compact('country', 'state', 'city', 'pin', 'addressType');
}

it('lists address types', function () {
    $user = fieldSalesUser('AddrTypes');
    fieldAddressLocation();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/address-types')
        ->assertSuccessful()
        ->assertJsonFragment(['name' => 'Billing Address']);
});

it('looks up pin codes with city state country', function () {
    $user = fieldSalesUser('PinLookup');
    $location = fieldAddressLocation();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/pin-codes?pin_code=380001')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $location['pin']->id)
        ->assertJsonPath('data.0.area_town', 'Navrangpura')
        ->assertJsonPath('data.0.city.name', 'Ahmedabad')
        ->assertJsonPath('data.0.state.gst_code', '24');
});

it('creates an address on an account', function () {
    $user = fieldSalesUser('AddrCreate');
    $this->actingAs($user);

    $doctor = reportingDoctor($user, 'Dr Addressable');
    $location = fieldAddressLocation();

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/accounts/{$doctor->id}/addresses", [
        'type_master_id' => $location['addressType']->id,
        'street' => '12 CG Road',
        'pin_code' => '380001',
        'area_town_id' => $location['pin']->id,
        'is_primary' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.street', '12 CG Road')
        ->assertJsonPath('data.city.name', 'Ahmedabad')
        ->assertJsonPath('data.type.name', 'Billing Address');

    expect($doctor->addresses()->count())->toBe(1);
});

it('shows account with addresses and related details', function () {
    $user = fieldSalesUser('AddrShow');
    $this->actingAs($user);

    $doctor = reportingDoctor($user, 'Dr Full Detail');
    $location = fieldAddressLocation();

    $address = $doctor->addresses()->create([
        'type_master_id' => $location['addressType']->id,
        'street' => '99 Ring Road',
        'pin_code' => '380001',
        'area_town_id' => $location['pin']->id,
        'city_id' => $location['city']->id,
        'state_id' => $location['state']->id,
        'country_id' => $location['country']->id,
        'is_primary' => true,
        'sort' => 1,
    ]);

    $doctor->gstDetail()->create([
        'address_id' => $address->id,
        'gst_number' => '24AAAAA0000A1Z5',
        'state_name' => 'Gujarat',
        'state_code' => '24',
        'gst_type' => 'Regular',
        'gst_status' => true,
        'pan_number' => 'AAAAA0000A',
    ]);

    $doctor->creditDetail()->create([
        'credit_type' => 'Days',
        'credit_days' => 30,
        'credit_status' => true,
    ]);

    $doctor->statutoryDetail()->create([
        'tan_number' => 'AHMA12345B',
        'cin' => 'U12345GJ2020PTC123456',
    ]);

    $doctor->bankDetail()->create([
        'bank_name' => 'HDFC Bank',
        'bank_account_number' => '1234567890',
        'bank_account_ifsc_code' => 'HDFC0001234',
        'bank_account_currency' => 'INR',
        'bank_account_country' => 'India',
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/accounts/{$doctor->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.addresses.0.street', '99 Ring Road')
        ->assertJsonPath('data.gst_detail.gst_number', '24AAAAA0000A1Z5')
        ->assertJsonPath('data.credit_detail.credit_days', 30)
        ->assertJsonPath('data.statutory_detail.tan_number', 'AHMA12345B')
        ->assertJsonPath('data.bank_details.0.bank_name', 'HDFC Bank');
});

it('creates account with nested address and related details', function () {
    $user = fieldSalesUser('AddrNested');
    $location = fieldAddressLocation();
    $type = TypeMaster::query()->firstOrCreate(
        ['name' => 'Doctor', 'typeable_type' => AccountMaster::class],
        ['parent_id' => null],
    );

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/accounts', [
        'name' => 'Dr Nested Address',
        'type_master_id' => $type->id,
        'website' => 'https://example.com',
        'secondary_email' => 'office@example.com',
        'address' => [
            'type_master_id' => $location['addressType']->id,
            'street' => '1 Nested Lane',
            'pin_code' => '380001',
            'area_town_id' => $location['pin']->id,
            'is_primary' => true,
        ],
        'credit_detail' => [
            'credit_type' => 'Limit',
            'credit_limit' => 50000,
            'credit_status' => true,
        ],
        'bank_detail' => [
            'bank_name' => 'ICICI Bank',
            'bank_account_number' => '9988776655',
            'bank_account_ifsc_code' => 'ICIC0001111',
        ],
    ])->assertSuccessful();

    $accountId = $response->json('data.id');

    $response
        ->assertJsonPath('data.website', 'https://example.com')
        ->assertJsonPath('data.addresses.0.street', '1 Nested Lane')
        ->assertJsonPath('data.credit_detail.credit_type', 'Limit')
        ->assertJsonPath('data.bank_details.0.bank_name', 'ICICI Bank');

    $this->patchJson("/api/v1/accounts/{$accountId}", [
        'gst_detail' => [
            'address_id' => $response->json('data.addresses.0.id'),
            'gst_number' => '24BBBBB0000B1Z5',
            'gst_type' => 'Regular',
            'gst_status' => true,
        ],
        'statutory_detail' => [
            'tan_number' => 'AHMB99999C',
        ],
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.gst_detail.gst_number', '24BBBBB0000B1Z5')
        ->assertJsonPath('data.gst_detail.state_code', '24')
        ->assertJsonPath('data.statutory_detail.tan_number', 'AHMB99999C');
});

it('updates and deletes an account address', function () {
    $user = fieldSalesUser('AddrUpdate');
    $this->actingAs($user);

    $doctor = reportingDoctor($user, 'Dr Addr Mutate');
    $location = fieldAddressLocation();

    Sanctum::actingAs($user);

    $create = $this->postJson("/api/v1/accounts/{$doctor->id}/addresses", [
        'type_master_id' => $location['addressType']->id,
        'street' => 'Old Street',
        'pin_code' => '380001',
        'area_town_id' => $location['pin']->id,
    ])->assertSuccessful();

    $addressId = $create->json('data.id');

    $this->patchJson("/api/v1/accounts/{$doctor->id}/addresses/{$addressId}", [
        'street' => 'New Street',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.street', 'New Street');

    $this->deleteJson("/api/v1/accounts/{$doctor->id}/addresses/{$addressId}")
        ->assertSuccessful();

    expect($doctor->addresses()->count())->toBe(0);
});
