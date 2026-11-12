<section class="setup-import-card" aria-label="Account master import">
    @php($directAccountImport = app(\App\Services\DataImport\AccountImportTemplate::class)->usesDirectDcr())
    <div class="setup-card-heading"><span class="setup-card-number">↑</span><div><h3>Import account masters</h3><p>Import customers and assign them to MRs.</p></div></div>
    <div class="setup-card-stats"><span>{{ $checklist['account_count'] }} accounts saved</span></div>
    <x-filament::button color="gray" wire:click="downloadAccountTemplate" wire:loading.attr="disabled">Download Excel template</x-filament::button>
    <details class="setup-optional">
        <summary>How to prepare your customer file</summary>
        <p>Fill the Accounts sheet. Required columns: external_reference, name, account_type and owner_email.</p>
        <p>Keep external_reference unchanged when updating a customer. Blank optional cells keep existing values. Leave account_code blank to generate it.</p>
        <p>{{ $directAccountImport ? 'Direct DCR does not require territories or patches.' : 'Create patches before using patch codes. Use existing city names for addresses.' }}</p>
        <p>Use the References sheet for company values. Preview the file, fix any errors, then select Confirm import.</p>
    </details>
    @if (in_array($accountImportStatus['status'] ?? null, ['queued', 'running'], true))
        <div class="setup-note" wire:poll.3s="refreshAccountImport" role="status" aria-live="polite">
            <h4>{{ $accountImportStatus['status'] === 'queued' ? 'Account import queued' : 'Importing account masters' }}</h4>
            <p>{{ $accountImportStatus['message'] }}</p>
            <progress max="{{ $accountImportStatus['total'] }}" value="{{ $accountImportStatus['processed'] }}" aria-label="Account import progress"></progress>
            <p>{{ $accountImportStatus['processed'] }} of {{ $accountImportStatus['total'] }} accounts processed. Records are saved when the whole import completes.</p>
            <p>You can leave this page and return. The import continues in the background.</p>
        </div>
    @else
        @if (($accountImportStatus['status'] ?? null) === 'failed')
            <p class="setup-error" role="alert">Account import failed. {{ $accountImportStatus['message'] }}</p>
        @endif
        @include('filament.pages.partials.onboarding-import-upload', ['previewAction' => 'previewAccounts', 'fileProperty' => 'accountFile', 'inputId' => 'account-file', 'accept' => '.xlsx,.csv', 'fileHelp' => 'Excel or CSV · Up to 500 rows · Maximum 2 MB.'])
    @endif
    @if ($accountImportResult !== [])
        <p class="setup-success" role="status">{{ $accountImportResult['created'] }} accounts created; {{ $accountImportResult['updated'] }} existing accounts updated.</p>
    @endif
    @if ($accountPreview !== [])
        <div class="setup-note">
        <h4>Review before importing</h4>
        <p>{{ $accountPreview['row_count'] }} source rows · {{ $accountPreview['created'] }} to create · {{ $accountPreview['updated'] }} to update · {{ $accountPreview['error_count'] }} errors.</p>
        @if ($accountPreview['errors'] !== [])
            <p class="setup-error">Nothing has been imported. Correct all errors and upload the file again.</p>
            <x-filament::button type="button" color="gray" wire:click="downloadAccountErrors" wire:target="downloadAccountErrors" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="downloadAccountErrors">Download row errors (CSV)</span>
                <span wire:loading wire:target="downloadAccountErrors">Preparing error CSV…</span>
            </x-filament::button>
            @if ($accountPreview['error_count'] > 25)<p>Showing the first 25 errors. Download the CSV for all {{ $accountPreview['error_count'] }} errors.</p>@endif
            <div style="overflow:auto;max-height:250px"><table class="setup-territories"><thead><tr><th>Row</th><th>Reference</th><th>Error</th></tr></thead><tbody>
                @foreach ($accountPreview['errors'] as $error)<tr><td>{{ $error['row'] }}</td><td>{{ $error['reference'] }}</td><td>{{ $error['message'] }}</td></tr>@endforeach
            </tbody></table></div>
        @endif
        <div style="overflow:auto;max-height:320px"><table class="setup-territories"><thead><tr><th>Row</th><th>Action</th><th>Reference</th><th>Account</th><th>Owner</th><th>Details</th></tr></thead><tbody>
            @foreach ($accountPreview['plans'] as $plan)
                <tr><td>{{ $plan['line'] }}</td><td>{{ $plan['id'] ? 'Update' : 'Create' }}</td><td>{{ $plan['data']['external_reference'] }}</td><td>{{ $plan['data']['name'] }}<br>{{ $plan['data']['account_type'] }}</td><td>{{ $plan['data']['owner_email'] }}</td><td>
                    <details><summary>View row details</summary><p>These are the values supplied in your file. Any validation errors are listed above.</p><dl>@foreach ($plan['data'] as $field => $value)@if ($value !== '')<dt>{{ str_replace('_', ' ', $field) }}</dt><dd>{{ $value }}</dd>@endif @endforeach</dl></details>
                    @if ($plan['warning'] ?? null)<p>{{ $plan['warning'] }}</p>@endif
                </td></tr>
            @endforeach
        </tbody></table></div>
        @if ($accountPreview['pages'] > 1)
            <div class="setup-actions">
                <x-filament::button color="gray" wire:click="showAccountPreviewPage({{ $accountPreviewPage - 1 }})" :disabled="$accountPreviewPage <= 1">Previous rows</x-filament::button>
                <span>Page {{ $accountPreviewPage }} of {{ $accountPreview['pages'] }} · 25 valid rows per page</span>
                <x-filament::button color="gray" wire:click="showAccountPreviewPage({{ $accountPreviewPage + 1 }})" :disabled="$accountPreviewPage >= $accountPreview['pages']">Next rows</x-filament::button>
            </div>
        @endif
        <x-filament::button type="button" wire:click="importAccounts" wire:target="importAccounts,accountFile,previewAccounts" wire:loading.attr="disabled" :disabled="$accountPreview['errors'] !== []">
            <span wire:loading.remove wire:target="importAccounts">Confirm import</span>
            <span wire:loading wire:target="importAccounts">Importing accounts…</span>
        </x-filament::button>
        @if ($accountPreview['errors'] !== [])<p class="setup-card-help">Confirm import becomes available after all row errors are corrected and the file is previewed again.</p>@endif
        </div>
    @endif
</section>
