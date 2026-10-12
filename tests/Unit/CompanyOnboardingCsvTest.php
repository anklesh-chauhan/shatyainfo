<?php

use App\Models\Organization;
use App\Services\CompanyOnboardingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function onboardingCsvService(): CompanyOnboardingService
{
    return new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    };
}

test('employee csv supports reordered headers and preserves leading zeroes', function () {
    $path = tempnam(sys_get_temp_dir(), 'employees');
    file_put_contents($path, "\xEF\xBB\xBFemail,mobile_number,last_name,employee_id,first_name\nasha@example.com,09876543210,Rao,001,Asha\n");

    try {
        expect(onboardingCsvService()->parseCsv($path))->toBe([
            ['email' => 'asha@example.com', 'mobile_number' => '09876543210', 'last_name' => 'Rao', 'employee_id' => '001', 'first_name' => 'Asha'],
        ]);
    } finally {
        unlink($path);
    }
});

test('employee csv rejects malformed rows or missing headers', function (string $csv) {
    $path = tempnam(sys_get_temp_dir(), 'employees');
    file_put_contents($path, $csv);

    try {
        expect(fn () => onboardingCsvService()->parseCsv($path))->toThrow(ValidationException::class);
    } finally {
        unlink($path);
    }
})->with([
    'missing headers' => "first_name,last_name\nAsha,Rao\n",
    'duplicate headers' => "employee_id,first_name,last_name,email,mobile_number,email\n001,Asha,Rao,a@example.com,123,a@example.com\n",
    'wrong column count' => "employee_id,first_name,last_name,email,mobile_number\n001,Asha,Rao\n",
]);

test('employee csv limits uploads to 500 rows', function () {
    $path = tempnam(sys_get_temp_dir(), 'employees');
    file_put_contents($path, "employee_id,first_name,last_name,email,mobile_number\n".str_repeat("001,Asha,Rao,,123\n", 501));

    try {
        expect(fn () => onboardingCsvService()->parseCsv($path))->toThrow(ValidationException::class);
    } finally {
        unlink($path);
    }
});

test('employee rows are validated together and saved without creating login accounts', function () {
    config(['database.connections.onboarding_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('onboarding_test');

    try {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable()->unique();
            $table->string('mobile_number')->unique();
            $table->boolean('is_active');
            $table->unsignedBigInteger('login_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $service = new class extends CompanyOnboardingService
        {
            public function authorize(): void {}

            public function organization(): ?Organization
            {
                return new Organization(['metadata' => ['company_setup' => ['organization_saved' => true]]]);
            }
        };
        $row = ['employee_id' => '001', 'first_name' => 'Asha', 'last_name' => 'Rao', 'email' => '', 'mobile_number' => '09876543210'];

        expect(fn () => $service->saveEmployees([$row, $row]))->toThrow(ValidationException::class);
        expect(DB::table('employees')->count())->toBe(0);
        expect($service->saveEmployees([$row]))->toBe(1);
        expect(DB::table('employees')->value('login_id'))->toBeNull();
        expect(fn () => $service->saveEmployees([$row]))->toThrow(ValidationException::class);
        expect(DB::table('employees')->count())->toBe(1);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('onboarding_test');
    }
});
