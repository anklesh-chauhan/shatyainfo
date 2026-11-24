<?php

namespace App\Services\Field;

use App\Jobs\ProcessFieldTravelImport;
use App\Models\AccountMasterPatch;
use App\Models\Patch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\FieldTravelImport;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FieldPatchWorkbook
{
    public function __construct(private FieldTravelImport $importer, private FieldPatchService $patches, private FieldTerritoryService $territories) {}

    public function canAccess(User $actor): bool
    {
        return $actor->can(['ViewAny:Patch', 'View:Patch', 'Create:Patch']) && CompanyOnboardingService::canAccess($actor);
    }

    public function authorizePlans(User $actor, bool $lock = false): Closure
    {
        if (! $this->canAccess($actor)) {
            throw new AuthorizationException;
        }

        return function (string $sheet, Patch|AccountMasterPatch $record, ?Patch $parent, string $action) use ($actor, $lock): void {
            $patch = $parent ?? $record;
            $territory = $this->patches->assignableTerritories($actor)->with('approval')
                ->when($lock, fn ($query) => $query->lockForUpdate())->find($patch->territory_id);
            if (! $territory || $this->territories->locked($actor, $territory)) {
                throw ValidationException::withMessages(['file' => 'Choose a territory you can assign and edit.']);
            }
            $pin = $patch->city_pin_code_id;
            if ($pin < 0) {
                throw ValidationException::withMessages(['file' => 'Add the city and PIN to the territory before importing patches.']);
            }
            if ($patch->exists) {
                $current = $this->patches->visible($actor)->with(['approval', 'territory.approval'])
                    ->when($lock, fn ($query) => $query->lockForUpdate())->find($patch->getKey());
                if (! $current || ! $current->territory || $actor->cannot('view', $current)
                    || ! $this->patches->assignableTerritories($actor)->whereKey($current->territory_id)->exists()
                    || $this->territories->locked($actor, $current->territory) || $this->territories->locked($actor, $current)) {
                    throw new AuthorizationException;
                }
                if ($lock && $current->getRawOriginal() !== $patch->getRawOriginal()) {
                    throw ValidationException::withMessages(['file' => 'The patch changed after preview. Preview the workbook again.']);
                }
                $moving = (int) $current->territory_id !== (int) $patch->territory_id;
                if ($moving && $pin !== null && ! $territory->cityPinCodes()->whereKey($pin)->exists()) {
                    throw ValidationException::withMessages(['file' => 'The patch PIN must belong to its new territory.']);
                }
                if ($sheet === 'Patches' && $action === 'Update' && ($moving || $current->city_pin_code_id !== $pin)) {
                    $this->patches->validateRouteChange($current, (int) $patch->territory_id, $pin, $moving);
                }
            }
            if ($record instanceof AccountMasterPatch && (! $actor->can(['ViewAny:AccountMaster', 'View:AccountMaster', 'Update:AccountMaster'])
                || ! $this->patches->visibleCustomers($actor)->whereKey($record->account_master_id)->when($lock, fn ($query) => $query->lockForUpdate())->first())) {
                throw new AuthorizationException;
            }
            if ($lock && $record instanceof AccountMasterPatch && $record->exists) {
                $current = AccountMasterPatch::query()->where('patch_id', $record->patch_id)
                    ->where('account_master_id', $record->account_master_id)->lockForUpdate()->first();
                if (! $current || $current->getRawOriginal() !== $record->getRawOriginal()) {
                    throw ValidationException::withMessages(['file' => 'A customer assignment changed after preview. Preview the workbook again.']);
                }
            }
        };
    }

    public function preview(User $actor, UploadedFile $file): array
    {
        $authorize = $this->authorizePlans($actor);
        try {
            $rows = $this->importer->read($file->getRealPath(), includeAllSheets: true);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'Choose a valid Excel .xlsx workbook.']);
        }
        if (array_diff(array_keys($rows), ['Patches', 'Patch Customers']) !== []) {
            throw ValidationException::withMessages(['file' => 'Use the Patches and Patch Customers sheets here. Import fares and travel segments in Admin.']);
        }
        $preview = $this->importer->preview($rows, $authorize);

        return Cache::lock($this->key($actor).':lock', 30)->block(5, function () use ($actor, $rows, $preview): array {
            $previous = $this->latestToken($actor);
            $status = $previous ? $this->read($actor, $previous)['status'] : null;
            abort_if(in_array($status, ['queued', 'running'], true), 409, 'A workbook is already being imported.');
            if ($preview['errors'] !== []) {
                if ($previous && $status === 'preview') {
                    Storage::disk('local')->delete($this->path($actor, $previous));
                    Cache::store('file')->forget($this->key($actor));
                }

                return ['status' => 'invalid', 'errors' => array_slice($preview['errors'], 0, 50), 'error_count' => count($preview['errors'])];
            }
            $token = (string) Str::uuid();
            $this->write($actor, $token, [
                'tenant_id' => Tenant::current()?->id,
                'patch_register' => true,
                'status' => 'preview',
                'rows' => $rows,
                'fingerprint' => $preview['fingerprint'],
                'plans' => array_map(fn (array $plan): array => array_intersect_key($plan, array_flip(['sheet', 'row', 'key', 'action', 'values'])), $preview['plans']),
            ]);
            Cache::store('file')->put($this->key($actor), $token, now()->addDays(7));
            if ($previous && $status === 'preview') {
                Storage::disk('local')->delete($this->path($actor, $previous));
            }

            return $this->status($actor, $token, 1);
        });
    }

    public function latest(User $actor): ?array
    {
        abort_unless($this->canAccess($actor), 403);
        $token = $this->latestToken($actor);

        return $token === null ? null : $this->status($actor, $token, 1);
    }

    public function dispatch(User $actor, string $token): array
    {
        abort_unless($this->canAccess($actor), 403);

        return Cache::lock($this->key($actor).':lock', 30)->block(5, function () use ($actor, $token): array {
            $payload = $this->read($actor, $token);
            if ($payload['status'] === 'preview') {
                $payload['status'] = 'queued';
                $this->write($actor, $token, $payload);
                try {
                    dispatch(new ProcessFieldTravelImport($token, $actor->id, Tenant::current()?->id));
                } catch (Throwable $exception) {
                    $payload['status'] = 'preview';
                    $this->write($actor, $token, $payload);
                    report($exception);
                    abort(503, 'Could not queue this workbook. Try again.');
                }
            }

            return $this->status($actor, $token, 1);
        });
    }

    public function status(User $actor, string $token, int $page): array
    {
        abort_unless($this->canAccess($actor), 403);
        $payload = $this->read($actor, $token);
        $job = new ProcessFieldTravelImport($token, $actor->id, Tenant::current()?->id);
        $plans = $payload['status'] === 'preview' ? $payload['plans'] : [];

        return [
            'token' => $token,
            'status' => $payload['status'],
            'plans' => array_slice($plans, ($page - 1) * 50, 50),
            'last_page' => max(1, (int) ceil(count($plans) / 50)),
            'progress' => Cache::store('file')->get($job->progressKey(), ['processed' => 0, 'total' => count($payload['plans'])]),
            'result' => $payload['result'] ?? [],
            'errors' => $payload['errors'] ?? [],
        ];
    }

    private function key(User $actor): string
    {
        return 'patch-workbook:'.(Tenant::current()?->id ?? 'landlord').':'.$actor->id;
    }

    private function latestToken(User $actor): ?string
    {
        $token = Cache::store('file')->get($this->key($actor));

        return is_string($token) && Storage::disk('local')->exists($this->path($actor, $token)) ? $token : null;
    }

    private function path(User $actor, string $token): string
    {
        abort_unless(Str::isUuid($token), 404);

        return 'field-travel-imports/'.$actor->id.'/'.$token.'.json';
    }

    private function read(User $actor, string $token): array
    {
        $json = Storage::disk('local')->get($this->path($actor, $token));
        abort_unless($json !== null, 404);
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($payload['patch_register'] ?? false) && ($payload['tenant_id'] ?? null) === Tenant::current()?->id, 404);

        return $payload;
    }

    private function write(User $actor, string $token, array $payload): void
    {
        abort_unless(Storage::disk('local')->put($this->path($actor, $token), json_encode($payload, JSON_THROW_ON_ERROR)), 503);
    }
}
