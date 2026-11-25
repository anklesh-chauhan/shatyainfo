<?php

use App\Filament\Pages\MyApprovals;
use App\Jobs\SendApprovalPush;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\PushSubscription;
use App\Models\SalesTourPlan;
use App\Services\Approval\ApprovalService;
use App\Services\Approval\FirebasePushService;
use Database\Seeders\MyApprovalsPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('converts legacy notification text to json and renders the unread notification count', function () {
    $user = reportingUser('Notification migration');
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    $migration = require database_path('migrations/tenant/2026_11_25_235554_convert_notification_data_to_json.php');
    $migration->down();
    $data = ['format' => 'filament', 'title' => 'Approval requested', 'body' => 'Review the submitted request.'];
    $notification = $user->notifications()->create([
        'id' => (string) str()->uuid(), 'type' => 'approval-migration-test', 'data' => $data,
    ]);

    $migration->up();

    expect(Schema::getColumnType('notifications', 'data'))->toBe('json')
        ->and($notification->fresh()->data)->toBe($data)
        ->and(app(DatabaseNotifications::class)->getUnreadNotificationsCount())->toBe(1);
    livewire(DatabaseNotifications::class)->assertSuccessful();
});

function inboxFixture(): array
{
    $requester = reportingUser('Inbox requester');
    $reviewer = reportingUser('Inbox reviewer');
    $next = reportingUser('Next reviewer');
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan']]);
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'active' => true]);
    foreach ([$reviewer, $next] as $index => $user) {
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => $index + 1,
            'routing_type' => 'specific_employee', 'approver_employee_id' => $user->employee->id, 'territory_scope' => 'all']);
    }
    test()->actingAs($requester);
    $service = app(ApprovalService::class);
    $approvals = collect([1, 2])->map(fn (int $month) => $service->start(
        SalesTourPlan::query()->create(['user_id' => $requester->id, 'month' => '2026-0'.$month.'-01']), 'SalesTourPlan'));

    return compact('requester', 'reviewer', 'next', 'service', 'approvals');
}

it('opens my approvals to every authenticated user while keeping records assigned', function () {
    $fixture = inboxFixture();
    Sanctum::actingAs($fixture['requester']);
    expect(MyApprovals::canAccess())->toBeTrue();
    $this->getJson('/api/v1/my-approvals')->assertOk()->assertJsonCount(0, 'data');
    Sanctum::actingAs($fixture['reviewer']);
    $this->getJson('/api/v1/my-approvals')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonFragment(['screen_key' => 'my-approvals']);
});

it('seeds my approvals for all existing roles and both guards without losing permissions', function () {
    foreach (['web', 'tenant'] as $guard) {
        Role::findOrCreate('custom_reviewer', $guard);
    }
    $this->seed(MyApprovalsPermissionSeeder::class);
    $this->seed(MyApprovalsPermissionSeeder::class);
    foreach (['web', 'tenant'] as $guard) {
        expect(Role::findByName('custom_reviewer', $guard)->hasPermissionTo('View:MyApprovals'))->toBeTrue();
    }
});

it('approves only current assigned selections and refuses a stale batch atomically', function () {
    $fixture = inboxFixture();
    $ids = $fixture['approvals']->map(fn ($approval) => $approval->currentStep()->id)->all();
    Sanctum::actingAs($fixture['next']);
    $this->postJson('/api/v1/my-approvals/bulk-decision', ['steps' => $ids, 'decision' => 'approve'])->assertConflict();
    Sanctum::actingAs($fixture['reviewer']);
    $this->postJson('/api/v1/my-approvals/bulk-decision', ['steps' => [$ids[0], 999999], 'decision' => 'approve'])->assertConflict();
    expect($fixture['approvals'][0]->currentStep()->id)->toBe($ids[0]);
    $this->postJson('/api/v1/my-approvals/bulk-decision', ['steps' => $ids, 'decision' => 'approve'])->assertOk()->assertJsonPath('data.count', 2);
    $this->postJson('/api/v1/my-approvals/bulk-decision', ['steps' => $ids, 'decision' => 'approve'])->assertConflict();
    expect($fixture['next']->notifications()->count())->toBe(2);
});

