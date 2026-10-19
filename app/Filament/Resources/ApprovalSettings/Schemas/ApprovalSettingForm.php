<?php

namespace App\Filament\Resources\ApprovalSettings\Schemas;

use App\Models\ApprovalSetting;
use Filament\Forms;
use Filament\Schemas\Schema;

class ApprovalSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\CheckboxList::make('enabled_modules')
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
