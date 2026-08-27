@php
    $size = $size ?? 48;
    $layout = $layout ?? 'row'; // 'row' = icona + testo affiancati, 'stack' = icona sopra, testo sotto
    $isStack = $layout === 'stack';
@endphp
<div style="display: flex; {{ $isStack ? 'flex-direction: column; align-items: center; gap: 0.85rem;' : 'flex-direction: row; align-items: center; gap: 0.9rem;' }}">
    <img
        src="{{ asset('images/compassbot-icon-512.png') }}"
        alt="CompassBot"
        style="height: {{ $size }}px; width: {{ $size }}px; display: block;"
    >
    <span style="font-family: 'Space Grotesk', ui-sans-serif, system-ui, sans-serif; font-weight: 700; font-size: {{ round($size * 0.46) }}px; letter-spacing: -0.02em; color: #2e2a26; {{ $isStack ? 'text-align: center;' : '' }}">Compass<span style="color: #d1701f;">Bot</span></span>
</div>