it('requires rejection comments and notifies requesters after bulk rejection', function () {
    $fixture = inboxFixture();
    $ids = $fixture['approvals']->map(fn ($approval) => $approval->currentStep()->id)->all();
    Sanctum::actingAs($fixture['reviewer']);
    $this->postJson('/api/v1/my-approvals/bulk-decision', ['steps' => $ids, 'decision' => 'reject'])->assertUnprocessable();
    $this->postJson('/api/v1/my-approvals/bulk-decision', ['steps' => [$ids[0], $ids[0]], 'decision' => 'approve'])->assertUnprocessable();
    $this->postJson('/api/v1/my-approvals/bulk-decision', ['steps' => $ids, 'decision' => 'reject', 'comments' => 'Revise the dates.'])->assertOk();
    expect($fixture['requester']->notifications()->count())->toBe(2)
        ->and($fixture['requester']->notifications()->first()->data['status'])->toBe('rejected');
});

it('provides web bulk actions and notifies only the current reviewer on submission', function () {
    $fixture = inboxFixture();
    expect($fixture['reviewer']->notifications()->count())->toBe(2)
        ->and($fixture['next']->notifications()->count())->toBe(0);
    $this->actingAs($fixture['reviewer']);
    Filament::setCurrentPanel('admin');
    $steps = $fixture['approvals']->map(fn ($approval) => $approval->currentStep());
    livewire(MyApprovals::class)->assertCanSeeTableRecords($steps)
        ->selectTableRecords($steps->pluck('id')->all())
        ->callAction(TestAction::make('approveSelected')->table()->bulk());
    expect($fixture['next']->notifications()->count())->toBe(2);
});

it('isolates notification reads and push tokens by user and removes mobile tokens on logout', function () {
    config(['services.firebase.enabled' => true]);
    Queue::fake([SendApprovalPush::class]);
    $fixture = inboxFixture();
    Queue::assertPushed(SendApprovalPush::class, fn (SendApprovalPush $job): bool => $job->connection === 'database'
        && $job->queue === 'approval-notifications'
        && $job->afterCommit === true
        && $job->userId === $fixture['reviewer']->id);
    $notification = $fixture['reviewer']->notifications()->first();
    Sanctum::actingAs($fixture['requester']);
    $this->postJson('/api/v1/approval-notifications/'.$notification->id.'/read')->assertNotFound();
    Sanctum::actingAs($fixture['reviewer']);
    $this->getJson('/api/v1/approval-notifications')->assertOk()->assertJsonPath('unread_count', 2);
    $this->postJson('/api/v1/approval-notifications/'.$notification->id.'/read')->assertOk();
    $this->getJson('/api/v1/approval-notifications')->assertJsonPath('unread_count', 1);
    $token = $fixture['reviewer']->createToken('push test');
    $this->app['auth']->forgetGuards();
    $this->withToken($token->plainTextToken)->postJson('/api/v1/push-subscriptions', ['token' => 'test-fcm-token', 'platform' => 'android'])->assertOk();
    $subscription = PushSubscription::query()->sole();
    expect($subscription->token)->toBe('test-fcm-token')
        ->and($subscription->getRawOriginal('token'))->not->toBe('test-fcm-token');
    $this->withToken($token->plainTextToken)->postJson('/api/v1/logout')->assertOk();
    expect(PushSubscription::query()->count())->toBe(0);
});

it('sends Firebase HTTP v1 notifications and removes unregistered devices', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $privateKey);
    $path = tempnam(sys_get_temp_dir(), 'firebase-test-');
    file_put_contents($path, json_encode(['project_id' => 'test-project', 'client_email' => 'test@example.test',
        'private_key_id' => 'test-key', 'private_key' => $privateKey], JSON_THROW_ON_ERROR));
    config(['services.firebase.enabled' => true, 'services.firebase.credentials' => $path]);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token']),
        'fcm.googleapis.com/*' => Http::sequence()
            ->push(['name' => 'projects/test-project/messages/123'])
            ->push(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);
    try {
        $subscription = PushSubscription::factory()->create(['target_type' => 'fid']);
        $service = app(FirebasePushService::class);
        $payload = ['title' => 'Approval requested', 'body' => 'A request is waiting.', 'url' => 'https://company.example/admin/my-approvals'];
        $service->send($subscription, $payload);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
            && $request['message']['fid'] === $subscription->token
            && $request['message']['data'] === $payload);
        $service->send($subscription, $payload);
        expect(PushSubscription::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
});
