<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanyRegistrationRequest;
use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use App\Support\Tenancy\CompanyDomain;
use App\Support\Tenancy\RegistrationCaptcha;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MarketingController extends Controller
{
    public function home(): View|RedirectResponse
    {
        if (Tenant::checkCurrent()) {
            return redirect('/admin/login');
        }

        return view('marketing.home', [
            'baseDomain' => CompanyDomain::base(),
        ]);
    }

    public function create(): View
    {
        return view('marketing.register', [
            'baseDomain' => CompanyDomain::base(),
            'turnstileSiteKey' => RegistrationCaptcha::usesTurnstile() ? config('services.turnstile.site_key') : null,
        ]);
    }

    public function addressAvailability(Request $request): JsonResponse
    {
        return response()->json(CompanyDomain::checkSlug((string) $request->query('slug', '')));
    }

    public function store(CompanyRegistrationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Tenant::$pendingOwner = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ];

        try {
            $tenant = new Tenant;
            $tenant->forceFill([
                'name' => $data['company'],
                'domain' => CompanyDomain::fromSlug($data['slug']),
                'provisioning_status' => 'awaiting_verification',
            ])->save();
        } catch (ValidationException $exception) {
            Tenant::$pendingOwner = null;

            throw $exception;
        } catch (QueryException $exception) {
            Tenant::$pendingOwner = null;

            throw ValidationException::withMessages([
                'slug' => 'That company address is already in use.',
            ]);
        }

        app(RegistrationAccessController::class)->sendVerification($tenant);

        return redirect()->to($this->statusUrl($tenant));
    }

    public function status(Request $request, Tenant $tenant): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'status' => $tenant->provisioning_status,
                'progress' => $tenant->provisioningProgress(),
                'step' => $tenant->provisioning_step,
                'redirect_url' => app(RegistrationAccessController::class)->redirectUrl($request, $tenant),
                'login_url' => $tenant->provisioning_status === 'ready'
                    ? CompanyDomain::loginUrl((string) $tenant->domain)
                    : null,
            ]);
        }

        return view('marketing.status', [
            'tenant' => $tenant,
            'loginUrl' => CompanyDomain::loginUrl((string) $tenant->domain),
            'resendUrl' => URL::temporarySignedRoute('signup.resend', now()->addHours(6), ['tenant' => $tenant]),
            'retryUrl' => URL::temporarySignedRoute(
                'signup.retry',
                now()->addHours(6),
                ['tenant' => $tenant],
            ),
        ]);
    }

    public function retry(Tenant $tenant): RedirectResponse
    {
        if ($tenant->provisioning_status === 'failed') {
            $tenant->forceFill([
                'provisioning_status' => 'pending',
                'provisioning_error' => null,
            ])->saveQuietly();

            ProvisionTenant::dispatch($tenant->id);
        }

        return redirect()->to($this->statusUrl($tenant));
    }

    public function loginForm(): View
    {
        return view('marketing.login', [
            'baseDomain' => CompanyDomain::base(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:255'],
        ]);

        $domain = CompanyDomain::resolve($data['company']);
        $tenant = Tenant::query()->where('domain', $domain)->first();

        if (! $tenant) {
            throw ValidationException::withMessages([
                'company' => 'No company is registered at that address.',
            ]);
        }

        if ($tenant->provisioning_status !== 'ready') {
            throw ValidationException::withMessages([
                'company' => 'That company is still being set up. Open the setup page from your registration if you have it.',
            ]);
        }

        return redirect()->away(CompanyDomain::loginUrl($domain));
    }

    private function statusUrl(Tenant $tenant): string
    {
        return URL::temporarySignedRoute(
            'signup.status',
            now()->addHours(6),
            ['tenant' => $tenant],
        );
    }
}
