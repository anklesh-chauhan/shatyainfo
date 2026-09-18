<?php

use App\Enums\ItemType;
use App\Models\AccountMaster;
use App\Models\ContactDetail;
use App\Models\DealStage;
use App\Models\Employee;
use App\Models\EmployeeAttendanceStatus;
use App\Models\EmployeeShift;
use App\Models\ItemMaster;
use App\Models\LeadStatus;
use App\Models\LeaveType;
use App\Models\LocationMaster;
use App\Models\Patch;
use App\Models\ShiftMaster;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Models\User;
use Database\Seeders\ConfigDrivenShieldPermissionSeeder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function reportingUser(string $name): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
    ]);

    $employee = Employee::query()->create([
        'employee_id' => fake()->unique()->numerify('EMP###'),
        'first_name' => $name,
        'last_name' => 'Rep',
        'email' => $user->email,
        'mobile_number' => fake()->unique()->numerify('90000#####'),
        'login_id' => $user->id,
        'is_active' => true,
    ]);

    $user->forceFill(['employee_id' => $employee->id])->save();

    return $user->fresh();
}

function reportingTerritory(string $name): Territory
{
    return Territory::query()->create([
        'name' => $name,
        'code' => fake()->unique()->bothify('T###'),
    ]);
}

function reportingDoctor(User $owner, string $name): AccountMaster
{
    $type = TypeMaster::query()->firstOrCreate(
        ['name' => 'Doctor', 'typeable_type' => AccountMaster::class],
        ['parent_id' => null],
    );

    return AccountMaster::query()->create([
        'name' => $name,
        'owner_id' => $owner->id,
        'type_master_id' => $type->id,
    ]);
}

function reportingSampleItem(string $code, string $name): ItemMaster
{
    return ItemMaster::query()->create([
        'item_code' => $code.'-'.fake()->unique()->numerify('###'),
        'item_name' => $name,
        'item_type' => ItemType::Sample,
    ]);
}

function reportingLocation(string $name): LocationMaster
{
    return LocationMaster::query()->create([
        'name' => $name,
        'location_code' => fake()->unique()->bothify('LOC###'),
        'is_active' => true,
    ]);
}

function reportingLeadStatus(string $name): LeadStatus
{
    return LeadStatus::query()->create([
        'name' => $name.' '.fake()->unique()->numerify('###'),
    ]);
}

function reportingDealStage(string $name): DealStage
{
    return DealStage::query()->create([
        'name' => $name.' '.fake()->unique()->numerify('###'),
    ]);
}

function reportingContact(string $firstName, string $lastName): ContactDetail
{
    return ContactDetail::query()->create([
        'first_name' => $firstName,
        'last_name' => $lastName,
    ]);
}

function reportingAttendanceStatus(string $label): EmployeeAttendanceStatus
{
    return EmployeeAttendanceStatus::query()->create([
        'status_code' => strtoupper(str($label)->slug('_')).'_'.fake()->unique()->numerify('##'),
        'status' => $label,
        'is_system' => false,
    ]);
}

function reportingShift(string $name): ShiftMaster
{
    return ShiftMaster::withoutEvents(fn (): ShiftMaster => ShiftMaster::query()->create([
        'code' => strtoupper(str($name)->slug('_')).'_'.fake()->unique()->numerify('##'),
        'name' => $name,
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'shift_type' => 'fixed',
        'week_off_type' => 'none',
    ]));
}

function reportingPatch(Territory $territory, string $name): Patch
{
    return Patch::query()->create([
        'name' => $name.' '.fake()->unique()->numerify('###'),
        'code' => fake()->unique()->bothify('P###'),
        'territory_id' => $territory->id,
    ]);
}

function reportingLeaveType(string $name, ?EmployeeAttendanceStatus $status = null): LeaveType
{
    $status ??= reportingAttendanceStatus($name.' Status');

    return LeaveType::query()->create([
        'code' => strtoupper(str($name)->substr(0, 2)).fake()->unique()->numerify('##'),
        'name' => $name.' '.fake()->unique()->numerify('###'),
        'is_active' => true,
        'employee_attendance_status_id' => $status->id,
    ]);
}

function reportingAssignShift(User $user, ShiftMaster $shift): void
{
    $employee = $user->employee;

    expect($employee)->not->toBeNull();

    EmployeeShift::query()->create([
        'employee_id' => $employee->id,
        'shift_master_id' => $shift->id,
        'effective_from' => today()->subDay()->toDateString(),
        'effective_to' => null,
        'is_current' => true,
    ]);
}

function reportingApiToken(User $user, string $name = 'field-api'): string
{
    return $user->createToken($name)->plainTextToken;
}

function fieldSalesUser(string $name): User
{
    test()->seed(ConfigDrivenShieldPermissionSeeder::class);

    $user = reportingUser($name);
    $user->assignRole(Role::findByName('sales_user', 'web'));

    return $user;
}
