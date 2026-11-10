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
            $directDcr = $workflow === 'direct_dcr';
            $titles = ['A great place to start.', 'Tell us about your company.', 'Choose your working method.', 'Bring your team together.', 'Prepare your customers.', 'Choose how approvals work.', 'Launch your first team.'];
            $descriptions = ['Prepare one HQ first, then bring the rest of your team onboard.', 'These details help your team recognize your workspace.', 'Choose how MRs plan visits. DCR approvals are configured separately.', 'Add employees, login access and HQ assignments.', 'Import customers and assign them to your MRs.', 'Confirm reporting approvals independently of visit planning.', 'Select the MRs to launch. Other teams can remain in preparation.'];
            if ($directDcr) {
                $descriptions[0] = 'Prepare your first team, then bring the rest onboard.';
                $descriptions[3] = 'Add employees and login access. HQ assignment is optional for Direct DCR.';
            }
        @endphp
        <div class="setup-wizard">
            <aside class="setup-intro">
                <div class="setup-brand">@include('filament.components.tenant-brand')</div>
                <p class="setup-eyebrow">YOUR WORKSPACE, YOUR TEAM</p>
                <h1>{{ $titles[$step] }}</h1>
                <p class="setup-description">{{ $descriptions[$step] }}</p>
                <ol class="setup-steps" aria-label="Setup progress">
                    @foreach (['Company information', 'Working method', $directDcr ? 'Team' : 'Team and HQs', 'Customers', 'Reporting rules', 'Review and launch'] as $label)
                        <li class="{{ $step === $loop->iteration ? 'is-current' : '' }}" @if ($step === $loop->iteration) aria-current="step" @endif><span class="setup-step-number" aria-hidden="true">{{ $step > $loop->iteration ? '✓' : $loop->iteration }}</span>{{ $label }}</li>
                    @endforeach
                </ol>
                <p class="setup-account">Signed in as<br>{{ auth()->user()->email }}</p>
            </aside>
            <section class="setup-content" aria-label="Current setup step" wire:key="setup-step-{{ $step }}">
                <p class="setup-step-label">{{ $step === 0 ? 'WELCOME' : 'STEP '.$step.' OF 6' }}</p>
                <p class="setup-hint">Text changes are saved when you leave a field. Drafts are private to your login. Uploads must be saved separately.</p>
                <p wire:loading role="status">Saving changes…</p>
                @if ($draftSaved)<p wire:loading.remove wire:dirty.remove role="status">Draft saved. You can return after signing in again.</p>@endif
                <p wire:dirty role="status">Unsaved changes.</p>
                @if ($step <= 2)<button type="button" class="setup-text-button" wire:click="skip" wire:loading.attr="disabled">Save and continue later</button>@endif
                @if ($errors->any())<div class="setup-error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                @if ($step === 0)
                    <div class="setup-welcome-art" aria-hidden="true"><span>✦</span><span>✓</span><span>＋</span></div>
                    <h2>Let’s set things up</h2>
                    <p>Start with your company details. Then add your employees and review how your team is organized.</p>
                    <div class="setup-note">You can save an incomplete draft and continue later.</div>
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
                            <div class="setup-field"><label for="org-{{ $field }}">{{ $label }}</label><x-filament::input.wrapper :valid="! $errors->has('organization.'.$field)"><x-filament::input id="org-{{ $field }}" type="{{ $field === 'email' ? 'email' : ($field === 'phone' ? 'tel' : 'text') }}" wire:model.live.blur="organization.{{ $field }}" required maxlength="{{ $field === 'phone' ? 30 : 255 }}" /></x-filament::input.wrapper></div>
                        @endforeach
                        <div class="setup-field"><label for="org-date-format">Date display format</label><x-filament::input.wrapper :valid="! $errors->has('organization.date_format')"><x-filament::input.select id="org-date-format" wire:model.live.blur="organization.date_format" required>@foreach (\App\Services\DateFormatService::FORMATS as $format => $label)<option value="{{ $format }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper><p class="setup-hint">Used across your workspace, reports and field app. You can change it later in Organization Profile.</p>@error('organization.date_format')<p role="alert">{{ $message }}</p>@enderror</div>
                        <div class="setup-field"><label for="org-timezone">Company timezone</label><x-filament::input.wrapper :valid="! $errors->has('organization.timezone')"><x-filament::input.select id="org-timezone" wire:model.live="organization.timezone" required>@foreach (\DateTimeZone::listIdentifiers() as $timezone)<option value="{{ $timezone }}">{{ $timezone === 'Asia/Kolkata' ? 'India — Asia/Kolkata (IST)' : $timezone }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper><p class="setup-hint">Confirm your company profile timezone. India is preselected for new companies.</p>@error('organization.timezone')<p role="alert">{{ $message }}</p>@enderror</div>
                        <div class="setup-field"><label for="uses-divisions">Does your company use divisions?</label><x-filament::input.wrapper><x-filament::input.select id="uses-divisions" wire:model.live.blur="organization.uses_divisions"><option value="1">Yes, use divisions</option><option value="0">No divisions</option></x-filament::input.select></x-filament::input.wrapper><p class="setup-hint">No makes division links optional for territories. Existing divisions and links are kept.</p></div>
                        <p class="setup-hint">Additional company details (optional)</p>
                        <div class="setup-field"><label for="org-legal_name">Legal name</label><x-filament::input.wrapper :valid="! $errors->has('organization.legal_name')"><x-filament::input id="org-legal_name" type="text" wire:model.live.blur="organization.legal_name" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-gst_number">GST number</label><x-filament::input.wrapper :valid="! $errors->has('organization.gst_number')"><x-filament::input id="org-gst_number" type="text" wire:model.live.blur="organization.gst_number" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-registration_number">Registration number</label><x-filament::input.wrapper :valid="! $errors->has('organization.registration_number')"><x-filament::input id="org-registration_number" type="text" wire:model.live.blur="organization.registration_number" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-website">Website</label><x-filament::input.wrapper :valid="! $errors->has('organization.website')"><x-filament::input id="org-website" type="url" wire:model.live.blur="organization.website" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-contact_person">Contact person</label><x-filament::input.wrapper :valid="! $errors->has('organization.contact_person')"><x-filament::input id="org-contact_person" type="text" wire:model.live.blur="organization.contact_person" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-contact_person_email">Contact person email</label><x-filament::input.wrapper :valid="! $errors->has('organization.contact_person_email')"><x-filament::input id="org-contact_person_email" type="email" wire:model.live.blur="organization.contact_person_email" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-field"><label for="org-contact_person_phone">Contact person phone</label><x-filament::input.wrapper :valid="! $errors->has('organization.contact_person_phone')"><x-filament::input id="org-contact_person_phone" type="tel" wire:model.live.blur="organization.contact_person_phone" maxlength="255" /></x-filament::input.wrapper></div>
                        <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><x-filament::button type="submit" wire:loading.attr="disabled" size="lg">Save and continue</x-filament::button></div>
                    </form>
                @elseif ($step === 2)
                    <h2>How does your field team plan doctor visits?</h2>
                    <form wire:submit="saveWorkflow" class="setup-form">
                        @foreach (\App\Services\CompanyLaunchService::workflows() as $value => $option)
                            <label class="setup-option"><input type="radio" wire:model.live="workflow" value="{{ $value }}"><span><strong>{{ $option['label'] }}</strong><small>{{ $option['description'] }}</small></span></label>
                        @endforeach
                        @if ($workflow)
                            <div class="setup-note">{{ $workflow === 'formal_mtp' ? 'MRs will open their approved monthly plan. Territories and patches are needed.' : 'MRs can start visits from their assigned customers. Detailed territories and patches are optional.' }} DCR approval choices are made in Reporting rules. This proposal takes effect only when you launch a team.</div>
                        @endif
                        <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><x-filament::button type="submit" wire:loading.attr="disabled">Save and continue</x-filament::button></div>
                    </form>
                @elseif ($step === 3)
                    <h2>Who’s joining your workspace?</h2><p>Add employees one at a time, or bring your whole team in with an Excel file.</p>
                    @livewire(\App\Filament\Pages\DataImport::class, ['embedded' => true], key('onboarding-employee-import'))
                    <a class="setup-option" href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl('create', panel: 'admin') }}"><span class="setup-option-icon" aria-hidden="true">＋</span><span><strong>Add an employee</strong><small>Enter a team member’s details yourself.</small></span><span aria-hidden="true">→</span></a>
                    <details class="setup-optional"><summary>Need to set up divisions first? (Optional)</summary><p>Create divisions and child organizational units before adding your team. Excel import can also create divisions for you.</p><a href="{{ \App\Filament\Resources\OrganizationalUnits\OrganizationalUnitResource::getUrl('index', panel: 'admin') }}">Set up organization structure →</a></details>
                    <details class="setup-optional"><summary>{{ $directDcr ? 'Import HQ locations (optional)' : 'Import HQ locations' }}</summary>
                        @livewire(\App\Filament\Pages\OnboardingImports::class, ['group' => 'hqs'], key('onboarding-hq-import'))
                    </details>
                    <a class="setup-option" href="{{ \App\Filament\Pages\FieldTeamOnboarding::getUrl(panel: 'admin') }}">{{ $directDcr ? 'Assign customers' : 'Assign HQs and customers' }} →</a>
                    @if ($directDcr)<p class="setup-hint">HQ, territories and patches are optional for Direct DCR. Assign customers to each MR before launch so they can report visits.</p>@endif
                    @if ($workflow === 'formal_mtp')
                        @include('filament.pages.partials.onboarding-territory-import')
                        <p class="setup-hint">{{ $checklist['unassigned_territory_count'] }} employees have no territory through their positions. <a href="{{ \App\Filament\Resources\Positions\PositionResource::getUrl('index', panel: 'admin') }}">Review positions and territory assignments →</a></p>
                    @endif
                    <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><div><button type="button" class="setup-text-button" wire:click="skip" wire:loading.attr="disabled">Save and continue later</button><x-filament::button wire:click="next" wire:loading.attr="disabled" size="lg">Next</x-filament::button></div></div>
                @elseif ($step === 4)
                    <h2>Customers and assignments</h2>
                    <p>{{ $directDcr ? 'Prepare the customers for your first team.' : 'Prepare the customers for your first HQ.' }} You can import the rest later.</p>
                    @include('filament.pages.partials.onboarding-account-import')
                    <a class="setup-option" href="{{ \App\Filament\Pages\FieldTeamOnboarding::getUrl(panel: 'admin') }}">Assign customers to MRs and review monthly call targets →</a>
                    <details class="setup-optional" @if ($workflow === 'formal_mtp') open @endif><summary>{{ $workflow === 'formal_mtp' ? 'Prepare patches for monthly planning' : 'Optional field routes and additional modules' }}</summary>
                    @if (\App\Filament\Pages\FieldTravelImport::canAccess())
                        @livewire(\App\Filament\Pages\FieldTravelImport::class, ['embedded' => true], key('onboarding-field-import'))
                    @else
                        <section class="setup-import-card" aria-label="Field route setup">
                            <div class="setup-card-heading"><span class="setup-card-number">1</span><div><h3>Set up field routes</h3><p>Import Patches and Standard Fares first with Patch Customers empty. Add customer assignments after importing accounts.</p></div></div>
                            <p class="setup-note">Import access requires permission to create patches or standard fares. You can skip this step and ask your administrator to complete it later.</p>
                        </section>
                    @endif
                    @livewire(\App\Filament\Pages\OnboardingImports::class, ['group' => 'products'], key('onboarding-product-import'))
                    @livewire(\App\Filament\Pages\OnboardingImports::class, ['group' => 'hr'], key('onboarding-hr-import'))
                    </details>
                    <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><div><button type="button" class="setup-text-button" wire:click="reviewFieldTravel" wire:loading.attr="disabled">Skip and complete later</button><x-filament::button wire:click="reviewFieldTravel" wire:loading.attr="disabled">Continue to approvals</x-filament::button></div></div>
                @elseif ($step === 5)
                    <h2>Does your team need approvals?</h2>
                    <p>We will create the settings and flows in your existing admin pages. L1 and L2 are human approval steps.</p>
                    <form wire:submit="reviewApprovals" class="setup-form">
                        @if ($this->approvalSetup()->hasExistingConfiguration() || $this->approvalAlreadyApplied())
                            <div class="setup-note">Approval configuration already exists. Continue to retain it. Use the links below to make changes.</div>
                        @else
                            <div class="setup-field"><label for="approval-required">Approval required?</label><x-filament::input.wrapper><x-filament::input.select id="approval-required" wire:model.live="approval.required"><option value="">Choose an option</option><option value="no">No approvals required</option><option value="yes">Yes, configure approvals</option></x-filament::input.select></x-filament::input.wrapper></div>
                            @if (($approval['required'] ?? '') === 'no')
                                <p class="setup-hint">Approval statuses will be hidden in the admin workspace and field app. You can enable approvals later in Approval Settings.</p>
                            @endif
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
                                <p class="setup-hint">Choose a named approver when several employees share a role. Territory-scoped approvers require a flow territory. L1 and L2 must be different people. Requesters cannot approve their own requests.</p>
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
                        <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><div><button type="button" class="setup-text-button" wire:click="skip">Save and continue later</button><x-filament::button type="submit" wire:loading.attr="disabled">Review setup</x-filament::button></div></div>
                    </form>
                @else
                    <h2>Choose the team to launch</h2><p>Selected MRs are recorded as launched. Missing requirements are checked again before launch. Planning and approval settings apply to the whole company; existing permissions continue to control access.</p>
                    @php($readiness = $this->launchReadiness())
                    <div class="setup-note">{{ $readiness['ready_count'] }} MRs pass the current setup checks. {{ $readiness['launched_count'] }} already launched.
                        @if ($readiness['approval_check_pending'])<p>Approval routes will be applied and checked for each selected MR at launch.</p>@endif
                        @foreach ($readiness['blockers'] as $blocker)<p>{{ $blocker }}</p>@endforeach
                    </div>
                    @foreach ($readiness['members'] as $member)
                        <div class="setup-note" wire:key="launch-member-{{ $member['employee_id'] }}">
                            <label><input type="checkbox" wire:model="launchMembers" value="{{ $member['employee_id'] }}"> <strong>{{ $member['name'] }}</strong> — {{ $member['hq'] ?? ($directDcr ? 'HQ optional' : 'HQ not assigned') }} · {{ $member['customers'] }} customers {{ $member['launched'] ? '· Already launched' : '' }}</label>
                            @foreach ($member['issue_details'] as $code => $issue)
                                <p>{{ $issue }}
                                    @if ($action = $this->readinessAction($code, $member))
                                        <a class="setup-review-link" href="{{ $action['url'] }}">{{ $action['label'] }} →</a>
                                    @endif
                                </p>
                            @endforeach
                        </div>
                    @endforeach
                    <p class="setup-hint"><a href="{{ \App\Filament\Pages\FieldTeamOnboarding::getUrl(panel: 'admin') }}">{{ $directDcr ? 'Review customer assignments' : 'Fix HQ/customer assignments' }}</a> · <a href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl('index', panel: 'admin') }}">Review employees and login access</a></p>
                    <details class="setup-optional">
                        <summary>Team launch history</summary>
                        @forelse ($this->launchHistory() as $event)
                            <div class="setup-note">
                                <p><strong>{{ $event['actor_name'] }}</strong> · {{ $event['launched_at'] }} · {{ \App\Services\CompanyLaunchService::workflows()[$event['mode']]['label'] }} · Policy revision {{ $event['policy_version'] }}</p>
                                <ul>@foreach ($event['members'] as $launchedMember)<li>{{ $launchedMember['name'] }} — {{ $launchedMember['hq'] ?? 'HQ not recorded' }}</li>@endforeach</ul>
                            </div>
                        @empty
                            <p>No team launches recorded yet.</p>
                        @endforelse
                    </details>
                    <dl class="setup-review"><div><dt>Company</dt><dd>{{ $organization['name'] }}</dd></div><div><dt>Company uses divisions</dt><dd>{{ $this->usesDivisions() ? 'Yes' : 'No' }}</dd></div><div><dt>Territories</dt><dd>{{ $checklist['territory_count'] ?: 'Set up later' }}</dd></div><div><dt>Employees without territory assignments</dt><dd>{{ $checklist['unassigned_territory_count'] }}</dd></div><div><dt>Employees</dt><dd>{{ $checklist['employee_count'] }} added</dd></div><div><dt>Accounts</dt><dd>{{ $checklist['account_count'] ?: 'Set up later' }}</dd></div><div><dt>Patches</dt><dd>{{ $checklist['patch_count'] ?: 'Set up later' }}</dd></div><div><dt>Standard fare routes</dt><dd>{{ $checklist['fare_count'] ?: 'Set up later' }}</dd></div><div><dt>Organization structure</dt><dd>{{ $checklist['unit_count'] ? $checklist['unit_count'].' units' : 'Set up later' }}</dd></div></dl>
                    <div class="setup-note">
                        <h3>Approval setup</h3>
                        @if (($approvalReview['required'] ?? '') === 'existing')
                            <p>Keep the existing approval settings and flows. Your admin changes are preserved.</p>
                        @elseif (($approvalReview['required'] ?? '') === 'no')
                            <p>No approvals required. Approval statuses are hidden in the admin workspace and field app. You can enable approvals later in Approval Settings.</p>
                        @else
                            @foreach (($approvalReview['flows'] ?? []) as $flow)
                                <p><strong>{{ $flow['label'] }}</strong> — {{ $flow['territory'] }}</p>
                                <ul>@foreach ($flow['steps'] as $route)<li>L{{ $loop->iteration }}: {{ $route['role'] }} — {{ $route['approver'] }} ({{ ['all' => 'Any territory', 'self' => 'Selected territory', 'children' => 'Selected territory and children'][$route['territory_scope']] }})</li>@endforeach</ul>
                            @endforeach
                            <p>These flows will become active when you finish setup.</p>
                        @endif
                    </div>
                    <a class="setup-review-link" href="{{ \App\Filament\Resources\Positions\PositionResource::getUrl('index', panel: 'admin') }}">Review positions and reporting →</a>
                    <div class="setup-actions"><button type="button" class="setup-text-button" wire:click="back">Back</button><button type="button" class="setup-text-button" wire:click="skip">Save and continue later</button><x-filament::button wire:click="finish" wire:loading.attr="disabled" size="lg">Launch selected team</x-filament::button></div>
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
