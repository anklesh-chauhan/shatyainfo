<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Contracts\IsTenant;
use Throwable;

class RunsAcrossTenants
{
    public function each(callable $callback): void
    {
        $callback();

        $tenantModel = app(IsTenant::class);

        $tenantModel::query()->orderBy('id')->each(function (IsTenant $tenant) use ($callback): void {
            try {
                $tenant->execute($callback);
            } catch (Throwable $exception) {
                Log::error('Tenant task failed.', [
                    'tenant_id' => $tenant->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }
}
