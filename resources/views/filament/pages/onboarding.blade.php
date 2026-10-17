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
        @php
            $checklist = $this->checklist();
            $completed = collect(['company', 'employees', 'review'])->filter(fn ($step) => $checklist[$step])->count();
        @endphp
        <x-filament::section>
            <x-slot name="heading">Set up your workspace</x-slot>
            <x-slot name="description">Work through this checklist at your own pace. Your progress is saved, and you can return from Setup Checklist.</x-slot>
            <div class="space-y-3">
                <p>{{ $completed }} of 3 required steps complete · Organization structure is optional</p>
                <progress style="width: 100%;" max="3" value="{{ $completed }}" aria-label="Onboarding checklist progress"></progress>
                <ol class="space-y-2" aria-label="Setup checklist">
                    @foreach (['company' => 'Company Information', 'structure' => 'Organization Structure (optional)', 'employees' => 'Employees', 'review' => 'Review & Finish'] as $key => $label)
                        <li><a href="#setup-{{ $key }}">{{ $loop->iteration }}. {{ $label }}</a> — <strong>{{ $checklist[$key] ? 'Complete' : 'To do' }}</strong></li>
                    @endforeach
                </ol>
            </div>
        </x-filament::section>
        <x-filament::section id="setup-company">
            <x-slot name="heading">1. Company Information</x-slot>
            <x-slot name="description">Required · Save your company name and contact details first.</x-slot>
            @if ($errors->any())
                <div role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form wire:submit="saveOrganization" class="space-y-4">
                @foreach (['name' => 'Company name', 'email' => 'Company email', 'phone' => 'Phone number'] as $field => $label)
                    <div>
                        <label for="org-{{ $field }}">{{ $label }} *</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="org-{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" wire:model="organization.{{ $field }}" required />
                        </x-filament::input.wrapper>
                    </div>
                @endforeach
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="saveOrganization">Save company information</x-filament::button>
            </form>
        </x-filament::section>
        <x-filament::section id="setup-structure">
            <x-slot name="heading">2. Organization Structure</x-slot>
            <x-slot name="description">Optional · Set up divisions and any child organizational units.</x-slot>
            <div class="space-y-3">
                <p>{{ $checklist['unit_count'] }} organizational units added. Create a unit with Type = Division, then add child units where needed.</p>
                <p>You can also create divisions, departments, job roles, positions, grades and job titles in the employee Excel import. Create parent/child OU relationships here before importing child units.</p>
                @if ($checklist['company'])
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\OrganizationalUnits\OrganizationalUnitResource::getUrl('index', panel: 'admin')">Set up organization structure</x-filament::button>
                @else
                    <p>Save Company Information to start this step.</p>
                @endif
            </div>
        </x-filament::section>
        <x-filament::section id="setup-employees">
            <x-slot name="heading">3. Employees</x-slot>
            <x-slot name="description">Required · Add your team, positions and reporting relationships.</x-slot>
            <div class="space-y-3">
                <p>{{ $checklist['employee_count'] }} employees added. Download the Excel template, read its Instructions sheet, and upload your completed Employees sheet.</p>
                <p>Use position codes for reporting relationships. Managers and their team members can appear in any row order.</p>
                @if ($checklist['company'])
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button tag="a" :href="\App\Filament\Pages\DataImport::getUrl(['module' => 'employees'], panel: 'admin')">Import employees from Excel</x-filament::button>
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\Employees\EmployeeResource::getUrl('index', panel: 'admin')">Manage employees</x-filament::button>
                    </div>
                @else
                    <p>Save Company Information to start this step.</p>
                @endif
            </div>
        </x-filament::section>
        <x-filament::section id="setup-review">
            <x-slot name="heading">4. Review &amp; Finish</x-slot>
            <x-slot name="description">Required · Confirm your team and reporting structure are ready.</x-slot>
            <div class="space-y-3">
                <p>Check employee details, primary positions and reporting managers. Top-level positions can have no reporting manager.</p>
                @if ($checklist['company'])
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\Positions\PositionResource::getUrl('index', panel: 'admin')">Review positions and reporting</x-filament::button>
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button wire:click="finish" wire:loading.attr="disabled" :disabled="! $checklist['employees']">Finish onboarding</x-filament::button>
                        <x-filament::button color="gray" wire:click="skip" wire:loading.attr="disabled">Continue later</x-filament::button>
                    </div>
                    <p>Continue later opens your workspace. Your unfinished checklist remains available.</p>
                @else
                    <p>Complete Company Information and Employees before finishing setup.</p>
                @endif
            </div>
        </x-filament::section>
    @endif
    <form method="post" action="{{ route('filament.admin.auth.logout') }}">
        @csrf
        <x-filament::button type="submit" color="gray">Sign out</x-filament::button>
    </form>
</x-filament-panels::page>
