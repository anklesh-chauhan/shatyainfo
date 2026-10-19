<section class="setup-note" aria-label="Account master import">
    <h3>Import account masters</h3>
    <p>{{ $checklist['account_count'] }} accounts available. Bring in doctors, institutions, pharmacies and other account types using your company masters.</p>
    <button type="button" class="setup-text-button" wire:click="downloadAccountTemplate" wire:loading.attr="disabled">Download account Excel template</button>
    <details class="setup-optional">
        <summary>How account import works</summary>
        <p>Fill the Accounts sheet only. Instructions explain every field group; References lists your company’s master values. Required: external_reference, name, account_type and owner_email.</p>
        <p>External reference is a stable source ID such as ERP:000123. Matching references update existing accounts. New references create accounts with generated application codes. Names are never used to match accounts.</p>
        <p>Blank optional cells preserve existing values. Patch codes add assignments. New accounts default to Prospect; Active status requires complete activation fields. Parent, institution and supplier references can appear in any row order.</p>
        <p>Preview does not save records. Correct every error before importing; the whole file saves together. To link manually entered accounts, set their External Reference in Accounts first.</p>
    </details>
    <form wire:submit="previewAccounts" class="setup-form">
        <div class="setup-field"><label for="account-file">Account Excel or CSV (up to 500 rows, 2 MB)</label><input id="account-file" type="file" accept=".xlsx,.csv" wire:model="accountFile"></div>
        <p wire:loading wire:target="accountFile">Uploading file…</p>
        <x-filament::button type="submit" wire:loading.attr="disabled">Preview accounts</x-filament::button>
    </form>
    @if ($accountImportResult !== [])
        <p class="setup-success" role="status">{{ $accountImportResult['created'] }} accounts created; {{ $accountImportResult['updated'] }} existing accounts updated.</p>
    @endif
    @if ($accountPreview !== [])
        <h4>Review account import</h4>
        <p>{{ count($accountPreview['rows']) }} source rows · {{ $accountPreview['created'] }} to create · {{ $accountPreview['updated'] }} to update · {{ count($accountPreview['errors']) }} errors.</p>
        @if ($accountPreview['errors'] !== [])
            <p class="setup-error">Nothing has been imported. Correct all errors and upload the file again.</p>
            <button type="button" class="setup-text-button" wire:click="downloadAccountErrors">Download row errors (CSV)</button>
            <div style="overflow:auto;max-height:250px"><table class="setup-territories"><thead><tr><th>Row</th><th>Reference</th><th>Error</th></tr></thead><tbody>
                @foreach ($accountPreview['errors'] as $error)<tr><td>{{ $error['row'] }}</td><td>{{ $error['reference'] }}</td><td>{{ $error['message'] }}</td></tr>@endforeach
            </tbody></table></div>
        @endif
        <div style="overflow:auto;max-height:320px"><table class="setup-territories"><thead><tr><th>Row</th><th>Action</th><th>Reference</th><th>Account</th><th>Owner</th><th>Details</th></tr></thead><tbody>
            @foreach ($accountPreview['plans'] as $plan)
                <tr><td>{{ $plan['line'] }}</td><td>{{ $plan['id'] ? 'Update' : 'Create' }}</td><td>{{ $plan['data']['external_reference'] }}</td><td>{{ $plan['data']['name'] }}<br>{{ $plan['data']['account_type'] }}</td><td>{{ $plan['data']['owner_email'] }}</td><td>
                    <details><summary>Review supplied fields</summary><dl>@foreach ($plan['data'] as $field => $value)@if ($value !== '')<dt>{{ str_replace('_', ' ', $field) }}</dt><dd>{{ $value }}</dd>@endif @endforeach</dl></details>
                    @if ($plan['warning'] ?? null)<p>{{ $plan['warning'] }}</p>@endif
                </td></tr>
            @endforeach
        </tbody></table></div>
        @if ($accountPreview['errors'] === [])
            <x-filament::button wire:click="importAccounts" wire:loading.attr="disabled">Import reviewed accounts</x-filament::button>
        @endif
    @endif
</section>
