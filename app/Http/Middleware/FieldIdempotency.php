<?php

namespace App\Http\Middleware;

use App\Models\FieldSyncReceipt;
use App\Models\FieldSyncRecovery;
use App\Models\User;
use App\Services\Field\FieldConflictResolutionService;
use App\Services\Field\FieldReplacementService;
use App\Services\Field\FieldSyncRecoveryService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FieldIdempotency
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($request->isMethodSafe() || $key === null || $request->is('api/v1/logout')) {
            return $next($request);
        }
        abort_unless(Str::isUuid($key), 422, 'Idempotency-Key must be a UUID.');
        abort_if(FieldSyncRecovery::query()->where('replacement_event_id', $key)
            ->orWhereJsonContains('review_history', ['replacement_event_id' => $key])->exists(), 409,
            'Replacement identities are reserved for reviewed posting. Retry the original failed operation.');
        $files = [];
        foreach (Arr::dot($request->allFiles()) as $name => $file) {
            abort_unless($file->isValid(), 422, 'An uploaded file could not be read.');
            $files[$name] = hash_file('sha256', $file->getRealPath());
        }
        $hash = hash('sha256', json_encode([
            $request->method(), $request->path(),
            Arr::sortRecursive($request->except(array_keys($request->allFiles()))),
            Arr::sortRecursive($files),
        ], JSON_THROW_ON_ERROR));
        $user = $request->user();

        try {
            return $user->getConnection()->transaction(function () use ($user, $key, $hash, $request, $next): Response {
                $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $receipt = FieldSyncReceipt::on($user->getConnectionName())->where('user_id', $user->id)->where('operation_id', $key)->first();
                if ($receipt) {
                    abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This operation ID was already used for a different request.');

                    return response($receipt->response_body, $receipt->status_code, [
                        'Content-Type' => 'application/json', 'Idempotency-Replayed' => 'true',
                    ]);
                }
                $recovery = FieldSyncRecovery::query()->where('user_id', $user->id)->where('operation_id', $key)->lockForUpdate()->first();
                if ($recovery) {
                    abort_unless(hash_equals($recovery->request_hash, $hash), 409, 'This failed operation is bound to its original request. Retry without changing its payload or attachments.');
                    if ($recovery->resolution_mode !== 'keep_server' && $recovery->status === 'approved' && $recovery->expires_at?->isFuture()
                        && User::query()->find($recovery->reviewer_id)?->can('Review:FieldSyncRecovery')) {
                        $request->attributes->set('field_reporting_recovery', ['user_id' => $user->id, 'date' => $recovery->report_date?->toDateString(), 'captured_date' => $recovery->captured_at?->toDateString()]);
                    }
                }
                $response = match ($recovery?->resolution_mode) {
                    'keep_server' => app(FieldConflictResolutionService::class)->acknowledge($request, $recovery),
                    'replacement' => app(FieldReplacementService::class)->apply($request, $recovery),
                    default => $next($request),
                };
                if (! $response->isSuccessful()) {
                    throw new HttpResponseException($response);
                }
                if ($response->isSuccessful()) {
                    FieldSyncReceipt::on($user->getConnectionName())->create([
                        'user_id' => $user->id, 'operation_id' => $key,
                        'request_hash' => $hash, 'status_code' => $response->getStatusCode(),
                        'response_body' => $response->getContent(),
                    ]);
                    $recovery?->update(['status' => 'recovered', 'recovered_at' => now()]);
                }

                return $response;
            });
        } catch (HttpResponseException $error) {
            if (in_array($error->getResponse()->getStatusCode(), [409, 422], true)) {
                app(FieldSyncRecoveryService::class)->recordFailure($request, $key, $hash, $files, $error->getResponse()->getContent());
            }

            return $error->getResponse();
        } catch (ValidationException $error) {
            app(FieldSyncRecoveryService::class)->recordFailure($request, $key, $hash, $files, implode(' ', Arr::flatten($error->errors())));
            throw $error;
        } catch (HttpExceptionInterface $error) {
            if (in_array($error->getStatusCode(), [409, 422], true)) {
                app(FieldSyncRecoveryService::class)->recordFailure($request, $key, $hash, $files, $error->getMessage());
            }
            throw $error;
        } finally {
            $request->attributes->remove('field_reporting_recovery');
        }
    }
}
