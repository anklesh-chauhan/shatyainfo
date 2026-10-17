<x-filament-panels::page>
    @if (! \App\Models\Tenant::current()->accountIsConfigured())
        <div wire:poll.3s="refreshSetup" role="status" aria-live="polite">
            <x-filament::section>
                <progress style="width: 100%;" max="100" value="{{ \App\Models\Tenant::current()->masterSetupProgress() }}" aria-label="Account setup progress"></progress>
                <p>{{ \App\Models\Tenant::current()->masterSetupProgress() }}% complete</p>
                <x-slot name="heading">We are configuring your account</x-slot>
                @if (\App\Models\Tenant::current()->master_setup_status === 'failed')
                    <p>We could not finish configuring your account. Your login and company details are safe. Please try again.</p>
                    <x-filament::button wire:click="retrySetup" wire:loading.attr="disabled">Try again</x-filament::button>
                @else
                    <p>It will take a moment to prepare your master data. This page will update automatically when your account is ready.</p>
                    <p>{{ \App\Models\Tenant::current()->master_setup_step ?: 'Preparing your account…' }}</p>
                @endif
            </x-filament::section>
        </div>
    @else
        <x-filament::section>
            <p>100% complete</p>
            <x-slot name="heading">Welcome to your workspace</x-slot>
            <x-slot name="description">Your account is ready. Add your organization details to get started.</x-slot>
            @if ($errors->any())
                <div role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form wire:submit="saveOrganization">
                @foreach (['name' => 'Organization name', 'email' => 'Organization email', 'phone' => 'Phone number'] as $field => $label)
                    <div>
                        <label for="org-{{ $field }}">{{ $label }} *</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="org-{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" wire:model="organization.{{ $field }}" required />
                        </x-filament::input.wrapper>
                    </div>
                @endforeach
                <x-filament::button type="submit" wire:loading.attr="disabled">Finish onboarding</x-filament::button>
                <x-filament::button color="gray" wire:click="skip">Finish later</x-filament::button>
            </form>
        </x-filament::section>
    @endif
    <form method="post" action="{{ route('filament.admin.auth.logout') }}">
        @csrf
        <x-filament::button type="submit" color="gray">Sign out</x-filament::button>
    </form>
</x-filament-panels::page>
