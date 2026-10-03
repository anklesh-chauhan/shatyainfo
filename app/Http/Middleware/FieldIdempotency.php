<?php

namespace App\Http\Middleware;

use App\Models\FieldSyncReceipt;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

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
                $response = $next($request);
                if (! $response->isSuccessful()) {
                    throw new HttpResponseException($response);
                }
                if ($response->isSuccessful()) {
                    FieldSyncReceipt::on($user->getConnectionName())->create([
                        'user_id' => $user->id, 'operation_id' => $key,
                        'request_hash' => $hash, 'status_code' => $response->getStatusCode(),
                        'response_body' => $response->getContent(),
                    ]);
                }

                return $response;
            });
        } catch (HttpResponseException $error) {
            return $error->getResponse();
        }
    }
}
