<form wire:submit="{{ $previewAction }}" class="setup-form">
    <div class="setup-field setup-upload-box">
        <label for="{{ $inputId }}">Upload your completed file</label>
        <input id="{{ $inputId }}" type="file" accept="{{ $accept }}" wire:model="{{ $fileProperty }}" wire:key="{{ $inputId }}" aria-describedby="{{ $inputId }}-help">
        <p id="{{ $inputId }}-help" class="setup-card-help">{{ $fileHelp }}</p>
    </div>
    <p wire:loading wire:target="{{ $fileProperty }}" role="status">Uploading file…</p>
    @error($fileProperty)<p class="setup-error" role="alert">{{ $message }}</p>@enderror
    @if (isset($errorProperty))
        @error($errorProperty)<p class="setup-error" role="alert">{{ $message }}</p>@enderror
    @endif
    <x-filament::button type="submit" wire:target="{{ $fileProperty }},{{ $previewAction }}" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="{{ $previewAction }}">Preview file</span>
        <span wire:loading wire:target="{{ $previewAction }}">Previewing file…</span>
    </x-filament::button>
</form>
