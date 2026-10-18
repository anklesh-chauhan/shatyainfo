<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CompanyDomain;
use App\Support\Tenancy\LandlordDomains;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RegistrationAccessController extends Controller
{
    public function captcha(Request $request): Response
    {
        $answer = '';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 6; $i++) {
            $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $request->session()->put('registration_captcha', ['answer' => $answer, 'expires' => time() + 600]);
        $image = imagecreatetruecolor(220, 70);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 247, 250));
        for ($i = 0; $i < 18; $i++) {
            imageline($image, random_int(0, 219), random_int(0, 69), random_int(0, 219), random_int(0, 69), imagecolorallocate($image, 150, 180, 190));
        }
        for ($i = 0; $i < 6; $i++) {
            imagestring($image, 5, 20 + $i * 31, random_int(18, 38), $answer[$i], imagecolorallocate($image, 25, 50, 70));
        }
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }

    public function sendVerification(Tenant $tenant): void
    {
        if ($tenant->provisioning_status !== 'awaiting_verification') {
            return;
        }
        $token = Str::random(64);
        Cache::store('registration')->put('registration.verify.'.hash('sha256', $token), $tenant->id, now()->addHour());
        $url = URL::temporarySignedRoute('signup.verify', now()->addHour(), ['token' => $token]);
        Mail::raw('Verify your email to create '.$tenant->name.': '.$url.' This link expires in one hour.', function ($message) use ($tenant): void {
            $message->to($tenant->provisioning_owner['email'])->subject('Verify your company registration');
        });
    }

    public function resend(Tenant $tenant): RedirectResponse
    {
        $this->sendVerification($tenant);

        return back()->with('status', 'Verification email sent. Check your inbox.');
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $key = 'registration.verify.'.hash('sha256', $token);

        return Cache::store('registration')->lock($key.'.lock', 10)->block(5, function () use ($request, $key): RedirectResponse {
            $tenantId = Cache::store('registration')->pull($key);
            abort_unless($tenantId, 410, 'Verification link expired or already used.');
            $tenant = Tenant::query()->findOrFail($tenantId);
            $tenant->getConnection()->transaction(function () use ($tenant): void {
                $record = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                abort_unless($record->provisioning_status === 'awaiting_verification', 410, 'Email has already been verified.');
                if ($record->provisioning_status === 'awaiting_verification') {
                    $owner = $record->provisioning_owner;
                    $owner['email_verified_at'] = now()->toDateTimeString();
                    $record->forceFill(['provisioning_owner' => $owner, 'provisioning_status' => 'pending'])->saveQuietly();
                    ProvisionTenant::dispatch($record->id);
                }
            });
            $request->session()->regenerate();
            $request->session()->forget('registration_login_token');
            $request->session()->put('verified_registration', $tenant->id);
            $request->session()->put('verified_registration_email', $tenant->provisioning_owner['email']);

            return redirect()->to(URL::temporarySignedRoute('signup.status', now()->addHours(6), ['tenant' => $tenant]));
        });
    }

    public function redirectUrl(Request $request, Tenant $tenant): ?string
    {
        if ($tenant->provisioning_status !== 'ready' || (string) $request->session()->get('verified_registration') !== (string) $tenant->id) {
            return null;
        }

        $email = $request->session()->get('verified_registration_email');
        if (! is_string($email) || $email === '') {
            return null;
        }

        $token = $request->session()->get('registration_login_token');
        if (! is_string($token) || $this->readHandoffToken($token) === null || $this->alreadyClaimed($token)) {
            $token = $this->issueHandoffToken($tenant->id, $email);
            $request->session()->put('registration_login_token', $token);
        }

        return CompanyDomain::url((string) $tenant->domain, '/registration/complete/'.$token);
    }

    public function complete(Request $request, string $token): RedirectResponse
    {
        $tenant = Tenant::current() ?? Tenant::query()->where('domain', LandlordDomains::normalize($request->getHost()))->first();
        abort_unless($tenant && $tenant->provisioning_status === 'ready', 403);
        if (! $tenant->isCurrent()) {
            $tenant->makeCurrent();
        }

        $handoff = $this->readHandoffToken($token) ?? $this->pullHandoff($this->handoffKey($token));
        abort_unless(is_array($handoff) && (string) $handoff['tenant_id'] === (string) $tenant->id, 403);
        abort_unless($this->claimOnce($token), 403);

        $user = User::query()->where('email', $handoff['email'])->whereNotNull('email_verified_at')->firstOrFail();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->away(CompanyDomain::url((string) $tenant->domain, '/admin'));
    }

    private function issueHandoffToken(int|string $tenantId, string $email): string
    {
        $payload = json_encode([
            'tenant_id' => (string) $tenantId,
            'email' => $email,
            'expires' => now()->addMinutes(30)->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return $this->encodeToken(Crypt::encryptString($payload));
    }

    /**
     * @return array{tenant_id: int|string, email: mixed}|null
     */
    private function readHandoffToken(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1) {
            return null;
        }

        try {
            $json = Crypt::decryptString($this->decodeToken($token));
        } catch (DecryptException) {
            return null;
        }

        $payload = json_decode($json, true);
        if (! is_array($payload) || ! isset($payload['tenant_id'], $payload['email'], $payload['expires'])) {
            return null;
        }

        if ((int) $payload['expires'] < now()->getTimestamp()) {
            return null;
        }

        return [
            'tenant_id' => $payload['tenant_id'],
            'email' => $payload['email'],
        ];
    }

    private function encodeToken(string $encrypted): string
    {
        return rtrim(strtr($encrypted, '+/', '-_'), '=');
    }

    private function decodeToken(string $token): string
    {
        $decoded = strtr($token, '-_', '+/');
        $padding = strlen($decoded) % 4;
        if ($padding > 0) {
            $decoded .= str_repeat('=', 4 - $padding);
        }

        return $decoded;
    }

    private function alreadyClaimed(string $token): bool
    {
        $key = $this->usedKey($token);

        try {
            return DB::connection($this->landlordConnection())->table($this->cacheTable())
                ->where('key', $key)
                ->where('expiration', '>', now()->getTimestamp())
                ->exists();
        } catch (QueryException) {
            try {
                return $this->registrationCache()->has($key);
            } catch (Throwable) {
                return false;
            }
        }
    }

    private function claimOnce(string $token): bool
    {
        $key = $this->usedKey($token);
        $expiration = now()->addMinutes(30)->getTimestamp();

        try {
            return DB::connection($this->landlordConnection())->table($this->cacheTable())->insertOrIgnore([
                'key' => $key,
                'value' => '1',
                'expiration' => $expiration,
            ]) > 0;
        } catch (QueryException) {
            try {
                return $this->registrationCache()->add($key, true, now()->addMinutes(30));
            } catch (Throwable) {
                return true;
            }
        }
    }

    private function usedKey(string $token): string
    {
        return 'registration-used:'.hash('sha256', $token);
    }

    private function landlordConnection(): string
    {
        return (string) config('multitenancy.landlord_database_connection_name', 'mysql');
    }

    private function cacheTable(): string
    {
        return (string) (config('cache.stores.registration.table') ?: config('cache.stores.database.table') ?: 'cache');
    }

    private function handoffKey(string $token): string
    {
        return 'registration.login.'.hash('sha256', $token);
    }

    private function registrationCache(): Repository
    {
        return Cache::store('registration');
    }

    /**
     * @return array{tenant_id: int|string, email: mixed}|null
     */
    private function pullHandoff(string $key): ?array
    {
        $handoff = $this->registrationCache()->pull($key);
        if (is_array($handoff)) {
            return $handoff;
        }

        $landlord = (string) config('multitenancy.landlord_database_connection_name', 'mysql');
        $table = (string) (config('cache.stores.registration.table') ?: config('cache.stores.database.table') ?: 'cache');

        try {
            $row = DB::connection($landlord)->table($table)
                ->where('key', 'like', '%'.$key)
                ->where('expiration', '>', now()->getTimestamp())
                ->orderByDesc('expiration')
                ->first();
        } catch (QueryException) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $value = $this->decodeCachedHandoff((string) $row->value);
        if ($value === null) {
            return null;
        }

        DB::connection($landlord)->table($table)->where('key', $row->key)->delete();

        return $value;
    }

    /**
     * @return array{tenant_id: int|string, email: mixed}|null
     */
    private function decodeCachedHandoff(string $value): ?array
    {
        try {
            $decoded = unserialize($value, ['allowed_classes' => false]);
        } catch (Throwable) {
            $decoded = null;
        }

        if (! is_array($decoded)) {
            $decoded = json_decode($value, true);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
