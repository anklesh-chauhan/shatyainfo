@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(in_array($tenant->onboarding_status, ['saved', 'applied'], true))
    <p>{{ $tenant->onboarding_status === 'applied' ? 'Your onboarding information has been applied.' : 'Your information is saved securely and will be applied when your database is ready.' }}</p>
    <p>You can close this page. Setup continues in the background.</p>
@else
    @if($tenant->onboarding_status === 'failed')<p role="alert">Your information could not be applied. Review and correct it below, or finish in Company Setup after signing in.</p>@endif
    <form id="onboarding-form" method="post" action="{{ $onboardingUrl }}" enctype="multipart/form-data" class="panel">
        @csrf
        <section id="organization-step">
            <h2>1. Organization information</h2>
            @foreach(['name' => 'Organization name', 'email' => 'Organization email', 'phone' => 'Phone number'] as $key => $label)
                <div class="field"><label for="org-{{ $key }}">{{ $label }} *</label>
                    <input id="org-{{ $key }}" name="organization[{{ $key }}]" type="{{ $key === 'email' ? 'email' : 'text' }}" value="{{ old('organization.'.$key, $tenant->onboarding_data['organization'][$key] ?? ($key === 'name' ? $tenant->name : ($key === 'email' ? ($tenant->provisioning_owner['email'] ?? '') : ''))) }}" maxlength="{{ $key === 'phone' ? 30 : 255 }}" required>
                </div>
            @endforeach
            <button type="button" class="btn btn-primary" id="next-step">Continue to employees</button>
        </section>
        <section id="employee-step" hidden>
            <h2>2. Employee information</h2>
            <p>Enter rows or upload a CSV, up to 500 employees. Email is optional. A CSV replaces the entered rows. Login invitations are not sent.</p>
            <p><a class="btn" href="{{ $templateUrl }}">Download CSV template</a></p>
            <div class="field"><label for="employee-csv">Employee CSV</label><input id="employee-csv" name="employee_csv" type="file" accept=".csv,.txt"></div>
            <div style="overflow-x:auto;"><table style="width:100%;text-align:left;min-width:720px;"><thead><tr><th>Employee code *</th><th>First name *</th><th>Last name *</th><th>Email</th><th>Mobile *</th><th></th></tr></thead><tbody id="employee-rows">
                @foreach(old('employees', $tenant->onboarding_data['employees'] ?? [array_fill_keys(\App\Services\CompanyOnboardingService::EMPLOYEE_COLUMNS, '')]) as $index => $row)
                    <tr>@foreach(\App\Services\CompanyOnboardingService::EMPLOYEE_COLUMNS as $field)<td><input aria-label="{{ str_replace('_', ' ', $field) }}" name="employees[{{ $index }}][{{ $field }}]" value="{{ $row[$field] ?? '' }}"></td>@endforeach<td><button type="button" class="remove-row">Remove</button></td></tr>
                @endforeach
            </tbody></table></div>
            <div class="actions" style="margin-top:16px;"><button type="button" class="btn" id="add-row">Add row</button><button type="button" class="btn" id="back-step">Back</button><button type="submit" class="btn btn-primary">Save information</button></div>
            <p>You can save without employees and add them later.</p>
        </section>
        <p><button type="submit" name="skip" value="1" class="btn" formnovalidate>Skip and finish later</button></p>
    </form>
    <template id="employee-row-template"><tr>
        @foreach(\App\Services\CompanyOnboardingService::EMPLOYEE_COLUMNS as $field)<td><input aria-label="{{ str_replace('_', ' ', $field) }}" name="employees[__INDEX__][{{ $field }}]"></td>@endforeach
        <td><button type="button" class="remove-row">Remove</button></td>
    </tr></template>
@endif
