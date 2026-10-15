<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CompanyDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

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
        $token = $request->session()->get('registration_login_token');
        if (! is_string($token)) {
            $token = Str::random(64);
            $request->session()->put('registration_login_token', $token);
            Cache::store('registration')->put('registration.login.'.hash('sha256', $token), ['tenant_id' => $tenant->id, 'email' => $request->session()->get('verified_registration_email')], now()->addMinutes(5));
        }
        if (! Cache::store('registration')->has('registration.login.'.hash('sha256', $token))) {
            return null;
        }

        return preg_replace('~/admin/login$~', '/registration/complete/'.$token, CompanyDomain::loginUrl((string) $tenant->domain));
    }

    public function complete(Request $request, string $token): RedirectResponse
    {
        $tenant = Tenant::current();
        abort_unless($tenant && $tenant->provisioning_status === 'ready', 403);
        $key = 'registration.login.'.hash('sha256', $token);

        return Cache::store('registration')->lock($key.'.lock', 10)->block(5, function () use ($request, $tenant, $key): RedirectResponse {
            $handoff = Cache::store('registration')->get($key);
            abort_unless(is_array($handoff) && (string) $handoff['tenant_id'] === (string) $tenant->id, 403);
            $user = User::query()->where('email', $handoff['email'])->whereNotNull('email_verified_at')->firstOrFail();
            Cache::store('registration')->forget($key);
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return redirect('/admin');
        });
    }
}
