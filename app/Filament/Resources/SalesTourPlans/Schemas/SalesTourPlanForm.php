<?php

namespace App\Filament\Resources\SalesTourPlans\Schemas;

use App\Filament\Resources\SalesTourPlans\Pages\CreateSalesTourPlan;
use App\Models\SalesTourPlan;
use App\Models\Territory;
use App\Models\User;
use App\Models\VisitPreference;
use App\Models\VisitPurpose;
use App\Models\VisitType;
use App\Services\JointUserService;
use App\Services\PositionService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class SalesTourPlanForm
{
    private static function resolveNextDate(Get $get): string
    {
        // Get all current repeater items
        $items = $get('../../details') ?? [];

        $validDates = collect($items)
            ->pluck('date')
            ->filter()
            ->map(fn ($d) => @Carbon::parse($d))
            ->filter()
            ->sort()
            ->values();

        // If there are valid existing dates, add +1 day from the last one
        if ($validDates->isNotEmpty()) {
            $next = $validDates->last()->copy()->addDay();

            $month = $get('../../month');

            // 🚫 Stop auto increment outside month
            if ($month && $next->format('Y-m') !== $month) {
                return Carbon::createFromFormat('Y-m', $month)
                    ->startOfMonth()
                    ->format('Y-m-d');
            }

            return $next->format('Y-m-d');
        }

        // Otherwise, use the selected month (from parent select)
        $month = $get('../../month');

        // 👇 Force start from the FIRST day of the selected month, not "now"
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth()->format('Y-m-d');
        }

        // Fallback (no month selected yet)
        return now()->startOfMonth()->format('Y-m-d');
    }

    private static function normalizeMonth(mixed $month): ?string
    {
        if ($month instanceof Carbon) {
            return $month->format('Y-m');
        }

        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}/', $month)) {
            return null;
        }

        return substr($month, 0, 7);
    }

    private static function generateMonthDays(string $month, ?User $user): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [];
        }

        $territoryId = null;

        if ($user) {
            $territoryIds = PositionService::getTerritoryIdsForUser($user);

            // Auto assign ONLY if exactly one territory
            if (count($territoryIds) === 1) {
                $territoryId = $territoryIds[0];
            }
        }

        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $days = [];

        while ($start->lte($end)) {
            $days[] = [
                'date' => $start->format('Y-m-d'),
                'territory_id' => $territoryId, // ✅ FIX
                'patch_ids' => [],
                'purpose' => null,
                'joint_with' => [],
                'remarks' => null,
            ];

            $start->addDay();
        }

        return $days;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make([
                    Select::make('user_id')
                        ->label('Sales Employee')
                        ->relationship(
                            name: 'user',
                            titleAttribute: 'name',
                            modifyQueryUsing: function ($query) {
                                $authUser = auth()->user();

                                if (! $authUser) {
                                    return $query->whereRaw('1 = 0');
                                }

                                if ($authUser->hasRole('Super Admin')) {
                                    return $query;
                                }

                                $visibleUserIds = PositionService::getVisibleUserIdsFor($authUser);

                                return $query->whereIn('id', $visibleUserIds);
                            }
                        )
                        ->getOptionLabelFromRecordUsing(function (User $user) {
                            $employee = $user->employee;

                            if (! $employee) {
                                return $user->email;
                            }

                            $primaryPosition = $employee->positions()
                                ->wherePivot('is_primary', true)
                                ->first();

                            $authEmployee = auth()->user()?->employee;
                            $authPosition = $authEmployee?->positions()
                                ->wherePivot('is_primary', true)
                                ->first();

                            if (! $primaryPosition || ! $authPosition) {
                                return $employee->full_name;
                            }

                            $depth = PositionService::getPositionDepth(
                                $primaryPosition,
                                $authPosition
                            );

                            // Indentation (2 spaces per level)
                            $indent = str_repeat('— ', max(0, $depth));

                            return $indent.$employee->full_name;
                        })
                        ->default(fn () => auth()->id())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set) {
                            if (! $state) {
                                return;
                            }

                            $user = User::find($state);

                            if (! $user) {
                                return;
                            }

                            $territoryIds = PositionService::getTerritoryIdsForUser($user);

                            if (! empty($territoryIds)) {
                                $set('details.*.territory_id', $territoryIds[0]);
                            }
                        }),

                    Select::make('month')
                        ->label('Select Month')
                        ->options(function (callable $get, ?SalesTourPlan $record): array {
                            $userId = $get('user_id') ?? $record?->user_id ?? Auth::id();
                            $currentMonth = self::normalizeMonth($record?->getRawOriginal('month') ?? $record?->month);

                            $existingMonths = SalesTourPlan::query()
                                ->where('user_id', $userId)
                                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->pluck('month')
                                ->map(fn ($month) => self::normalizeMonth($month))
                                ->filter()
                                ->all();

                            $months = [];
                            $start = now()->startOfMonth();
                            $horizon = VisitPreference::current()->tourPlanFutureMonths();

                            if ($currentMonth && $currentMonth < $start->format('Y-m')) {
                                $months[$currentMonth] = Carbon::createFromFormat('Y-m', $currentMonth)->format('F Y');
                            }

                            for ($i = 0; $i < $horizon; $i++) {
                                $monthKey = $start->copy()->addMonths($i)->format('Y-m');

                                if (in_array($monthKey, $existingMonths, true)) {
                                    continue;
                                }

                                $months[$monthKey] = $start->copy()->addMonths($i)->format('F Y');
                            }

                            return $months;
                        })
                        ->formatStateUsing(fn (mixed $state): ?string => self::normalizeMonth($state))
                        ->default(null)
                        ->required()
                        ->disabledOn('edit')
                        ->helperText(fn (?SalesTourPlan $record): ?string => $record
                            ? null
                            : 'Selecting a month creates the tour plan for every day of that month.')
                        ->reactive()
                        ->afterStateUpdated(function ($state, callable $set, Get $get, $livewire) {
                            if (! $state) {
                                return;
                            }

                            if ($livewire instanceof CreateSalesTourPlan) {
                                $livewire->createPlanForSelectedMonth($state, $get('user_id'));

                                return;
                            }

                            $userId = $get('user_id') ?? auth()->id();
                            $user = $userId ? User::find($userId) : null;

                            $set('details', self::generateMonthDays($state, $user));

                            Notification::make()
                                ->title('Tour plan generated')
                                ->body('All days of the selected month have been added.')
                                ->success()
                                ->send();
                        })
                        ->searchable(),

                    Select::make('status')
                        ->options([
                            'draft' => 'Draft',
                            'submitted' => 'Submitted',
                            'approved' => 'Approved',
                            'rejected' => 'Rejected',
                        ])
                        ->default('draft')
                        ->disabled(),
                ])->columns(3)->columnSpanFull(),

                // show details only if month is selected
                Section::make('Tour Plan Details')
                    ->visible(fn (Get $get) => ! empty($get('month')))
                    ->schema([
                        // Repeater for Sales Tour Plan Details
                        Repeater::make('details')
                            ->relationship('details')
                            ->label('Daily Plan')
                            ->required()
                            ->compact()
                            ->addable(false)
                            ->table([
                                TableColumn::make('Date'),
                                TableColumn::make('Territory'),
                                TableColumn::make('Patches'),
                                TableColumn::make('Visit Type'),
                                TableColumn::make('Purpose'),
                                TableColumn::make('Joint With'),
                                TableColumn::make('Remarks'),
                            ])
                            ->schema([
                                Hidden::make('id'),

                                DatePicker::make('date')
                                    ->label('Date')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('j M, (D)')
                                    ->default(fn (Get $get) => self::resolveNextDate($get))
                                    ->dehydrateStateUsing(function ($state, Get $get) {
                                        // Normalize or rebuild the date before save
                                        try {
                                            if ($state && is_string($state)) {
                                                return Carbon::parse($state)->format('Y-m-d');
                                            }

                                            // If empty or invalid, derive it based on month
                                            $month = $get('../../month');
                                            if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
                                                return Carbon::createFromFormat('Y-m', $month)->startOfMonth()->format('Y-m-d');
                                            }

                                            return now()->format('Y-m-d');
                                        } catch (\Throwable $e) {
                                            return now()->format('Y-m-d');
                                        }
                                    })
                                    ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                        // Get the repeater data from the same level
                                        $rows = $get('../') ?? []; // ✅ Filament 4.1 way to access repeater parent

                                        // Normalize all dates to 'Y-m-d'
                                        $normalized = collect($rows)
                                            ->pluck('date')
                                            ->filter()
                                            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                                            ->toArray();

                                        // Normalize current date
                                        $current = null;
                                        try {
                                            $current = Carbon::parse($state)->format('Y-m-d');
                                        } catch (\Throwable $e) {
                                        }

                                        // Check for duplicates
                                        if ($current && collect($normalized)->filter(fn ($d) => $d === $current)->count() > 1) {
                                            $set('date', null);

                                            Notification::make()
                                                ->title('Duplicate date not allowed')
                                                ->body("The date {$current} is already used in another row.")
                                                ->danger()
                                                ->send();
                                        }
                                    })
                                    ->reactive(),

                                Select::make('territory_id')
                                    ->label('Territory')
                                    ->options(function () {
                                        $user = auth()->user();

                                        // Single source of truth
                                        $territoryIds = PositionService::getTerritoryIdsForUser($user);

                                        // Super admin / full access → no filtering
                                        if (empty($territoryIds)) {
                                            return Territory::pluck('name', 'id');
                                        }

                                        return Territory::whereIn('id', $territoryIds)
                                            ->pluck('name', 'id');
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->afterStateHydrated(function (callable $set, $state) {

                                        // Do not override existing value
                                        if ($state) {
                                            return;
                                        }

                                        $territoryIds = PositionService::getTerritoryIdsForUser(auth()->user());

                                        if (! empty($territoryIds)) {
                                            $set('territory_id', $territoryIds[0]);
                                        }
                                    }),
                                Select::make('patches') // Use relationship name (plural)
                                    ->relationship('patches', 'name')     // This handles all the saving/loading for you
                                    ->multiple()
                                    ->preload()
                                    ->searchable(),

                                Select::make('visit_type_id')
                                    ->label('Visit Type')
                                    ->reactive()
                                    ->searchable()
                                    ->options(
                                        VisitType::withoutGlobalScopes()
                                            ->where('is_active', true)
                                            ->orderBy('sort_order')
                                            ->pluck('name', 'id')
                                    )
                                    ->afterStateUpdated(fn ($state, callable $set) => $set('visit_purpose_ids', [])
                                    ),

                                Select::make('visit_purpose_ids')
                                    ->label('Purposes')
                                    ->multiple()
                                    ->reactive() // ✅ REQUIRED
                                    ->options(fn (Get $get) => VisitPurpose::withoutGlobalScopes() // safety
                                        ->where('visit_type_id', $get('visit_type_id'))
                                        ->where('is_active', true)
                                        ->orderBy('sort_order')
                                        ->pluck('name', 'id')
                                    )
                                    ->searchable()
                                    ->dehydrated(true),

                                Select::make('joint_with')
                                    ->label('Joint With')
                                    ->multiple()
                                    ->options(fn () => JointUserService::getJointUsersForUser(auth()->user())
                                        ->with('employee') // eager load for performance
                                        ->get()
                                        ->mapWithKeys(fn (User $user) => [
                                            $user->id => $user->employee
                                                    ? "{$user->employee->full_name} ({$user->email})"
                                                    : $user->email, // fallback
                                        ])
                                        ->toArray()
                                    )
                                    ->searchable()
                                    ->preload(),

                                Textarea::make('remarks')
                                    ->label('Remarks')
                                    ->rows(2),
                            ])

                            ->afterStateHydrated(function (Repeater $component, $state, $record) {
                                if (! $record || $record->details->isEmpty()) {
                                    return;
                                }

                                $items = $record->details()
                                    ->orderBy('date')
                                    ->with('patches')
                                    ->get()
                                    ->map(fn ($d) => [
                                        'id' => $d->id,
                                        'date' => $d->date?->format('Y-m-d'),
                                        'territory_id' => $d->territory_id,
                                        'patches' => $d->patches->pluck('id')->toArray(),
                                        'visit_type_id' => $d->visit_type_id,
                                        'visit_purpose_ids' => $d->visit_purpose_ids,
                                        'joint_with' => $d->joint_with,
                                        'remarks' => $d->remarks,
                                    ])
                                    ->toArray();

                                $component->state($items);
                            })

                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, callable $get): array {
                                $monthStr = $get('../../month');

                                // If the date is just a number (1, 2, 3...), build a full date from the month
                                if (is_numeric($data['date'])) {
                                    try {
                                        if ($monthStr && \Carbon\Carbon::hasFormat($monthStr, 'Y-m')) {
                                            $data['date'] = \Carbon\Carbon::createFromFormat('Y-m-d', "{$monthStr}-".str_pad($data['date'], 2, '0', STR_PAD_LEFT))
                                                ->format('Y-m-d');
                                        } else {
                                            $data['date'] = now()->format('Y-m-d');
                                        }
                                    } catch (\Throwable $e) {
                                        $data['date'] = now()->format('Y-m-d');
                                    }
                                } else {
                                    // Otherwise, parse normally
                                    try {
                                        $data['date'] = \Carbon\Carbon::parse($data['date'])->format('Y-m-d');
                                    } catch (\Throwable $e) {
                                        $data['date'] = now()->format('Y-m-d');
                                    }
                                }

                                return $data;
                            })

                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data, Get $get): array {
                                // $data['patch_ids'] = is_array($data['patch_ids'] ?? null) ? $data['patch_ids'] : [];
                                $data['joint_with'] = is_array($data['joint_with'] ?? null) ? $data['joint_with'] : [];
                                $data['visit_purpose_ids'] = $data['visit_purpose_ids'] ?? [];

                                return $data;
                            })

                            ->columnSpanFull(),
                    ])->columnSpanFull(),

                Textarea::make('manager_remarks')
                    ->label('Manager Remarks')
                    ->columnSpanFull(),
            ]);
    }
}
