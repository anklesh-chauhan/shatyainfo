<?php

use App\Http\Middleware\FieldIdempotency;
use App\Models\FieldSyncReceipt;
use App\Models\User;
use App\Services\Field\FieldRecordedAt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'mysql', 'database.connections.mysql' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::purge('mysql');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    (require database_path('migrations/2026_09_23_214228_create_field_sync_receipts_table.php'))->up();
    $this->user = User::withoutEvents(fn () => User::query()->create(['name' => 'Protocol test']));
    $this->request = function (string $id, array $payload = []) {
        $request = Request::create('/api/v1/visits/1/check-in', 'POST', $payload);
        $request->headers->set('Idempotency-Key', $id);
        $request->setUserResolver(fn () => $this->user);

        return $request;
    };
});

it('commits a write and receipt once and replays the original response', function () {
    $count = 0;
    $handler = function () use (&$count) {
        $count++;

        return response()->json(['data' => ['id' => 1]], 201);
    };
    $id = (string) Str::uuid();
    $middleware = new FieldIdempotency;
    $first = $middleware->handle(($this->request)($id, ['latitude' => 1]), $handler);
    $second = $middleware->handle(($this->request)($id, ['latitude' => 1]), $handler);
    expect($count)->toBe(1)->and($second->getContent())->toBe($first->getContent())
        ->and($second->getStatusCode())->toBe(201)->and(FieldSyncReceipt::count())->toBe(1);
});

it('rejects mismatched payloads for an acknowledged operation', function () {
    $id = (string) Str::uuid();
    $middleware = new FieldIdempotency;
    $handler = fn () => response()->json(['ok' => true]);
    $middleware->handle(($this->request)($id, ['latitude' => 1]), $handler);
    try {
        $middleware->handle(($this->request)($id, ['latitude' => 2]), $handler);
        test()->fail('Expected conflict');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
});

it('rolls back writes and receipts when an operation throws', function () {
    try {
        (new FieldIdempotency)->handle(($this->request)((string) Str::uuid()), function () {
            User::query()->whereKey($this->user->id)->update(['name' => 'Changed']);
            throw new RuntimeException('Failure');
        });
    } catch (RuntimeException) {
    }
    expect($this->user->fresh()->name)->toBe('Protocol test')->and(FieldSyncReceipt::count())->toBe(0);
});

it('does not acknowledge rejected operations', function () {
    (new FieldIdempotency)->handle(($this->request)((string) Str::uuid()), function () {
        User::query()->whereKey($this->user->id)->update(['name' => 'Changed']);

        return response()->json(['message' => 'Invalid'], 422);
    });
    expect(FieldSyncReceipt::count())->toBe(0)->and($this->user->fresh()->name)->toBe('Protocol test');
});

it('validates the offline capture window', function () {
    $this->travelTo(now()->setTime(15, 0));
    expect(FieldRecordedAt::resolve(['recorded_at' => now()->subHours(2)->toIso8601String()])->hour)->toBe(13);
    expect(fn () => FieldRecordedAt::resolve(['recorded_at' => now()->addHour()->toIso8601String()]))->toThrow(ValidationException::class);
    expect(fn () => FieldRecordedAt::resolve(['recorded_at' => now()->subDays(8)->toIso8601String()]))->toThrow(ValidationException::class);
});
