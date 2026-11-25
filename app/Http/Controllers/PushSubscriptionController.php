<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\V1\StorePushTokenRequest;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PushSubscriptionController extends Controller
{
    public function store(StorePushTokenRequest $request): JsonResponse
    {
        abort_unless(config('services.firebase.enabled'), 503, 'Push notifications are not configured.');
        $data = $request->validated();
        $accessToken = $request->user()->currentAccessToken();
        PushSubscription::query()->updateOrCreate(['token_hash' => hash('sha256', $data['token'])], [
            'user_id' => $request->user()->id,
            'token' => $data['token'],
            'platform' => $data['platform'],
            'target_type' => $data['target_type'] ?? 'token',
            'access_token_id' => $accessToken instanceof PersonalAccessToken ? $accessToken->id : null,
            'session_key' => $request->hasSession() ? hash('sha256', $request->session()->getId()) : null,
        ]);

        return response()->json(['message' => 'Push notifications enabled.']);
    }

    public function destroy(StorePushTokenRequest $request): JsonResponse
    {
        PushSubscription::query()->where('user_id', $request->user()->id)
            ->where('token_hash', hash('sha256', $request->validated('token')))->delete();

        return response()->json(['message' => 'Push notifications disabled.']);
    }

    public function config(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => (bool) config('services.firebase.enabled'),
            'config' => config('services.firebase.public'),
            'vapid_key' => config('services.firebase.vapid_key'),
        ]]);
    }
}
