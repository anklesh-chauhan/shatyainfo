<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Http;

class RegistrationCaptcha
{
    public static function usesTurnstile(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret'));
    }

    public static function verifyTurnstile(?string $token, ?string $ip): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        $response = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('services.turnstile.secret'),
            'response' => $token,
            'remoteip' => $ip,
        ]);

        return $response->ok() && $response->json('success') === true;
    }
}
