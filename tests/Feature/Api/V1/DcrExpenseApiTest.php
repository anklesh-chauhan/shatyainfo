<?php

use App\Models\ExpenseType;
use App\Models\SalesDcrExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a manual expense only once when a queued upload is retried', function () {
    Sanctum::actingAs(reportingUser('ExpenseRep'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $type = ExpenseType::query()->create(['name' => 'Meals', 'code' => 'MEAL', 'is_active' => true]);
    $payload = ['expense_type_id' => $type->id, 'amount' => 125, 'remarks' => 'Field lunch'];
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', $payload, $headers)->assertSuccessful();
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', $payload, $headers)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(SalesDcrExpense::query()->where('sales_dcr_id', $id)->count())->toBe(1);
});

it('rejects invalid expense amounts', function () {
    Sanctum::actingAs(reportingUser('InvalidExpenseRep'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $type = ExpenseType::query()->create(['name' => 'Meals', 'code' => 'MEAL', 'is_active' => true]);
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', ['expense_type_id' => $type->id, 'amount' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
});
