@props(['nombre', 'logo', 'siglas'])
<button type="button" {{ $attributes->merge(['class' => 'convenio-card']) }}>
  <span class="convenio-card-logo"><img src="{{ asset($logo) }}" alt="" loading="lazy" /></span>
  <span class="convenio-card-copy">
    <span class="convenio-card-title">{{ $nombre }}</span>
    <span class="convenio-card-acronym">{{ $siglas }}</span>
  </span>
  <span class="convenio-card-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-up-right-from-square"></i></span>
</button>
