<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\HasApprovalLockUI;
use App\Filament\Resources\Concerns\HasSendForApprovalAction;
use App\Services\OptionalModuleService;
use App\Traits\HasApprovalWorkflow;
use App\Traits\HasSafeGlobalSearch;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

abstract class BaseResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && app(OptionalModuleService::class)->navigationEnabled(static::permissionKey());
    }

    use HasApprovalLockUI;
    use HasApprovalWorkflow;
    use HasSafeGlobalSearch;
    use HasSendForApprovalAction;

    protected static function permissionKey(): string
    {
        return str_replace('Resource', '', class_basename(static::class));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->applyVisibility(static::permissionKey());
    }
}
