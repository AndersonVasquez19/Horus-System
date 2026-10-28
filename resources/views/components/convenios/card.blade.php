@props(['id', 'nombre', 'logo', 'siglas', 'vigencia'])
<button type="button" x-bind:aria-pressed="active && active.id === '{{ $id }}' ? 'true' : 'false'" x-bind:class="{ 'is-active': active && active.id === '{{ $id }}' }" {{ $attributes->merge(['class' => 'convenio-card']) }}>
  <span class="convenio-card-logo"><img src="{{ asset($logo) }}" alt="" loading="lazy" /></span>
  <span class="convenio-card-copy">
    <span class="convenio-card-title">{{ $nombre }}</span>
    <span class="convenio-card-acronym">{{ $siglas }}</span>
    <span class="convenio-card-date"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i> Vigente hasta {{ $vigencia }}</span>
  </span>
  <span class="convenio-card-arrow" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
</button>
