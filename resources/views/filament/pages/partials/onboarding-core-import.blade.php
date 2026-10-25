<div class="setup-shell">
    @php($checklist = $this->checklist())
    @if ($errors->any())
        <div class="setup-error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if ($importSection === 'territories')
        @include('filament.pages.partials.onboarding-territory-import')
    @elseif ($importSection === 'accounts')
        @include('filament.pages.partials.onboarding-account-import')
    @endif
</div>
