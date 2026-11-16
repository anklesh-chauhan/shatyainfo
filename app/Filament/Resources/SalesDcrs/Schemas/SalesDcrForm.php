<?php

namespace App\Filament\Resources\SalesDcrs\Schemas;

use App\Models\User;
use App\Models\VisitPreference;
use App\Services\PositionService;
use App\Services\Reports\ReportVisibility;
use Closure;
use Filament\Forms;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class SalesDcrForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Forms\Components\DatePicker::make('dcr_date')
                            ->label('DCR date')
                            ->required()
                            ->default(today())
                            ->minDate(function ($record): Carbon {
                                $earliest = VisitPreference::current()->earliestReportableDcrDate();
                                $current = $record?->dcr_date?->copy()->startOfDay();

                                if ($current instanceof Carbon && $current->lt($earliest)) {
                                    return $current;
                                }

                                return $earliest;
                            })
                            ->maxDate(function ($record): Carbon {
                                $latest = today()->startOfDay();
                                $current = $record?->dcr_date?->copy()->startOfDay();

                                if ($current instanceof Carbon && $current->gt($latest)) {
                                    return $current;
                                }

                                return $latest;
                            })
                            ->disabled(fn ($record): bool => $record !== null && $record->dcr_date !== null && ! VisitPreference::current()->allowsDcrDate($record->dcr_date))
                            ->helperText(fn (): string => VisitPreference::current()->dcrDateRejectionMessage())
                            ->rule(function ($record): Closure {
                                return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    if ($value === null || $value === '') {
                                        return;
                                    }

                                    $incoming = Carbon::parse($value)->toDateString();

                                    if ($record?->dcr_date?->toDateString() === $incoming) {
                                        return;
                                    }

                                    $preference = VisitPreference::current();

                                    if (! $preference->allowsDcrDate($incoming)) {
                                        $fail($preference->dcrDateRejectionMessage());
                                    }
                                };
                            }),
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name', modifyQueryUsing: function (Builder $query): Builder {
                                $ids = self::assignableUserIds();

                                return $ids === null ? $query : $query->whereKey($ids);
                            })
                            ->rule(function (): Exists {
                                $rule = Rule::exists(User::class, 'id');
                                $ids = self::assignableUserIds();

                                return $ids === null ? $rule : $rule->whereIn('id', $ids);
                            })
                            ->required(),
                        Forms\Components\Select::make('approval_status')
                            ->disabled()
                            ->dehydrated(false)
                            ->options([
                                'draft' => 'Draft',
                                'submitted' => 'Submitted',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])->default('draft'), //
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * @return list<int>|null
     */
    private static function assignableUserIds(): ?array
    {
        $user = auth()->user();
        if (ReportVisibility::hasFullAccess($user)) {
            return null;
        }

        return $user instanceof User ? PositionService::getVisibleUserIdsFor($user)->values()->all() : [];
    }
}
