<div class="setup-shell">
    <style>
        .setup-shell{color:#202124}.setup-wizard{display:grid;grid-template-columns:0.9fr 1.1fr;gap:56px;padding:24px 12px;min-height:520px}.setup-brand{font-size:20px;font-weight:600;margin-bottom:48px}.setup-brand>span{display:inline-grid;place-items:center;background:#e8f0fe;color:#1967d2;border-radius:12px;width:38px;height:38px;margin-right:8px}.setup-eyebrow,.setup-step-label{font-size:11px;letter-spacing:1.4px;font-weight:600;color:#5f6368;margin-bottom:20px}.setup-intro h1{font-size:36px;line-height:1.2;font-weight:400;letter-spacing:-1px;margin:0 0 20px}.setup-description,.setup-content>p{font-size:15px;line-height:1.7;color:#5f6368}.setup-steps{list-style:none;padding:0;margin:32px 0}.setup-steps li{display:flex;align-items:center;gap:12px;color:#5f6368;margin:16px 0;font-size:14px}.setup-step-number{border:1px solid #dadce0;border-radius:50%;width:28px;height:28px;display:grid;place-items:center;font-size:12px}.setup-steps .is-current{color:#1967d2;font-weight:600}.is-current .setup-step-number{background:#e8f0fe;border-color:#e8f0fe}.setup-account{font-size:12px;line-height:1.7;color:#5f6368;overflow-wrap:anywhere}.setup-content{padding-top:10px;min-width:0}.setup-content h2{font-size:24px;font-weight:400;line-height:1.3;margin:0 0 12px}.setup-content .setup-step-label{font-size:11px;margin-bottom:22px}.setup-form{margin-top:28px}.setup-field{margin-bottom:22px}.setup-field label{display:block;font-size:13px;margin-bottom:8px}.setup-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:36px;flex-wrap:wrap}.setup-actions>div{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.setup-text-button,.setup-review-link,.setup-optional a{color:#1967d2;font-size:14px;font-weight:500;cursor:pointer}.setup-text-button{padding:10px 0}.setup-text-button:focus-visible,.setup-option:focus-visible{outline:2px solid #1967d2;outline-offset:4px}.setup-option{display:flex;align-items:center;gap:14px;border:1px solid #dadce0;border-radius:12px;padding:20px;margin-top:18px;color:inherit;text-decoration:none}.setup-option:hover{border-color:#1967d2;background:#f8faff}.setup-option strong{font-size:14px;font-weight:600;display:block}.setup-option small{font-size:12px;line-height:1.6;color:#5f6368;display:block;margin-top:5px}.setup-option-icon{color:#1967d2;font-size:24px}.setup-option>span:nth-child(2){flex:1}.setup-optional{margin-top:24px;font-size:13px;line-height:1.7}.setup-optional summary{cursor:pointer}.setup-optional p{margin:10px 0}.setup-hint{font-size:12px!important;margin-top:20px}.setup-note{background:#f8f9fa;border-radius:12px;padding:18px;font-size:13px;line-height:1.7;margin-top:24px;color:#5f6368}.setup-error{color:#b3261e;background:#fce8e6;padding:14px;border-radius:8px;font-size:13px;margin-bottom:20px}.setup-success{color:#137333;background:#e6f4ea;border-radius:8px;padding:12px;font-size:13px;margin-top:18px}.setup-success-icon{width:52px;height:52px;background:#e6f4ea;color:#137333;border-radius:50%;display:grid;place-items:center;font-size:26px;margin-bottom:20px}.setup-review{margin:24px 0}.setup-review>div{display:flex;justify-content:space-between;gap:16px;padding:16px 0;border-bottom:1px solid #e8eaed;font-size:14px}.setup-review dt{color:#5f6368}.setup-review dd{font-weight:500;text-align:right;overflow-wrap:anywhere}.setup-footer{border-top:1px solid #e8eaed;margin-top:20px;padding-top:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;color:#5f6368;font-size:12px}.setup-welcome-art{display:flex;align-items:center;gap:14px;margin:22px 0 34px}.setup-welcome-art span{display:grid;place-items:center;width:62px;height:70px;border-radius:16px;background:#e8f0fe;color:#1967d2;font-size:30px;transform:rotate(-8deg)}.setup-welcome-art span:nth-child(2){background:#e6f4ea;color:#137333;transform:rotate(5deg)}.setup-welcome-art span:nth-child(3){background:#fef7e0;color:#b06000;transform:rotate(-4deg)}
        .dark .setup-shell{color:#e8eaed}.dark .setup-description,.dark .setup-content>p,.dark .setup-steps li,.dark .setup-account,.dark .setup-option small,.dark .setup-review dt,.dark .setup-footer{color:#bdc1c6}.dark .setup-option,.dark .setup-review>div,.dark .setup-footer{border-color:#5f6368}.dark .setup-note,.dark .setup-option:hover{background:#292a2d;color:#bdc1c6}.dark .setup-text-button,.dark .setup-review-link,.dark .setup-optional a,.dark .setup-steps .is-current{color:#8ab4f8}
        @media(max-width:700px){.setup-wizard{grid-template-columns:1fr;gap:24px;padding:4px;min-height:0}.setup-brand{margin-bottom:24px}.setup-intro h1{font-size:28px}.setup-description,.setup-account,.setup-eyebrow{display:none}.setup-steps{display:flex;gap:12px;margin:20px 0 0;flex-wrap:wrap}.setup-steps li{font-size:11px;margin:0;gap:6px}.setup-step-number{width:23px;height:23px;flex-shrink:0}.setup-content h2{font-size:22px}.setup-option{padding:16px}.setup-content{padding-top:0}.setup-actions{margin-top:26px}}
    </style>
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
            $titles = ['A great place to start.', 'Tell us about your company.', 'Bring your team together.', 'Choose how approvals work.', 'Ready for your first day.'];
            $descriptions = ['A few simple steps to make this workspace yours.', 'These details help your team recognize your workspace.', 'Add employees and give everyone a place in your organization.', 'Set approval levels and approvers for the modules your team uses.', 'Review your setup, then open your workspace.'];
        @endphp
        <div class="setup-wizard">
            <aside class="setup-intro">
                <div class="setup-brand">@include('filament.components.tenant-brand')</div>
                <p class="setup-eyebrow">YOUR WORKSPACE, YOUR TEAM</p>
                <h1>{{ $titles[$step] }}</h1>
                <p class="setup-description">{{ $descriptions[$step] }}</p>
                <ol class="setup-steps" aria-label="Setup progress">
                    @foreach (['Company information', 'Add employees', 'Approvals', 'Review & finish'] as $label)
                        <li class="{{ $step === $loop->iteration ? 'is-current' : '' }}" @if ($step === $loop->iteration) aria-current="step" @endif><span class="setup-step-number" aria-hidden="true">{{ $step > $loop->iteration ? '✓' : $loop->iteration }}</span>{{ $label }}</li>
                    @endforeach
                </ol>
                <p class="setup-account">Signed in as<br>{{ auth()->user()->email }}</p>
            </aside>
            <section class="setup-content" aria-label="Current setup step" wire:key="setup-step-{{ $step }}">
                <p class="setup-step-label">{{ $step === 0 ? 'WELCOME' : 'STEP '.$step.' OF 4' }}</p>
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
                        @foreach (['name' => 'Company name', 'email' => 'Company email', 'phone' => 'Phone number'] as $field => $label)
                            <div class="setup-field"><label for="org-{{ $field }}">{{ $label }}</label><x-filament::input.wrapper :valid="! $errors->has('organization.'.$field)"><x-filament::input id="org-{{ $field }}" type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" wire:model="organization.{{ $field }}" required maxlength="{{ $field === 'phone' ? 30 : 255 }}" /></x-filament::input.wrapper></div>
                        @endforeach
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
                    <h2>Who’s joining your workspace?</h2><p>Add employees one at a time, or bring your whole team in with an Excel file.</p>
                    @if ($checklist['employee_count'] > 0)<div class="setup-success" role="status">✓ {{ $checklist['employee_count'] }} employees added. You can add more whenever you need.</div>@endif
                    <a class="setup-option" href="{{ \App\Filament\Pages\DataImport::getUrl(['module' => 'employees'], panel: 'admin') }}"><span class="setup-option-icon" aria-hidden="true">↑</span><span><strong>Import employees from Excel</strong><small>One template for employee details, positions and reporting relationships.</small></span><span aria-hidden="true">→</span></a>
                    <a class="setup-option" href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl('create', panel: 'admin') }}"><span class="setup-option-icon" aria-hidden="true">＋</span><span><strong>Add an employee</strong><small>Enter a team member’s details yourself.</small></span><span aria-hidden="true">→</span></a>
                    <details class="setup-optional"><summary>Need to set up divisions first? (Optional)</summary><p>Create divisions and child organizational units before adding your team. Excel import can also create divisions for you.</p><a href="{{ \App\Filament\Resources\OrganizationalUnits\OrganizationalUnitResource::getUrl('index', panel: 'admin') }}">Set up organization structure →</a></details>
                    <p class="setup-hint">After adding employees, return to Setup Checklist to continue.</p>
                    <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><div><button type="button" class="setup-text-button" wire:click="skip" wire:loading.attr="disabled">Continue later</button><x-filament::button wire:click="next" wire:loading.attr="disabled" size="lg">Next</x-filament::button></div></div>
                @elseif ($step === 3)
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
                    <dl class="setup-review"><div><dt>Company</dt><dd>{{ $organization['name'] }}</dd></div><div><dt>Employees</dt><dd>{{ $checklist['employee_count'] }} added</dd></div><div><dt>Organization structure</dt><dd>{{ $checklist['unit_count'] ? $checklist['unit_count'].' units' : 'Set up later' }}</dd></div></dl>
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
                @if ($step >= 3)
                    <p class="setup-hint"><a class="setup-review-link" href="{{ \App\Filament\Resources\ApprovalSettings\ApprovalSettingResource::getUrl('index', panel: 'admin') }}">Approval Settings</a> · <a class="setup-review-link" href="{{ \App\Filament\Resources\ApprovalFlows\ApprovalFlowResource::getUrl('index', panel: 'admin') }}">Approval Flows</a> · <a class="setup-review-link" href="{{ \App\Filament\Resources\ApprovalDelegations\ApprovalDelegationResource::getUrl('index', panel: 'admin') }}">Approval Delegations</a></p>
                @endif
                @if ($checklist['company'] && ($detailsUrl = $this->organizationDetailsUrl()))
                    <p class="setup-hint"><a class="setup-review-link" href="{{ $detailsUrl }}">Update full organization details and logo &rarr;</a><br>Manage your logo, legal information, contact details and other company settings.</p>
                @else
                    <p class="setup-hint">Save company information to open the full organization profile and upload your logo.</p>
                @endif
            </section>
        </div>
    @endif
    <footer class="setup-footer"><span>Continue at your own pace.</span><form method="post" action="{{ route('filament.admin.auth.logout') }}">@csrf<button type="submit" class="setup-text-button">Sign out</button></form></footer>
</div>
