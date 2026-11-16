<?php

namespace App\Filament\Resources\Concerns;

use App\Services\OptionalModuleService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

trait AuthorizesOptionalModule
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (! app(OptionalModuleService::class)->navigationEnabled(class_basename(static::getModel()))) {
            return Response::deny();
        }

        return parent::getAuthorizationResponse($action, $record);
    }
}
