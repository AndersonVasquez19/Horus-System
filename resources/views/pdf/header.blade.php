@php
    $logoFile = public_path('assets/images/index/logos/logo-horus.png');
    $logoData = file_exists($logoFile) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile)) : null;
@endphp

<div class="header-container">
    @if ($logoData)
        <img src="{{ $logoData }}" class="header-logo" alt="Horus Group SRL">
    @endif
    <div class="header-institution">HORUS GROUP SRL</div>
    <div class="header-title">{{ $titulo ?? 'Reporte Administrativo' }}</div>
    @if (!empty($subtitulo))
        <div class="header-subtitle">{{ $subtitulo }}</div>
    @endif
    <div class="header-accent-bar"></div>
</div>
