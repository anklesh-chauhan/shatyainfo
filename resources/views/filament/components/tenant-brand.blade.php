@php
    $name = \App\Support\Tenancy\TenantBranding::name();
    $logo = \App\Support\Tenancy\TenantBranding::logo();
@endphp
<div style="display:flex;align-items:center;gap:8px;min-width:0;max-width:100%" title="{{ $name }}">
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $name }} logo" style="width:32px;height:32px;object-fit:contain;flex-shrink:0">
    @else
        <img src="{{ asset('shatya-info-logo-transparent.png') }}" alt="" width="32" height="32" style="width:32px;height:32px;object-fit:contain;flex-shrink:0">
    @endif
    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:16px;font-weight:600">{{ $name }}</span>
</div>
