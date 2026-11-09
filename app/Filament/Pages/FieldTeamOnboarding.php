<?php

namespace App\Filament\Pages;

use App\Models\AccountMaster;
use App\Models\FieldUserHq;
use App\Models\LocationMaster;
use App\Models\User;
use App\Services\CompanyLaunchService;
use App\Services\Field\FieldTeamOnboardingService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class FieldTeamOnboarding extends Page implements HasForms
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 60;

    protected static string|\UnitEnum|null $navigationGroup = 'Customers & Territories';

    use InteractsWithForms;

    protected string $view = 'filament.pages.field-team-onboarding';

    protected static ?string $navigationLabel = 'HQ & Customer Assignments';

    protected static ?string $title = 'HQ and customer assignment';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return FieldTeamOnboardingService::canManage(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $representative = request()->query('representative');
        $user = is_scalar($representative) && ctype_digit((string) $representative)
            ? User::query()->find((int) $representative) : null;
        $this->form->fill(['account_ids' => [], 'calls_per_month' => 2, 'user_id' => $user?->id,
            'location_master_id' => $user ? FieldUserHq::query()->where('user_id', $user->id)->value('location_master_id') : null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Field representative setup')->description(fn (): string => app(CompanyLaunchService::class)->requiresHq()
                ? 'Assign an HQ and customers without territory, patch or position setup. Changes take effect now; previous customer ownership remains in history.'
                : 'Assign customers for Direct DCR. HQ, territories and patches are optional. Changes take effect now; previous customer ownership remains in history.')
                ->schema([
                    Select::make('user_id')->label('Representative')->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))->searchable()->required()->live()->afterStateUpdated(function ($state, Set $set): void {
                        $set('location_master_id', FieldUserHq::query()->where('user_id', $state)->value('location_master_id'));
                    }),
                    Select::make('location_master_id')->label('HQ')->options(fn () => LocationMaster::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()->required(fn (): bool => app(CompanyLaunchService::class)->requiresHq())
                        ->helperText(fn (): ?string => app(CompanyLaunchService::class)->requiresHq() ? null : 'Optional for Direct DCR. Leave blank to keep any existing HQ assignment.')
                        ->createOptionForm([TextInput::make('name')->required()->maxLength(255), TextInput::make('location_code')->required()->maxLength(50)->unique('location_masters', 'location_code')])
                        ->createOptionUsing(function (array $data): int {
                            abort_unless(static::canAccess() && auth()->user()->can('create', LocationMaster::class), 403);

                            return LocationMaster::query()->create([...$data, 'is_active' => true])->id;
                        }),
                    Select::make('account_ids')->label('Customers to assign or transfer')->multiple()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => AccountMaster::query()->applyVisibility('AccountMaster')->where('name', 'like', '%'.$search.'%')->limit(100)->pluck('name', 'id')->all())
                        ->getOptionLabelsUsing(fn (array $values): array => AccountMaster::query()->applyVisibility('AccountMaster')->whereIn('id', $values)->pluck('name', 'id')->all())
                        ->helperText('Only selected customers are changed. Unselected customers retain their existing owners.'),
                    TextInput::make('calls_per_month')->label('Initial monthly call target')->integer()->numeric()->minValue(0)->maxValue(1000)->required()
                        ->helperText('Used only where no target covers this month. Existing targets are kept; use the customer Call targets section for later changes. 0 excludes frequency requirements.'),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $result = app(FieldTeamOnboardingService::class)->save(auth()->user(), $this->form->getState());
        Notification::make()->title($result['assigned_customers'].' customers assigned')->success()->send();
        $this->data['account_ids'] = [];
    }
}
