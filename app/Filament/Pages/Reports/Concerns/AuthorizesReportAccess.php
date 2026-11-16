<?php

namespace App\Filament\Pages\Reports\Concerns;

use Illuminate\Support\Facades\Auth;

trait AuthorizesReportAccess
{
    public static function canAccess(): bool
    {
        return Auth::user()?->canAccessBusinessPage('View:'.class_basename(static::class)) ?? false;
    }
}
