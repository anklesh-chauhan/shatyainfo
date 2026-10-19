<x-filament-panels::page>
    @if ($fromOnboarding)
        <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\Onboarding::getUrl(['section' => 'field-travel'], panel: 'admin')">Return to company setup</x-filament::button>
        <p>This optional setup import supports patches, customer assignments and standard fares. You may leave Patch Customers blank until customers and addresses exist.</p>
    @endif
    <x-filament::section heading="Prepare your workbook">
        <p>Set up territories first. Add customers before importing their patch assignments. Historical travel requires existing daily call reports.</p>
        @if (! $fromOnboarding)<label><input type="checkbox" wire:model.live="historical"> Import historical travel segments instead of patches and fares</label>@endif
        <div class="mt-4"><x-filament::button wire:click="downloadTemplate">Download Excel template</x-filament::button></div>
        <details class="mt-4">
            <summary>Instructions and examples</summary>
            @foreach (app(\App\Services\DataImport\FieldTravelTemplate::class)->instructions() as [$heading, $instruction])
                <p class="mt-3"><strong>{{ $heading }}:</strong> {{ $instruction }}</p>
            @endforeach
            <p class="mt-3">Fictitious examples are included on the Examples sheet. Fill the data sheets with your own records.</p>
        </details>
    </x-filament::section>
    <form wire:submit="previewFile">
        <input type="file" wire:model="file" accept=".xlsx">
        @error('file') <p role="alert">{{ $message }}</p> @enderror
        <x-filament::button type="submit" wire:loading.attr="disabled">Preview workbook</x-filament::button>
    </form>
    @if ($importErrors)
        <x-filament::section heading="Errors — correct these and preview again">
            @foreach ($importErrors as $error) <p>{{ $error }}</p> @endforeach
        </x-filament::section>
    @endif
    @if ($preview)
        <x-filament::section heading="Review import">
            <p>{{ count($preview) }} rows. Expand a row to inspect its resolved values. Nothing is saved during preview.</p>
            @foreach (array_slice($preview, (max(1, $previewPage) - 1) * 100, 100) as $row)
                <details class="mt-2">
                    <summary>{{ $row['sheet'] }} row {{ $row['row'] }} — {{ $row['key'] }} — {{ $row['action'] }}</summary>
                    @foreach ($row['values'] as $field => $value)
                        <p>{{ str($field)->headline() }}: {{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</p>
                    @endforeach
                </details>
            @endforeach
            @if (count($preview) > 100)
                <label>Preview page <select wire:model.live="previewPage">
                    @for ($page = 1; $page <= ceil(count($preview) / 100); $page++)
                        <option value="{{ $page }}">{{ $page }}</option>
                    @endfor
                </select></label>
            @endif
            @if ($status === 'preview')
                <x-filament::button class="mt-4" wire:click="importReviewed" wire:loading.attr="disabled">Import reviewed workbook</x-filament::button>
            @endif
        </x-filament::section>
    @endif
    @if ($status === 'queued')
        <div wire:poll.5s="refreshStatus">Import queued or processing. Keep the queue worker running. This page will update when it finishes.</div>
    @elseif ($status === 'complete')
        <p role="status">Import complete: {{ $result['Create'] ?? 0 }} created, {{ $result['Update'] ?? 0 }} updated, {{ $result['Unchanged'] ?? 0 }} unchanged.</p>
    @elseif ($status === 'failed')
        <p role="alert">Import failed. Correct the reported issue and preview again.</p>
    @endif
</x-filament-panels::page>
