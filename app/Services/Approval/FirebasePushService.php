<?php

namespace App\Services\Approval;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebasePushService
{
    /** @param array<string, string> $payload */
    public function send(PushSubscription $subscription, array $payload): void
    {
        if (! config('services.firebase.enabled')) {
            return;
        }

        $path = config('services.firebase.credentials');
        if (! is_string($path) || ! is_readable($path)) {
            throw new RuntimeException('Configure FIREBASE_CREDENTIALS with a readable service-account JSON path.');
        }
        $credentials = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $project = $credentials['project_id'];
        $cacheKey = 'firebase-access-token:'.hash('sha256', $project.$credentials['client_email'].$credentials['private_key_id']);
        $accessToken = Cache::remember($cacheKey, 3300, fn (): string => $this->accessToken($credentials));

        $message = [
            $subscription->target_type === 'fid' ? 'fid' : 'token' => $subscription->token,
            'notification' => ['title' => $payload['title'], 'body' => $payload['body']],
            'data' => $payload,
            'android' => ['priority' => 'high'],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            'webpush' => ['fcm_options' => ['link' => $payload['url']]],
        ];
        $response = Http::withToken($accessToken)->timeout(15)
            ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send', ['message' => $message]);

        if (collect($response->json('error.details', []))->contains(fn (array $detail): bool => ($detail['errorCode'] ?? null) === 'UNREGISTERED')) {
            $subscription->delete();

            return;
        }
        if ($response->status() === 401) {
            Cache::forget($cacheKey);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Firebase push delivery failed (HTTP '.$response->status().').');
        }
    }

    /** @param array<string, string> $credentials */
    private function accessToken(array $credentials): string
    {
        $header = $this->encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->encode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        if (! openssl_sign($header.'.'.$claims, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Firebase access-token request.');
        }
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $header.'.'.$claims.'.'.$this->encode($signature),
        ]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Firebase service-account authentication failed.');
        }

        return $response->json('access_token');
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
