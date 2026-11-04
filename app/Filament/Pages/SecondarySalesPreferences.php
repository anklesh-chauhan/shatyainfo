<?php

namespace App\Filament\Pages;

use App\Models\SecondarySalesPreference;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SecondarySalesPreferences extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.secondary-sales-preferences';

    protected static ?string $navigationLabel = 'Secondary sales rules';

    protected static ?string $title = 'Secondary sales stock-balance rules';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'SFA Operations';

    protected static ?int $navigationSort = 25;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('Manage:SecondarySalesPreference') || $user?->hasAnyRole(['super_admin', 'administration_admin']));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(SecondarySalesPreference::current()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        $signInputs = [];
        foreach (SecondarySalesPreference::DEFAULT_FORMULA_SIGNS as $field => $default) {
            $signInputs[] = Forms\Components\TextInput::make('formula_signs.'.$field)
                ->label(str_replace('_', ' ', $field))
                ->numeric()->integer()->required()->default($default);
        }

        return $schema->components([
            Section::make('Closing formula')
                ->description('Default: opening + purchase + primary free − purchase return − secondary sales − secondary free + sales return − expiry/breakage. Variance = reported closing − calculated closing. Change signs when a stockist ERP treats a column differently.')
                ->schema([
                    Forms\Components\TextInput::make('quantity_unit')->label('Statement quantity unit')->required()->maxLength(30)
                        ->helperText('Must match an SKU unit conversion, for example box or strip.'),
                    ...$signInputs,
                ])->columns(2),
            Section::make('Variance and approval')
                ->schema([
                    Forms\Components\TextInput::make('variance_tolerance_qty')->label('Absolute unit tolerance')->numeric()->required(),
                    Forms\Components\TextInput::make('variance_tolerance_percent')->label('Percent of calculated closing')->numeric()->required(),
                    Forms\Components\Toggle::make('freeze_incentive_on_variance')->label('Freeze incentive units when an accepted variance is approved'),
                    Forms\Components\CheckboxList::make('approvable_discrepancy_codes')->label('Discrepancies that a reviewer may approve without a new file')
                        ->options([
                            'stock_balance_variance' => 'Closing variance outside tolerance',
                            'opening_does_not_match_prior_closing' => 'Opening does not match prior closing',
                            'missing_prior_closing' => 'Missing prior-month closing',
                            'primary_purchase_mismatch' => 'Primary purchase does not match company invoices',
                            'expired_batch_still_in_stock' => 'Expired batch still has closing stock',
                        ])->columns(1),
                ])->columns(2),
            Section::make('Carry-forward, primary purchases and free goods')
                ->schema([
                    Forms\Components\Toggle::make('require_prior_closing')->label('Require a prior approved closing for every SKU/batch'),
                    Forms\Components\Select::make('prior_closing_source')->label('Prior closing used for opening match')
                        ->options(['reported' => 'Stockist reported closing', 'calculated' => 'Calculated closing'])->required(),
                    Forms\Components\Toggle::make('verify_primary_purchases')->label('Compare purchase quantity with approved company invoices'),
                    Forms\Components\TextInput::make('primary_purchase_tolerance_qty')->label('Primary purchase unit tolerance')->numeric()->required(),
                    Forms\Components\Toggle::make('include_secondary_free_in_scheme_deduction')->label('Deduct secondary free goods from net sales at PTS (capped at paid sales)'),
                    Forms\Components\Toggle::make('flag_expired_batches')->label('Flag expired batches that still have closing stock'),
                    Forms\Components\Toggle::make('allow_fractional_quantities')->label('Allow fractional quantities'),
                    Forms\Components\Toggle::make('exclusive_source_kind_per_period')->label('Do not mix invoice-line and stock-balance templates for the same stockist month'),
                ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        SecondarySalesPreference::current()->update($this->form->getState());
        $this->form->fill(SecondarySalesPreference::current()->fresh()->toArray());
        Notification::make()->title('Secondary sales rules saved')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')->label('Restore document defaults')->color('gray')->requiresConfirmation()
                ->action(function (): void {
                    SecondarySalesPreference::current()->update(SecondarySalesPreference::defaults());
                    $this->form->fill(SecondarySalesPreference::current()->fresh()->toArray());
                    Notification::make()->title('Document default rules restored')->success()->send();
                }),
        ];
    }
}
