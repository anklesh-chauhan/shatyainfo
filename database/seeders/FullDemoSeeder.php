<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Enums\MarketingCampaignStatus;
use App\Enums\PromotionalBenefitType;
use App\Enums\PromotionalSchemeAppliesTo;
use App\Enums\PromotionalSchemeStatus;
use App\Enums\PromotionalSchemeType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SampleIssueStatus;
use App\Enums\SampleRequestStatus;
use App\Models\AccountMaster;
use App\Models\AttendancePunch;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\ContactDetail;
use App\Models\DailyAttendance;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Employee;
use App\Models\EmployeeAttendanceStatus;
use App\Models\EmployeeShift;
use App\Models\EmploymentDetail;
use App\Models\ExpenseType;
use App\Models\FollowUp;
use App\Models\FollowUpStatus;
use App\Models\InventoryStock;
use App\Models\ItemMaster;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\LocationMaster;
use App\Models\MarketingCampaign;
use App\Models\OrganizationalUnit;
use App\Models\Patch;
use App\Models\PromotionalScheme;
use App\Models\PromotionalSchemeBenefit;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\SampleIssue;
use App\Models\SampleRequest;
use App\Models\SgipDistribution;
use App\Models\SgipLimit;
use App\Models\ShiftMaster;
use App\Models\Territory;
use App\Models\TransportMode;
use App\Models\TypeMaster;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitOutcome;
use App\Models\VisitType;
use App\Services\OrganizationalUnitService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class FullDemoSeeder extends Seeder
{
    public const SALES_EMAIL = 'demo.salesperson@company.com';

    public const MANAGER_EMAIL = 'demo.manager@company.com';

    public const PASSWORD = 'password';

    public function run(): void
    {
        $manager = $this->demoUser(
            self::MANAGER_EMAIL,
            'Demo Manager',
            'DEMO-MGR-01',
            '9000000002',
            'sales_admin',
        );
        $sales = $this->demoUser(
            self::SALES_EMAIL,
            'Demo Salesperson',
            'DEMO-SE-01',
            '9000000001',
            'sales_user',
        );

        $this->ensureAdminCanSignIn();

        $graph = $this->fieldGraph($sales);

        Auth::login($sales);

        try {
            $items = $this->items();
            $locations = $this->locations();

            $this->tourPlans($sales, $manager, $graph['territory'], $graph['patches']);
            $visits = $this->visits($sales, $manager, $graph);
            $this->followUps($sales, $visits, $graph['contacts']);
            $this->crm($sales, $graph);
            $this->salesDocuments($sales, $graph, $items['commercial']);
            $this->samples($sales, $graph, $items, $locations, $visits['meera_past']);
            $this->purchaseOrder($locations['hq'], $items['commercial']);
            $this->promotion();
            $this->attendance($sales->employee);
            $this->leave($sales->employee);
        } finally {
            Auth::logout();
        }

        $this->command?->info('Full demo data is ready.');
        $this->command?->info('Admin: admin@admin.com / '.self::PASSWORD);
        $this->command?->info('Salesperson: '.self::SALES_EMAIL.' / '.self::PASSWORD);
        $this->command?->info('Manager: '.self::MANAGER_EMAIL.' / '.self::PASSWORD);
    }

    private function demoUser(string $email, string $name, string $code, string $mobile, string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
            ],
        );

        $employee = Employee::query()->updateOrCreate(
            ['email' => $email],
            [
                'employee_id' => $code,
                'first_name' => $name,
                'last_name' => 'Demo',
                'mobile_number' => $mobile,
                'login_id' => $user->id,
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
        );

        $user->forceFill(['employee_id' => $employee->id])->save();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        $ou = OrganizationalUnit::query()->where('code', 'SALES-N')->first()
            ?? OrganizationalUnit::query()->where('code', 'SALES')->first();

        $employment = EmploymentDetail::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'division_ou_id' => OrganizationalUnitService::divisionId($ou?->id),
                'hire_date' => now()->subYears(2)->toDateString(),
                'employment_type' => 'Permanent',
                'employment_status' => 'Active',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
        );

        if ($ou) {
            $employment->organizationalUnits()->syncWithoutDetaching([
                $ou->id => [
                    'is_primary' => true,
                    'effective_from' => now()->subYear()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        return $user->fresh();
    }

    private function ensureAdminCanSignIn(): void
    {
        $admin = User::query()->where('email', 'admin@admin.com')->first();

        if (! $admin || $admin->employee_id) {
            return;
        }

        $employee = Employee::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'employee_id' => 'DEMO-ADMIN',
                'first_name' => 'Admin',
                'last_name' => 'Demo',
                'mobile_number' => '9000000000',
                'login_id' => $admin->id,
                'is_active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        );

        $admin->forceFill(['employee_id' => $employee->id])->save();
    }

    /**
     * @return array{
     *     territory: Territory,
     *     patches: array{a: Patch, b: Patch},
     *     accounts: array<string, AccountMaster>,
     *     contacts: array<string, ContactDetail>
     * }
     */
    private function fieldGraph(User $sales): array
    {
        $cityId = City::query()->value('id');

        $morning = $this->pin('Demo Navrangpura', 380901, 23.0365, 72.5611, $cityId);
        $afternoon = $this->pin('Demo Satellite', 380902, 23.0300, 72.5170, $cityId);

        $territory = Territory::query()->firstOrCreate(
            ['code' => 'DEMO-HQ'],
            [
                'name' => 'Demo HQ',
                'description' => 'Territory used by the demo salesperson.',
                'status' => 'active',
            ],
        );

        $patches = [
            'a' => $this->patch('DEMO-A', 'Demo Navrangpura', $territory, $morning),
            'b' => $this->patch('DEMO-B', 'Demo Satellite', $territory, $afternoon),
        ];

        $doctorType = TypeMaster::query()->firstOrCreate(
            ['name' => 'Doctor', 'typeable_type' => AccountMaster::class],
            ['parent_id' => null],
        );

        $accounts = [];
        $contacts = [];

        foreach ($this->doctors() as $doctor) {
            $account = AccountMaster::query()->firstOrCreate(
                ['name' => $doctor['name']],
                [
                    'owner_id' => $sales->id,
                    'type_master_id' => $doctorType->id,
                    'territory_id' => $territory->id,
                    'phone_number' => $doctor['mobile'],
                    'email' => $doctor['email'],
                    'created_by' => $sales->id,
                    'updated_by' => $sales->id,
                ],
            );

            $patches[$doctor['patch']]->companies()->syncWithoutDetaching([
                $account->id => [
                    'sequence_no' => $doctor['sequence'],
                    'distance_km' => $doctor['sequence'] * 2,
                ],
            ]);

            $contact = ContactDetail::query()->firstOrCreate(
                ['email' => $doctor['email']],
                [
                    'salutation' => 'Dr',
                    'first_name' => $doctor['first'],
                    'last_name' => $doctor['last'],
                    'mobile_number' => $doctor['mobile'],
                    'account_master_id' => $account->id,
                    'created_by' => $sales->id,
                    'updated_by' => $sales->id,
                ],
            );

            $accounts[$doctor['key']] = $account;
            $contacts[$doctor['key']] = $contact;
        }

        return [
            'territory' => $territory,
            'patches' => $patches,
            'accounts' => $accounts,
            'contacts' => $contacts,
        ];
    }

    /**
     * @return list<array{key: string, name: string, first: string, last: string, email: string, mobile: string, patch: string, sequence: int}>
     */
    private function doctors(): array
    {
        return [
            ['key' => 'meera', 'name' => 'Dr Meera Shah', 'first' => 'Meera', 'last' => 'Shah', 'email' => 'meera.shah@demo.test', 'mobile' => '9811100001', 'patch' => 'a', 'sequence' => 1],
            ['key' => 'arun', 'name' => 'Dr Arun Patel', 'first' => 'Arun', 'last' => 'Patel', 'email' => 'arun.patel@demo.test', 'mobile' => '9811100002', 'patch' => 'a', 'sequence' => 2],
            ['key' => 'kavita', 'name' => 'Dr Kavita Nair', 'first' => 'Kavita', 'last' => 'Nair', 'email' => 'kavita.nair@demo.test', 'mobile' => '9811100003', 'patch' => 'b', 'sequence' => 1],
            ['key' => 'rohan', 'name' => 'Dr Rohan Iyer', 'first' => 'Rohan', 'last' => 'Iyer', 'email' => 'rohan.iyer@demo.test', 'mobile' => '9811100004', 'patch' => 'b', 'sequence' => 2],
        ];
    }

    private function pin(string $area, int $pinCode, float $latitude, float $longitude, mixed $cityId): CityPinCode
    {
        $pin = CityPinCode::query()->firstOrCreate(
            ['area_town' => $area],
            [
                'pin_code' => $pinCode,
                'city_id' => $cityId,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
        );

        if ($pin->latitude === null || $pin->longitude === null) {
            $pin->update([
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return $pin;
    }

    private function patch(string $code, string $name, Territory $territory, CityPinCode $pin): Patch
    {
        return Patch::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'territory_id' => $territory->id,
                'city_pin_code_id' => $pin->id,
                'description' => 'Demo calling patch',
                'color' => '#0F766E',
            ],
        );
    }

    /**
     * @param  array{a: Patch, b: Patch}  $patches
     */
    private function tourPlans(User $sales, User $manager, Territory $territory, array $patches): void
    {
        $visitType = VisitType::query()->firstOrCreate(
            ['code' => 'sales_activity'],
            [
                'name' => 'Sales Activity',
                'description' => 'Sales Activity',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        foreach ([now()->subMonthNoOverflow(), now()] as $month) {
            $label = $month->format('Y-m');
            $plan = SalesTourPlan::query()
                ->where('user_id', $sales->id)
                ->where('month', 'like', $label.'%')
                ->first();

            if (! $plan) {
                $plan = SalesTourPlan::query()->create([
                    'user_id' => $sales->id,
                    'month' => $label,
                    'approval_status' => 'approved',
                    'approved_by' => $manager->id,
                    'approved_at' => now(),
                    'manager_remarks' => 'Approved for the demo month.',
                    'created_by' => $sales->id,
                    'updated_by' => $manager->id,
                ]);
            }

            $cursor = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();
            $alternate = 0;

            while ($cursor->lte($end)) {
                if ($cursor->isWeekday() || $cursor->isSameDay(today())) {
                    $patch = $cursor->isSameDay(today())
                        ? $patches['a']
                        : $patches[$alternate % 2 === 0 ? 'a' : 'b'];
                    $alternate++;

                    $detail = SalesTourPlanDetail::query()
                        ->where('sales_tour_plan_id', $plan->id)
                        ->whereDate('date', $cursor->toDateString())
                        ->first();

                    if (! $detail) {
                        $detail = SalesTourPlanDetail::query()->create([
                            'sales_tour_plan_id' => $plan->id,
                            'date' => $cursor->toDateString(),
                            'territory_id' => $territory->id,
                            'visit_type_id' => $visitType->id,
                            'remarks' => $cursor->isSameDay(today()) ? 'Today’s doctor calls' : 'Field working day',
                            'created_by' => $sales->id,
                            'updated_by' => $sales->id,
                        ]);
                    }

                    $detail->patches()->sync([$patch->id]);
                }

                $cursor->addDay();
            }
        }
    }

    /**
     * @param  array{territory: Territory, patches: array{a: Patch, b: Patch}, accounts: array<string, AccountMaster>, contacts: array<string, ContactDetail>}  $graph
     * @return array{meera_past: Visit, meera_today: Visit}
     */
    private function visits(User $sales, User $manager, array $graph): array
    {
        $followUp = VisitOutcome::query()->firstOrCreate(
            ['code' => 'FOLLOW_UP_REQUIRED'],
            ['label' => 'Follow-up Required', 'notes' => 'Customer needs follow-up or additional discussion'],
        );

        $pastDate = today()->subWeek();
        $plan = SalesTourPlan::query()
            ->where('user_id', $sales->id)
            ->where('month', 'like', now()->format('Y-m').'%')
            ->first();

        $past = $this->visit($sales, $manager, $graph, [
            'document_number' => 'DEMO-VIS-MEERA-PAST',
            'account' => 'meera',
            'patch' => 'a',
            'date' => $pastDate,
            'status' => 'completed',
            'plan' => $plan,
            'outcome_id' => $followUp->id,
            'remarks' => 'Asked to see the new strip and a follow-up sample.',
            'start' => $pastDate->copy()->setTime(10, 0),
            'end' => $pastDate->copy()->setTime(10, 40),
        ]);

        $todayMeera = $this->visit($sales, $manager, $graph, [
            'document_number' => 'DEMO-VIS-MEERA-TODAY',
            'account' => 'meera',
            'patch' => 'a',
            'date' => today(),
            'status' => 'draft',
            'plan' => $plan,
        ]);

        $this->visit($sales, $manager, $graph, [
            'document_number' => 'DEMO-VIS-ARUN-TODAY',
            'account' => 'arun',
            'patch' => 'a',
            'date' => today(),
            'status' => 'draft',
            'plan' => $plan,
        ]);

        $this->visit($sales, $manager, $graph, [
            'document_number' => 'DEMO-VIS-KAVITA-TODAY',
            'account' => 'kavita',
            'patch' => 'b',
            'date' => today(),
            'status' => 'started',
            'plan' => $plan,
            'start' => today()->copy()->setTime(10, 30),
            'checkin' => true,
        ]);

        return [
            'meera_past' => $past,
            'meera_today' => $todayMeera,
        ];
    }

    /**
     * @param  array{territory: Territory, patches: array{a: Patch, b: Patch}, accounts: array<string, AccountMaster>}  $graph
     * @param  array<string, mixed>  $definition
     */
    private function visit(User $sales, User $manager, array $graph, array $definition): Visit
    {
        $date = $definition['date'] instanceof Carbon
            ? $definition['date']->copy()
            : Carbon::parse($definition['date']);
        $patch = $graph['patches'][$definition['patch']];
        $account = $graph['accounts'][$definition['account']];

        if (str_ends_with($definition['document_number'], '-TODAY')) {
            $previous = Visit::query()->where('document_number', $definition['document_number'])->first();

            if ($previous && ! Carbon::parse($previous->visit_date)->isSameDay($date)) {
                $previous->update([
                    'document_number' => $definition['document_number'].'-'.$previous->id,
                ]);
            }
        }

        $dcr = SalesDcr::query()->firstOrCreate(
            [
                'user_id' => $sales->id,
                'dcr_date' => $date->toDateString(),
            ],
            [
                'approval_status' => 'draft',
                'territory_id' => $graph['territory']->id,
                'sales_tour_plan_id' => $definition['plan']?->id,
                'sales_person_id' => $sales->id,
                'sales_manager_id' => $manager->id,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );

        $visit = Visit::withoutEvents(function () use ($sales, $manager, $graph, $definition, $date, $patch, $dcr): Visit {
            return Visit::query()->firstOrCreate(
                ['document_number' => $definition['document_number']],
                [
                    'employee_id' => $sales->id,
                    'reporting_manager_id' => $manager->id,
                    'territory_id' => $graph['territory']->id,
                    'patch_id' => $patch->id,
                    'sales_tour_plan_id' => $definition['plan']?->id,
                    'sales_dcr_id' => $dcr->id,
                    'visit_date' => $date->toDateString(),
                    'start_time' => $definition['start'] ?? null,
                    'end_time' => $definition['end'] ?? null,
                    'visit_type' => 'planned',
                    'visit_status' => $definition['status'],
                    'approval_status' => 'draft',
                    'remarks' => $definition['remarks'] ?? null,
                    'visit_outcome_id' => $definition['outcome_id'] ?? null,
                    'checkin_latitude' => ($definition['checkin'] ?? false) ? $patch->cityPinCode?->latitude : null,
                    'checkin_longitude' => ($definition['checkin'] ?? false) ? $patch->cityPinCode?->longitude : null,
                    'created_by' => $sales->id,
                    'updated_by' => $sales->id,
                ],
            );
        });

        $visit->visitables()->firstOrCreate([
            'visitable_type' => AccountMaster::class,
            'visitable_id' => $account->id,
        ]);

        if ($dcr->approval_status !== 'approved') {
            $dcr->updateVisitCount();
        }

        if ($date->lt(today()) && $dcr->approval_status === 'draft') {
            $this->expense($dcr);
            $dcr->update([
                'approval_status' => 'approved',
                'approved_by' => $manager->id,
                'approved_at' => now(),
                'remarks' => 'Demo day closed.',
            ]);
        }

        return $visit;
    }

    private function expense(SalesDcr $dcr): void
    {
        $type = ExpenseType::query()->firstOrCreate(
            ['code' => 'DAILY_ALLOWANCE'],
            ['name' => 'Daily Allowance', 'is_active' => true],
        );
        $mode = TransportMode::query()->firstOrCreate(
            ['code' => 'BIKE'],
            ['name' => 'Bike', 'is_distance_based' => true],
        );

        SalesDcrExpense::query()->firstOrCreate(
            [
                'sales_dcr_id' => $dcr->id,
                'remarks' => 'Demo daily allowance',
            ],
            [
                'expense_type_id' => $type->id,
                'transport_mode_id' => $mode->id,
                'amount' => 250,
                'quantity' => 1,
                'rate' => 250,
                'is_auto_calculated' => false,
            ],
        );

        $dcr->recalculateTotalExpense();
    }

    /**
     * @param  array{meera_past: Visit}  $visits
     * @param  array<string, ContactDetail>  $contacts
     */
    private function followUps(User $sales, array $visits, array $contacts): void
    {
        $open = FollowUpStatus::query()->firstOrCreate(['name' => 'Open']);

        FollowUp::query()->firstOrCreate(
            [
                'user_id' => $sales->id,
                'interaction' => 'Asked for the new strip price list and a follow-up sample.',
            ],
            [
                'followupable_type' => Visit::class,
                'followupable_id' => $visits['meera_past']->id,
                'to_whom' => $contacts['meera']->id,
                'follow_up_date' => today()->subDays(3),
                'next_follow_up_date' => today()->subDay(),
                'follow_up_status_id' => $open->id,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );
    }

    /**
     * @param  array{territory: Territory, accounts: array<string, AccountMaster>, contacts: array<string, ContactDetail>}  $graph
     */
    private function crm(User $sales, array $graph): void
    {
        $preQualified = LeadStatus::query()->firstOrCreate(['name' => 'Pre-Qualified']);
        $contacted = LeadStatus::query()->firstOrCreate(['name' => 'Contacted']);
        $converted = LeadStatus::query()->firstOrCreate(['name' => 'Converted']);
        $negotiation = DealStage::query()->firstOrCreate(['name' => 'Negotiation/Review']);
        $won = DealStage::query()->firstOrCreate(['name' => 'Closed Won']);
        $open = FollowUpStatus::query()->firstOrCreate(['name' => 'Open']);

        $this->lead($sales, $graph, 'DEMO-LEAD-OPEN', 'Clinic asked for a comparative strip.', $preQualified, $graph['accounts']['arun'], $graph['contacts']['arun'], 180000);
        $waitingLead = $this->lead($sales, $graph, 'DEMO-LEAD-WAITING', 'Wants the rate card before the next call.', $contacted, $graph['accounts']['kavita'], $graph['contacts']['kavita'], 95000);
        $this->lead($sales, $graph, 'DEMO-LEAD-CONVERTED', 'Converted after the last detailing visit.', $converted, $graph['accounts']['meera'], $graph['contacts']['meera'], 240000);

        FollowUp::query()->firstOrCreate(
            [
                'user_id' => $sales->id,
                'interaction' => 'Rate card shared. Next call is already booked.',
            ],
            [
                'followupable_type' => Lead::class,
                'followupable_id' => $waitingLead->id,
                'to_whom' => $graph['contacts']['kavita']->id,
                'follow_up_date' => today(),
                'next_follow_up_date' => today()->addDays(5),
                'follow_up_status_id' => $open->id,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );

        $closing = $this->deal($sales, 'DEMO-DEAL-CLOSING', 'Arun Patel quarterly order', $graph['accounts']['arun'], $graph['contacts']['arun'], $negotiation, 85000, today()->addDays(3));
        $this->deal($sales, 'DEMO-DEAL-WON', 'Meera Shah repeat order', $graph['accounts']['meera'], $graph['contacts']['meera'], $won, 120000, today()->subDays(2));

        FollowUp::query()->firstOrCreate(
            [
                'user_id' => $sales->id,
                'interaction' => 'Quote discussed. Close date is this week.',
            ],
            [
                'followupable_type' => Deal::class,
                'followupable_id' => $closing->id,
                'to_whom' => $graph['contacts']['arun']->id,
                'follow_up_date' => today()->subDays(4),
                'next_follow_up_date' => today()->subDay(),
                'follow_up_status_id' => $open->id,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );
    }

    private function lead(User $sales, array $graph, string $code, string $description, LeadStatus $status, AccountMaster $account, ContactDetail $contact, float $revenue): Lead
    {
        return Lead::query()->firstOrCreate(
            ['reference_code' => $code],
            [
                'owner_id' => $sales->id,
                'transaction_date' => today()->subDays(10)->toDateString(),
                'account_master_id' => $account->id,
                'contact_detail_id' => $contact->id,
                'territory_id' => $graph['territory']->id,
                'status_id' => $status->id,
                'status_type' => LeadStatus::class,
                'annual_revenue' => $revenue,
                'description' => $description,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );
    }

    private function deal(User $sales, string $code, string $name, AccountMaster $account, ContactDetail $contact, DealStage $stage, float $amount, Carbon $closeDate): Deal
    {
        return Deal::query()->firstOrCreate(
            ['reference_code' => $code],
            [
                'owner_id' => $sales->id,
                'deal_name' => $name,
                'transaction_date' => today()->subDays(6)->toDateString(),
                'account_master_id' => $account->id,
                'contact_detail_id' => $contact->id,
                'amount' => $amount,
                'expected_revenue' => $amount,
                'expected_close_date' => $closeDate->toDateString(),
                'description' => $name,
                'status_id' => $stage->id,
                'status_type' => DealStage::class,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );
    }

    /**
     * @param  array{accounts: array<string, AccountMaster>, contacts: array<string, ContactDetail>}  $graph
     */
    private function salesDocuments(User $sales, array $graph, ItemMaster $item): void
    {
        $account = $graph['accounts']['meera'];
        $contact = $graph['contacts']['meera'];
        $amounts = $this->lineAmounts(10, 120, 12);

        $quote = Quote::query()->firstOrCreate(
            ['document_number' => 'DEMO-QT-1001'],
            [
                'account_master_id' => $account->id,
                'contact_detail_id' => $contact->id,
                'sales_person_id' => $sales->id,
                'date' => today()->subDays(5)->toDateString(),
                'expiration_date' => today()->addDays(20)->toDateString(),
                'status' => 'sent',
                'sent_at' => today()->subDays(5)->toDateString(),
                'currency' => 'INR',
                'description' => 'Demo quote for DemoTab 500.',
                ...$amounts,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );

        $order = SalesOrder::query()->firstOrCreate(
            ['document_number' => 'DEMO-SO-1001'],
            [
                'account_master_id' => $account->id,
                'contact_detail_id' => $contact->id,
                'sales_person_id' => $sales->id,
                'date' => today()->subDays(3)->toDateString(),
                'delivery_date' => today()->addDays(4)->toDateString(),
                'status' => 'accepted',
                'currency' => 'INR',
                'description' => 'Demo order confirmed from the quote.',
                ...$amounts,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );

        $invoice = SalesInvoice::query()->firstOrCreate(
            ['document_number' => 'DEMO-INV-1001'],
            [
                'account_master_id' => $account->id,
                'contact_detail_id' => $contact->id,
                'sales_person_id' => $sales->id,
                'date' => today()->subDay()->toDateString(),
                'due_date' => today()->addDays(14)->toDateString(),
                'status' => 'sent',
                'payment_status' => 'Unpaid',
                'currency' => 'INR',
                'description' => 'Demo invoice for the confirmed order.',
                ...$amounts,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );

        foreach ([$quote, $order, $invoice] as $document) {
            if ($document->items()->doesntExist()) {
                $document->items()->create([
                    'item_master_id' => $item->id,
                    'quantity' => 10,
                    'price' => 120,
                    'unit_price' => 120,
                    'tax_rate' => 12,
                    'amount' => $amounts['subtotal'],
                    'description' => $item->item_name,
                    'created_by' => $sales->id,
                    'updated_by' => $sales->id,
                ]);
            }
        }

        $this->link($quote->salesOrders(), $order->id);
        $this->link($order->salesInvoices(), $invoice->id);
        $this->link($quote->salesInvoices(), $invoice->id);
    }

    /**
     * @return array{subtotal: float, tax: float, gross_total: float, total: float, discount_mode: string}
     */
    private function lineAmounts(int $quantity, float $price, float $taxRate): array
    {
        $subtotal = $quantity * $price;
        $tax = round($subtotal * $taxRate / 100, 2);

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'gross_total' => $subtotal + $tax,
            'total' => $subtotal + $tax,
            'discount_mode' => 'none',
        ];
    }

    private function link($relation, int $id): void
    {
        if ($relation->whereKey($id)->exists()) {
            return;
        }

        $relation->attach($id, [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{commercial: ItemMaster, sample: ItemMaster}
     */
    private function items(): array
    {
        return [
            'commercial' => $this->item('DEMO-TAB-500', 'DemoTab 500', null, 80, 120),
            'sample' => $this->item('DEMO-STRIP', 'Demo Strip', ItemType::Sample, 15, 15),
        ];
    }

    private function item(string $sku, string $name, ?ItemType $type, float $purchase, float $selling): ItemMaster
    {
        return ItemMaster::query()->firstOrCreate(
            ['sku' => $sku],
            [
                'item_name' => $name,
                'item_type' => $type,
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'tax_rate' => 12,
                'has_variants' => false,
                'opening_stock' => 100,
            ],
        );
    }

    /**
     * @return array{hq: LocationMaster, field: LocationMaster}
     */
    private function locations(): array
    {
        $hq = LocationMaster::query()->firstOrCreate(
            ['name' => 'Demo HQ Store'],
            ['is_active' => true, 'description' => 'Demo warehouse'],
        );
        $field = LocationMaster::query()->firstOrCreate(
            ['name' => 'Demo Field Bag'],
            ['is_active' => true, 'description' => 'Samples carried by the demo salesperson'],
        );

        return ['hq' => $hq, 'field' => $field];
    }

    /**
     * @param  array{territory: Territory, accounts: array<string, AccountMaster>}  $graph
     * @param  array{commercial: ItemMaster, sample: ItemMaster}  $items
     * @param  array{hq: LocationMaster, field: LocationMaster}  $locations
     */
    private function samples(User $sales, array $graph, array $items, array $locations, Visit $pastVisit): void
    {
        foreach ([$items['sample'], $items['commercial']] as $item) {
            InventoryStock::query()->firstOrCreate(
                [
                    'item_master_id' => $item->id,
                    'location_master_id' => $locations['hq']->id,
                ],
                [
                    'quantity_on_hand' => $item->item_type === ItemType::Sample ? 40 : 200,
                    'quantity_reserved' => 0,
                    'quantity_available' => $item->item_type === ItemType::Sample ? 40 : 200,
                    'average_cost' => $item->purchase_price ?? 0,
                    'last_movement_at' => now(),
                ],
            );
        }

        SgipLimit::query()->firstOrCreate(
            [
                'applies_to' => 'global',
                'applies_to_id' => null,
                'item_type' => ItemType::Sample->value,
                'period' => 'monthly',
            ],
            ['max_quantity' => 20],
        );

        $distribution = SgipDistribution::query()->firstOrCreate(
            ['visit_id' => $pastVisit->id],
            [
                'user_id' => $sales->id,
                'employee_id' => $sales->employee?->id,
                'account_master_id' => $graph['accounts']['meera']->id,
                'territory_id' => $graph['territory']->id,
                'visit_date' => $pastVisit->visit_date,
                'approval_status' => 'approved',
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );

        if ($distribution->items()->doesntExist()) {
            $distribution->items()->create([
                'item_master_id' => $items['sample']->id,
                'quantity' => 2,
                'unit_value' => 15,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ]);
        }

        $request = SampleRequest::query()->firstOrCreate(
            ['purpose' => 'Demo field sample stock'],
            [
                'employee_id' => $sales->employee?->id,
                'territory_id' => $graph['territory']->id,
                'location_master_id' => $locations['field']->id,
                'request_date' => today()->subDays(2)->toDateString(),
                'status' => SampleRequestStatus::Approved,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );

        $line = $request->lines()->firstOrCreate(
            ['item_master_id' => $items['sample']->id],
            [
                'quantity_requested' => 10,
                'quantity_approved' => 10,
                'quantity_issued' => 0,
            ],
        );

        $issue = SampleIssue::query()->firstOrCreate(
            ['notes' => 'Demo issue to the field bag'],
            [
                'sample_request_id' => $request->id,
                'from_location_id' => $locations['hq']->id,
                'to_location_id' => $locations['field']->id,
                'issue_date' => today()->subDay()->toDateString(),
                'status' => SampleIssueStatus::Draft,
                'issued_by' => $sales->id,
                'created_by' => $sales->id,
                'updated_by' => $sales->id,
            ],
        );

        $issue->lines()->firstOrCreate(
            ['item_master_id' => $items['sample']->id],
            [
                'sample_request_line_id' => $line->id,
                'quantity' => 10,
                'unit_cost' => 15,
            ],
        );
    }

    private function purchaseOrder(LocationMaster $location, ItemMaster $item): void
    {
        $vendorType = TypeMaster::query()->firstOrCreate(
            ['name' => 'Vendor', 'typeable_type' => AccountMaster::class],
            ['parent_id' => null],
        );

        $supplier = AccountMaster::query()->firstOrCreate(
            ['name' => 'Demo Pharma Supplies'],
            [
                'owner_id' => Auth::id(),
                'type_master_id' => $vendorType->id,
                'email' => 'supplies@demo.test',
                'phone_number' => '9811100099',
            ],
        );

        $order = PurchaseOrder::query()->firstOrCreate(
            ['document_number' => 'DEMO-PO-1001'],
            [
                'supplier_id' => $supplier->id,
                'location_master_id' => $location->id,
                'order_date' => today()->subDays(4)->toDateString(),
                'expected_delivery_date' => today()->addDays(7)->toDateString(),
                'status' => PurchaseOrderStatus::Draft,
                'subtotal' => 8000,
                'tax_amount' => 960,
                'total_amount' => 8960,
                'currency' => 'INR',
                'notes' => 'Demo purchase of DemoTab 500.',
                'approval_status' => 'draft',
            ],
        );

        $order->lines()->firstOrCreate(
            ['item_master_id' => $item->id],
            [
                'quantity_ordered' => 100,
                'quantity_received' => 0,
                'unit_price' => 80,
                'tax_rate' => 12,
                'line_total' => 8960,
                'description' => $item->item_name,
            ],
        );
    }

    private function promotion(): void
    {
        $scheme = PromotionalScheme::query()->firstOrCreate(
            ['code' => 'DEMO-Q3'],
            [
                'name' => 'Demo quarter discount',
                'scheme_type' => PromotionalSchemeType::PercentDiscount,
                'status' => PromotionalSchemeStatus::Active,
                'applies_to' => PromotionalSchemeAppliesTo::Global,
                'valid_from' => today()->startOfMonth()->toDateString(),
                'valid_to' => today()->addMonths(2)->toDateString(),
                'min_order_value' => 5000,
                'description' => 'Ten percent off qualifying orders during the demo quarter.',
            ],
        );

        PromotionalSchemeBenefit::query()->firstOrCreate(
            [
                'promotional_scheme_id' => $scheme->id,
                'benefit_type' => PromotionalBenefitType::DiscountPercent,
            ],
            ['discount_value' => 10],
        );

        MarketingCampaign::query()->firstOrCreate(
            ['campaign_number' => 'DEMO-CAMP-001'],
            [
                'name' => 'Demo detailing campaign',
                'promotional_scheme_id' => $scheme->id,
                'status' => MarketingCampaignStatus::Active,
                'start_date' => today()->startOfMonth()->toDateString(),
                'end_date' => today()->addMonths(2)->toDateString(),
                'total_budget' => 50000,
                'description' => 'Field detailing campaign for the demo quarter.',
            ],
        );
    }

    private function attendance(Employee $employee): void
    {
        $shift = ShiftMaster::query()->where('code', 'GEN')->first()
            ?? ShiftMaster::withoutEvents(fn (): ShiftMaster => ShiftMaster::query()->create([
                'code' => 'GEN',
                'name' => 'General Staff Shift',
                'shift_type' => ShiftMaster::SHIFT_TYPE_FIXED,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'week_off_type' => ShiftMaster::WEEK_OFF_NONE,
            ]));

        EmployeeShift::query()->firstOrCreate(
            [
                'employee_id' => $employee->id,
                'shift_master_id' => $shift->id,
            ],
            [
                'effective_from' => today()->subMonth()->toDateString(),
                'is_current' => true,
            ],
        );

        $present = EmployeeAttendanceStatus::query()->firstOrCreate(
            ['status_code' => 'DP'],
            ['status' => 'Day Present', 'remarks' => 'Employee present for full day.'],
        );

        $cursor = today()->subDays(6);

        while ($cursor->lt(today())) {
            if ($cursor->isWeekday()) {
                $this->punch($employee, $cursor, 'in', '09:05:00');
                $this->punch($employee, $cursor, 'out', '18:10:00');

                DailyAttendance::query()->firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'attendance_date' => $cursor->toDateString(),
                    ],
                    [
                        'shift_master_id' => $shift->id,
                        'first_punch_in' => '09:05:00',
                        'last_punch_out' => '18:10:00',
                        'actual_working_minutes' => 545,
                        'final_working_minutes' => 480,
                        'status_id' => $present->id,
                    ],
                );
            }

            $cursor->addDay();
        }

    }

    private function punch(Employee $employee, Carbon $date, string $type, string $time): void
    {
        AttendancePunch::query()->firstOrCreate(
            [
                'employee_id' => $employee->id,
                'punch_date' => $date->toDateString(),
                'punch_type' => $type,
            ],
            [
                'punch_time' => $time,
                'source' => 'mobile',
                'location' => 'Demo HQ',
            ],
        );
    }

    private function leave(Employee $employee): void
    {
        $status = EmployeeAttendanceStatus::query()->firstOrCreate(
            ['status_code' => 'CL'],
            ['status' => 'Casual Leave', 'remarks' => 'Demo casual leave status.'],
        );

        $type = LeaveType::query()->firstOrCreate(
            ['code' => 'CL'],
            [
                'name' => 'Casual Leave',
                'is_paid' => true,
                'affects_payroll' => true,
                'is_active' => true,
                'employee_attendance_status_id' => $status->id,
            ],
        );

        $from = today()->subMonth()->startOfMonth()->addDays(2);

        LeaveApplication::query()->firstOrCreate(
            [
                'employee_id' => $employee->id,
                'reason' => 'Demo casual leave',
            ],
            [
                'leave_type_id' => $type->id,
                'from_date' => $from->toDateString(),
                'to_date' => $from->toDateString(),
                'total_days' => 1,
                'is_half_day' => false,
                'approval_status' => 'approved',
                'applied_at' => $from,
            ],
        );
    }
}
