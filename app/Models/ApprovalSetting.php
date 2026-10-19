<?php

namespace App\Models;

use App\Helpers\ModelHelper;
use App\Services\Approval\ApprovalDomainDispatcher;
use Illuminate\Validation\ValidationException;

class ApprovalSetting extends BaseModel
{
    protected $table = 'approval_settings';

    protected $fillable = ['enabled_modules'];

    protected $casts = [
        'enabled_modules' => 'array',
    ];

    /** @return array<class-string, string> */
    public static function eligibleModuleOptions(): array
    {
        return collect(app(ApprovalDomainDispatcher::class)->supportedModels())
            ->filter(fn (string $class): bool => method_exists($class, 'approval'))
            ->mapWithKeys(fn (string $class): array => [$class => ModelHelper::labelFromClass($class)])
            ->all();
    }

    public static function supportsModule(string $module): bool
    {
        return collect(array_keys(static::eligibleModuleOptions()))
            ->contains(fn (string $class): bool => $module === $class || $module === class_basename($class));
    }

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            foreach ($setting->enabled_modules as $module) {
                if (! is_string($module) || ! static::supportsModule($module)) {
                    throw ValidationException::withMessages([
                        'enabled_modules' => 'Select only modules with a supported approval workflow and outcome handler.',
                    ]);
                }
            }

            $setting->enabled_modules = collect($setting->enabled_modules)
                ->map(fn (string $module): string => 'App\\Models\\'.class_basename($module))
                ->unique()->values()->all();
        });
    }

    public static function instance(): ?self
    {
        return static::first();
    }

    public static function enabledModules(): array
    {
        return static::instance()?->enabled_modules ?? [];
    }

    public static function moduleRequiresApproval(string $module): bool
    {
        $moduleBasename = class_basename($module);

        return collect(static::enabledModules())
            ->contains(fn (string $enabledModule): bool => $enabledModule === $module || class_basename($enabledModule) === $moduleBasename);
    }

    public function getEnabledModulesAttribute($value): array
    {
        return is_array($value) ? $value : (json_decode($value ?? '[]', true) ?: []);
    }

    public static function approvedModuleOptions(): array
    {
        return collect(static::enabledModules())
            ->filter(fn (string $class): bool => static::supportsModule($class))
            ->mapWithKeys(fn (string $class): array => [
                class_basename($class) => ModelHelper::labelFromClass($class),
            ])
            ->toArray();
    }
}
