<div class="setup-shell">
    @include('filament.pages.partials.onboarding-styles')
    @if (! \App\Models\Tenant::current()->accountIsConfigured())
        <div wire:poll.3s="refreshSetup" role="status" aria-live="polite">
            <x-filament::section>
                <x-slot name="heading">We are configuring your account</x-slot>
                <progress style="width:100%" max="100" value="{{ \App\Models\Tenant::current()->masterSetupProgress() }}" aria-label="Account setup progress"></progress>
                <p>{{ \App\Models\Tenant::current()->masterSetupProgress() }}% complete</p>
                @if (\App\Models\Tenant::current()->master_setup_status === 'failed')
                    <p>We could not finish configuring your account. Your details are safe.</p>
                    <x-filament::button wire:click="retrySetup" wire:loading.attr="disabled">Try again</x-filament::button>
                @else
                    <p>{{ \App\Models\Tenant::current()->master_setup_step ?: 'Preparing your account…' }}</p>
                @endif
            </x-filament::section>
        </div>
    @else
        @php
            $checklist = $this->checklist();
            $titles = ['A great place to start.', 'Tell us about your company.', 'Map your territories.', 'Bring your team together.', 'Prepare your workspace.', 'Choose how approvals work.', 'Ready for your first day.'];
            $descriptions = ['A few simple steps to make this workspace yours.', 'These details help your team recognize your workspace.', 'Create the territory hierarchy your team will work in.', 'Add employees and give everyone a place in your organization.', 'Optionally import accounts, field routes, products, shift assignments and holidays. You can complete these later.', 'Set approval levels and approvers for the modules your team uses.', 'Review your setup, then open your workspace.'];
        @endphp
        <div class="setup-wizard">
            <aside class="setup-intro">
                <div class="setup-brand">@include('filament.components.tenant-brand')</div>
                <p class="setup-eyebrow">YOUR WORKSPACE, YOUR TEAM</p>
                <h1>{{ $titles[$step] }}</h1>
                <p class="setup-description">{{ $descriptions[$step] }}</p>
                <ol class="setup-steps" aria-label="Setup progress">
                    @foreach (['Company information', 'Territories', 'Add employees', 'Optional imports', 'Approvals', 'Review & finish'] as $label)
                        <li class="{{ $step === $loop->iteration ? 'is-current' : '' }}" @if ($step === $loop->iteration) aria-current="step" @endif><span class="setup-step-number" aria-hidden="true">{{ $step > $loop->iteration ? '✓' : $loop->iteration }}</span>{{ $label }}</li>
                    @endforeach
                </ol>
                <p class="setup-account">Signed in as<br>{{ auth()->user()->email }}</p>
            </aside>
            <section class="setup-content" aria-label="Current setup step" wire:key="setup-step-{{ $step }}">
                <p class="setup-step-label">{{ $step === 0 ? 'WELCOME' : 'STEP '.$step.' OF 6' }}</p>
                @if ($errors->any())<div class="setup-error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                @if ($step === 0)
                    <div class="setup-welcome-art" aria-hidden="true"><span>✦</span><span>✓</span><span>＋</span></div>
                    <h2>Let’s set things up</h2>
                    <p>Start with your company details. Then add your employees and review how your team is organized.</p>
                    <div class="setup-note">You can continue later after saving your company information.</div>
                    <div class="setup-actions"><x-filament::button wire:click="next" wire:loading.attr="disabled" size="lg">Get started</x-filament::button></div>
                @elseif ($step === 1)
                    <h2>Company information</h2><p>Confirm the details below to personalize your workspace.</p>
                    <form wire:submit="saveOrganization" class="setup-form">
                        <div class="setup-field">
                            <label for="company-logo">Company logo (optional)</label>
                            @if ($logoUrl = $this->companyLogoUrl())
                                <img src="{{ $logoUrl }}" alt="Company logo preview" style="max-width:200px;height:100px;object-fit:contain;margin-bottom:12px">
                            @endif
                            <div class="setup-upload-box"><input id="company-logo" type="file" accept="image/jpeg,image/png,image/gif,image/webp" wire:model="companyLogo" aria-describedby="company-logo-help"></div>
                            <p id="company-logo-help" class="setup-card-help">JPG, PNG, GIF or WebP. Maximum 2 MB. Saved when you select Save and continue.</p>
                            <p wire:loading wire:target="companyLogo" role="status">Uploading logo…</p>
                        </div>
                        @foreach (['name' => 'Company name', 'email' => 'Company email', 'phone' => 'Phone number'] as $field => $label)
                            <div class="setup-field"><label for="org-{{ $field }}">{{ $label }}</label><x-filament::input.wrapper :valid="! $errors->has('organization.'.$field)"><x-filament::input id="org-{{ $field }}" type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" wire:model="organization.{{ $field }}" required maxlength="{{ $field === 'phone' ? 30 : 255 }}" /></x-filament::input.wrapper></div>
                        @endforeach
                        <div class="setup-field"><label for="uses-divisions">Does your company use divisions?</label><x-filament::input.wrapper><x-filament::input.select id="uses-divisions" wire:model="organization.uses_divisions"><option value="1">Yes, use divisions</option><option value="0">No divisions</option></x-filament::input.select></x-filament::input.wrapper><p class="setup-hint">No makes division links optional for territories. Existing divisions and links are kept.</p></div>
                        <p class="setup-hint">Additional company details (optional)</p>
                        <div class="setup-field"><label for="org-legal_name">Legal name</label><x-filament::input.wrapper :valid="! $errors->has('organization.legal_name')"><x-filament::input id="org-legal_name" type="text" wire:model="organization.legal_name" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-gst_number">GST number</label><x-filament::input.wrapper :valid="! $errors->has('organization.gst_number')"><x-filament::input id="org-gst_number" type="text" wire:model="organization.gst_number" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-registration_number">Registration number</label><x-filament::input.wrapper :valid="! $errors->has('organization.registration_number')"><x-filament::input id="org-registration_number" type="text" wire:model="organization.registration_number" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-website">Website</label><x-filament::input.wrapper :valid="! $errors->has('organization.website')"><x-filament::input id="org-website" type="url" wire:model="organization.website" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-contact_person">Contact person</label><x-filament::input.wrapper :valid="! $errors->has('organization.contact_person')"><x-filament::input id="org-contact_person" type="text" wire:model="organization.contact_person" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-contact_person_email">Contact person email</label><x-filament::input.wrapper :valid="! $errors->has('organization.contact_person_email')"><x-filament::input id="org-contact_person_email" type="email" wire:model="organization.contact_person_email" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-contact_person_phone">Contact person phone</label><x-filament::input.wrapper :valid="! $errors->has('organization.contact_person_phone')"><x-filament::input id="org-contact_person_phone" type="tel" wire:model="organization.contact_person_phone" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><x-filament::button type="submit" wire:loading.attr="disabled" size="lg">Save and continue</x-filament::button></div>
                    </form>
                @elseif ($step === 2)
                    @include('filament.pages.partials.onboarding-territory-import')
                    <a class="setup-option" href="{{ \App\Filament\Resources\Territories\TerritoryResource::getUrl('create', panel: 'admin') }}"><span><strong>Add manually</strong><small>Create a territory in the existing admin page, then return here.</small></span></a>
                    <p class="setup-hint"><a class="setup-review-link" href="{{ \App\Filament\Resources\Territories\TerritoryResource::getUrl('index', panel: 'admin') }}">Manage territories →</a></p>
                    <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><div><button type="button" class="setup-text-button" wire:click="skipTerritories">Set up later</button><x-filament::button wire:click="next" wire:loading.attr="disabled">Continue to employees</x-filament::button></div></div>
                @elseif ($step === 3)
                    <h2>Who’s joining your workspace?</h2><p>Add employees one at a time, or bring your whole team in with an Excel file.</p>
                    @if ($checklist['employee_count'] > 0)<div class="setup-success" role="status">✓ {{ $checklist['employee_count'] }} employees added. You can add more whenever you need.</div>@endif
                    <a class="setup-option" href="{{ \App\Filament\Pages\DataImport::getUrl(['module' => 'employees'], panel: 'admin') }}"><span class="setup-option-icon" aria-hidden="true">↑</span><span><strong>Import employees from Excel</strong><small>One template for employee details, positions, territory codes and reporting relationships.</small></span><span aria-hidden="true">→</span></a>
                    <a class="setup-option" href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl('create', panel: 'admin') }}"><span class="setup-option-icon" aria-hidden="true">＋</span><span><strong>Add an employee</strong><small>Enter a team member’s details yourself.</small></span><span aria-hidden="true">→</span></a>
                    <details class="setup-optional"><summary>Need to set up divisions first? (Optional)</summary><p>Create divisions and child organizational units before adding your team. Excel import can also create divisions for you.</p><a href="{{ \App\Filament\Resources\OrganizationalUnits\OrganizationalUnitResource::getUrl('index', panel: 'admin') }}">Set up organization structure →</a></details>
                    <p class="setup-hint">Use territory_codes in the employee template to assign territories to positions. Separate multiple codes with a semicolon. Blank keeps existing assignments. After adding employees, return to Setup Checklist to continue.</p>
                    <p class="setup-hint">{{ $checklist['unassigned_territory_count'] }} employees have no territory through their positions. <a class="setup-review-link" href="{{ \App\Filament\Resources\Positions\PositionResource::getUrl('index', panel: 'admin') }}">Review positions and territory assignments →</a></p>
                    <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><div><button type="button" class="setup-text-button" wire:click="skip" wire:loading.attr="disabled">Continue later</button><x-filament::button wire:click="next" wire:loading.attr="disabled" size="lg">Next</x-filament::button></div></div>
                @elseif ($step === 4)
                    <h2>Optional setup imports</h2>
                    <p>Add accounts, field routes, products and HR setup. You can complete any of these imports later.</p>
                    @include('filament.pages.partials.onboarding-account-import')
                    @if (\App\Filament\Pages\FieldTravelImport::canAccess())
                        @livewire(\App\Filament\Pages\FieldTravelImport::class, ['embedded' => true], key('onboarding-field-import'))
                    @else
                        <section class="setup-import-card" aria-label="Field route setup">
                            <div class="setup-card-heading"><span class="setup-card-number">2</span><div><h3>Set up field routes</h3><p>Import patches, account assignments and standard fares for your team's visits.</p></div></div>
                            <p class="setup-note">Import access requires permission to create patches or standard fares. You can skip this step and ask your administrator to complete it later.</p>
                        </section>
                    @endif
                    @livewire(\App\Filament\Pages\OnboardingImports::class, ['group' => 'products'], key('onboarding-product-import'))
                    @livewire(\App\Filament\Pages\OnboardingImports::class, ['group' => 'hr'], key('onboarding-hr-import'))
                    <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><div><button type="button" class="setup-text-button" wire:click="reviewFieldTravel" wire:loading.attr="disabled">Skip and complete later</button><x-filament::button wire:click="reviewFieldTravel" wire:loading.attr="disabled">Continue to approvals</x-filament::button></div></div>
                @elseif ($step === 5)
                    <h2>Does your team need approvals?</h2>
                    <p>We will create the settings and flows in your existing admin pages. L1 and L2 are human approval steps.</p>
                    <form wire:submit="reviewApprovals" class="setup-form">
                        @if ($this->approvalSetup()->hasExistingConfiguration() || $this->approvalAlreadyApplied())
                            <div class="setup-note">Approval configuration already exists. Continue to retain it. Use the links below to make changes.</div>
                        @else
                            <div class="setup-field"><label for="approval-required">Approval required?</label><x-filament::input.wrapper><x-filament::input.select id="approval-required" wire:model.live="approval.required"><option value="">Choose an option</option><option value="no">No approvals required</option><option value="yes">Yes, configure approvals</option></x-filament::input.select></x-filament::input.wrapper></div>
                            @if (($approval['required'] ?? '') === 'yes')
                                <p class="setup-hint">Choose modules with approval support.</p>
                                @foreach ($this->approvalSetup()->groups() as $group => $modules)
                                    <fieldset class="setup-note"><legend>{{ $group }}</legend>
                                        @foreach ($modules as $module => $label)
                                            <label style="display:block;margin:10px 0"><input type="checkbox" wire:model.live="approval.modules" value="{{ $module }}"> {{ $label }}</label>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                                <p class="setup-hint">HR Change Requests shares one flow for salary, employment, attendance, overtime, and comp-off changes.</p>
                                <h3>Common approval setup</h3>
                                @include('filament.pages.partials.onboarding-approval-routing', ['prefix' => 'approval.common'])
                                <p class="setup-hint">Territory-scoped approvers require a flow territory. The role must resolve to one active employee with a login, excluding you. L1 and L2 must resolve to different people. Request-time checks also prevent self-approval.</p>
                                @foreach (($approval['modules'] ?? []) as $module)
                                    @if (isset($this->approvalSetup()->moduleOptions()[$module]))
                                        <div class="setup-note" wire:key="approval-override-{{ $module }}">
                                            <label><input type="checkbox" wire:model.live="approval.overrides.{{ $module }}.custom"> Customize {{ $this->approvalSetup()->moduleOptions()[$module] }}</label>
                                            @if (data_get($approval, 'overrides.'.$module.'.custom'))
                                                @include('filament.pages.partials.onboarding-approval-routing', ['prefix' => 'approval.overrides.'.$module])
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            @endif
                        @endif
                        <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><div><button type="button" class="setup-text-button" wire:click="skip">Continue later</button><x-filament::button type="submit" wire:loading.attr="disabled">Review setup</x-filament::button></div></div>
                    </form>
                @else
                    <div class="setup-success-icon" aria-hidden="true">✓</div><h2>Your workspace is taking shape</h2><p>Check your team’s details and reporting managers before you finish.</p>
                    <dl class="setup-review"><div><dt>Company</dt><dd>{{ $organization['name'] }}</dd></div><div><dt>Company uses divisions</dt><dd>{{ $this->usesDivisions() ? 'Yes' : 'No' }}</dd></div><div><dt>Territories</dt><dd>{{ $checklist['territory_count'] ?: 'Set up later' }}</dd></div><div><dt>Employees without territory assignments</dt><dd>{{ $checklist['unassigned_territory_count'] }}</dd></div><div><dt>Employees</dt><dd>{{ $checklist['employee_count'] }} added</dd></div><div><dt>Accounts</dt><dd>{{ $checklist['account_count'] ?: 'Set up later' }}</dd></div><div><dt>Patches</dt><dd>{{ $checklist['patch_count'] ?: 'Set up later' }}</dd></div><div><dt>Standard fare routes</dt><dd>{{ $checklist['fare_count'] ?: 'Set up later' }}</dd></div><div><dt>Organization structure</dt><dd>{{ $checklist['unit_count'] ? $checklist['unit_count'].' units' : 'Set up later' }}</dd></div></dl>
                    <div class="setup-note">
                        <h3>Approval setup</h3>
                        @if (($approvalReview['required'] ?? '') === 'existing')
                            <p>Keep the existing approval settings and flows. Your admin changes are preserved.</p>
                        @elseif (($approvalReview['required'] ?? '') === 'no')
                            <p>No approvals required. Approval modules will remain disabled.</p>
                        @else
                            @foreach (($approvalReview['flows'] ?? []) as $flow)
                                <p><strong>{{ $flow['label'] }}</strong> — {{ $flow['territory'] }}</p>
                                <ul>@foreach ($flow['steps'] as $route)<li>L{{ $loop->iteration }}: {{ $route['role'] }} — {{ $route['approver'] }} ({{ ['all' => 'Any territory', 'self' => 'Selected territory', 'children' => 'Selected territory and children'][$route['territory_scope']] }})</li>@endforeach</ul>
                            @endforeach
                            <p>These flows will become active when you finish setup.</p>
                        @endif
                    </div>
                    <a class="setup-review-link" href="{{ \App\Filament\Resources\Positions\PositionResource::getUrl('index', panel: 'admin') }}">Review positions and reporting →</a>
                    <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><x-filament::button wire:click="finish" wire:loading.attr="disabled" size="lg">Open my workspace</x-filament::button></div>
                @endif
                @if ($step >= 5)
                    <p class="setup-hint"><a class="setup-review-link" href="{{ \App\Filament\Resources\ApprovalSettings\ApprovalSettingResource::getUrl('index', panel: 'admin') }}">Approval Settings</a> · <a class="setup-review-link" href="{{ \App\Filament\Resources\ApprovalFlows\ApprovalFlowResource::getUrl('index', panel: 'admin') }}">Approval Flows</a> · <a class="setup-review-link" href="{{ \App\Filament\Resources\ApprovalDelegations\ApprovalDelegationResource::getUrl('index', panel: 'admin') }}">Approval Delegations</a></p>
                @endif
                @if ($checklist['company'] && ($detailsUrl = $this->organizationDetailsUrl()))
                    <p class="setup-hint"><a class="setup-review-link" href="{{ $detailsUrl }}">Update full organization details and logo &rarr;</a><br>Manage your logo, legal information, contact details and other company settings.</p>
                @else
                    <p class="setup-hint">Add your logo and save company information in the Company information step.</p>
                @endif
            </section>
        </div>
    @endif
    <footer class="setup-footer"><span>Continue at your own pace.</span><form method="post" action="{{ route('filament.admin.auth.logout') }}">@csrf<button type="submit" class="setup-text-button">Sign out</button></form></footer>
</div>
