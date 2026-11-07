<?php

namespace App\Filament\Resources\ApprovalSettings\Schemas;

use App\Models\ApprovalSetting;
use Filament\Forms;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ApprovalSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Toggle::make('approvals_required')
                    ->label('Does your team need approvals?')
                    ->default(fn (): bool => ApprovalSetting::enabledModules() !== [])
                    ->afterStateHydrated(fn (Forms\Components\Toggle $component, ?ApprovalSetting $record) => $component->state(($record?->enabled_modules ?? []) !== []))
                    ->dehydrated(false)
                    ->afterStateUpdated(function (bool $state, Set $set): void {
                        if (! $state) {
                            $set('enabled_modules', []);
                        }
                    })
                    ->live()
                    ->helperText('Turn off for no approvals in the admin workspace and field app. Existing approval flows are kept so you can enable them later.'),
                Forms\Components\CheckboxList::make('enabled_modules')
                    ->disabled(fn (Get $get): bool => ! $get('approvals_required'))
                    ->dehydrated()
                    ->dehydrateStateUsing(fn (?array $state, Get $get): array => $get('approvals_required') ? ($state ?? []) : [])
                    ->required(fn (Get $get): bool => (bool) $get('approvals_required'))
                    ->label('Modules Requiring Approval')
                    ->options(function (): array {
                        $legacy = collect(ApprovalSetting::enabledModules())
                            ->reject(fn (string $module): bool => ApprovalSetting::supportsModule($module))
                            ->mapWithKeys(fn (string $module): array => [
                                (str_contains($module, '\\') ? $module : 'App\\Models\\'.$module) => class_basename($module).' (unsupported — remove)',
                            ])->all();

                        return ApprovalSetting::eligibleModuleOptions() + $legacy;
                    })
                    ->in(fn (): array => array_keys(ApprovalSetting::eligibleModuleOptions()))
                    ->columns(2)
                    ->helperText(function (): string {
                        $unsupported = collect(ApprovalSetting::enabledModules())
                            ->reject(fn (string $module): bool => ApprovalSetting::supportsModule($module))
                            ->map(fn (string $module): string => class_basename($module));

                        return 'Only modules with an approval workflow and outcome handler are available. Configure their steps in Approval Flows.'
                            .($unsupported->isNotEmpty() ? ' Previously enabled modules without support: '.$unsupported->implode(', ').'. Remove these selections before saving.' : '');
                    })
                    ->afterStateHydrated(function (Forms\Components\CheckboxList $component, ?array $state): void {
                        $component->state(collect($state ?? [])
                            ->map(fn (string $module): string => str_contains($module, '\\') ? $module : 'App\\Models\\'.$module)
                            ->all());
                    }),
            ])->columns(1);
    }
}
