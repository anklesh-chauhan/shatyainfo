<section class="setup-import-card" aria-label="Field route setup">
    <div class="setup-card-heading"><span class="setup-card-number">1</span><div><h3>Field routes & historical travel</h3><p>First import Patches and Standard Fares, leaving the Patch Customers sheet empty. After importing account masters below, return to add customer assignments and historical travel. Customer code fields accept your account code or external_reference.</p></div></div>
    <fieldset @disabled(in_array($status, ['queued', 'running'], true))>
    <div class="setup-field">
        <label for="field-travel-mode">Import type</label>
        <x-filament::input.wrapper><x-filament::input.select id="field-travel-mode" wire:model.live="historical">
            <option value="0">Patches, account assignments & standard fares</option>
            <option value="1">Historical travel segments (optional)</option>
        </x-filament::input.select></x-filament::input.wrapper>
    </div>
    <div class="setup-card-stats"><span>Excel workbook · Maximum 10 MB</span></div>
    <x-filament::button color="gray" wire:click="downloadTemplate" wire:loading.attr="disabled">Download Excel template</x-filament::button>
    @if ($historical)
        <p class="setup-card-help">Create employees and their editable daily call reports (DCRs) first. Each employee and date must match one existing DCR. Keep external_reference unchanged when importing again: matching segments are updated rather than duplicated. Locked reports cannot be changed. You can complete this migration later.</p>
    @else
        <p class="setup-card-help">Set up territories first. Leave account assignments blank until accounts and addresses exist.</p>
    @endif
    <p class="setup-card-help">Preview detects the import type when the workbook contains only patches and fares or only historical travel. For a workbook containing both types, choose which type to import.</p>
    <details class="setup-optional"><summary>Instructions and examples</summary>
        @foreach (app(\App\Services\DataImport\FieldTravelTemplate::class)->instructions() as [$heading, $instruction])<p><strong>{{ $heading }}:</strong> {{ $instruction }}</p>@endforeach
        <p>Fill the data sheets with your own records. Examples are never imported. Preview does not save records.</p>
    </details>
    @error('file')<p class="setup-error" role="alert">{{ $message }}</p>@enderror
    @include('filament.pages.partials.onboarding-import-upload', ['previewAction' => 'previewFile', 'fileProperty' => 'file', 'inputId' => 'field-route-file', 'accept' => '.xlsx', 'fileHelp' => '.xlsx · Maximum 10 MB.'])
    </fieldset>
    @if ($importErrors)<div class="setup-error" role="alert"><p>Correct all errors and upload the file again.</p><ul>@foreach ($importErrors as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if ($preview)
        <div class="setup-note"><h4>Review before importing</h4><p>{{ count($preview) }} rows. Nothing is saved during preview.</p>
            <div style="overflow:auto;max-height:320px"><table class="setup-territories"><thead><tr><th>Row</th><th>Record</th><th>Action</th><th>Details</th></tr></thead><tbody>
                @foreach (array_slice($preview, (max(1, $previewPage) - 1) * 100, 100) as $row)
                    <tr><td>{{ $row['sheet'] }}: {{ $row['row'] }}</td><td>{{ $row['key'] }}</td><td>{{ $row['action'] }}</td><td><details><summary>Review supplied fields</summary><dl>@foreach ($row['values'] as $field => $value)<dt>{{ str($field)->headline() }}</dt><dd>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</dd>@endforeach</dl></details></td></tr>
                @endforeach
            </tbody></table></div>
            @if (count($preview) > 100)<label>Preview page <select wire:model.live="previewPage">@for ($page = 1; $page <= ceil(count($preview) / 100); $page++)<option value="{{ $page }}">{{ $page }}</option>@endfor</select></label>@endif
            @if ($status === 'preview')<x-filament::button wire:click="importReviewed" wire:loading.attr="disabled">Confirm import</x-filament::button>@endif
        </div>
    @endif
    @if (in_array($status, ['queued', 'running'], true))
        <div wire:poll.3s="refreshStatus" role="status" aria-live="polite">
            <h4>{{ $status === 'queued' ? 'Field travel import queued' : 'Importing field travel records' }}</h4>
            <p>{{ $status === 'queued' ? 'Waiting for the import worker.' : 'Validating and importing your workbook.' }}</p>
            <progress max="{{ max(1, $progress['total'] ?? 0) }}" value="{{ $progress['processed'] ?? 0 }}" aria-label="Field travel import progress"></progress>
            <p>{{ $progress['processed'] ?? 0 }} of {{ $progress['total'] ?? 0 }} rows processed. Records are saved when the whole import completes.</p>
            <p>You can leave this page and return. The import continues in the background.</p>
        </div>
    @elseif ($status === 'complete')<p class="setup-success" role="status">{{ $result['Create'] ?? 0 }} created, {{ $result['Update'] ?? 0 }} updated, {{ $result['Unchanged'] ?? 0 }} unchanged.</p>
    @elseif ($status === 'failed')<p class="setup-error" role="alert">Import failed. Correct the reported issue and preview again.</p>@endif
</section>
