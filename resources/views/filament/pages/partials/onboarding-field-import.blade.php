<section class="setup-import-card" aria-label="Field route setup">
    <div class="setup-card-heading"><span class="setup-card-number">2</span><div><h3>Set up field routes</h3><p>Import patches, account assignments and standard fares for your team's visits.</p></div></div>
    <div class="setup-card-stats"><span>Excel workbook · Maximum 10 MB</span></div>
    <x-filament::button color="gray" wire:click="downloadTemplate" wire:loading.attr="disabled">Download Excel template</x-filament::button>
    <p class="setup-card-help">Set up territories first. Leave account assignments blank until accounts and addresses exist.</p>
    <details class="setup-optional"><summary>Instructions and examples</summary>
        @foreach (app(\App\Services\DataImport\FieldTravelTemplate::class)->instructions() as [$heading, $instruction])<p><strong>{{ $heading }}:</strong> {{ $instruction }}</p>@endforeach
        <p>Fill the data sheets with your own records. Examples are never imported. Preview does not save records.</p>
    </details>
    @error('file')<p class="setup-error" role="alert">{{ $message }}</p>@enderror
    @include('filament.pages.partials.onboarding-import-upload', ['previewAction' => 'previewFile', 'fileProperty' => 'file', 'inputId' => 'field-route-file', 'accept' => '.xlsx', 'fileHelp' => '.xlsx · Maximum 10 MB.'])
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
    @if ($status === 'queued')<p wire:poll.5s="refreshStatus" role="status">Import queued or processing. This section will update when it finishes.</p>
    @elseif ($status === 'complete')<p class="setup-success" role="status">{{ $result['Create'] ?? 0 }} created, {{ $result['Update'] ?? 0 }} updated, {{ $result['Unchanged'] ?? 0 }} unchanged.</p>
    @elseif ($status === 'failed')<p class="setup-error" role="alert">Import failed. Correct the reported issue and preview again.</p>@endif
</section>
