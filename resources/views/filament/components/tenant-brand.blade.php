@php
    $name = \App\Support\Tenancy\TenantBranding::name();
    $logo = \App\Support\Tenancy\TenantBranding::logo();
@endphp
<div style="display:flex;align-items:center;gap:8px;min-width:0;max-width:100%" title="{{ $name }}">
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $name }} logo" style="width:32px;height:32px;object-fit:contain;flex-shrink:0">
    @else
        <span aria-hidden="true" style="display:grid;place-items:center;width:32px;height:32px;flex-shrink:0;border-radius:8px;background:#e8f0fe;color:#1967d2;font-size:16px">{{ mb_substr($name, 0, 1) }}</span>
    @endif
    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:16px;font-weight:600">{{ $name }}</span>
</div>
