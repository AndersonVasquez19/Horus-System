@props(['items' => []])
<div x-data="convenios(@js($items))" class="convenios-layout">
  <div class="convenios-list">
    @foreach (array_slice($items, 0, 3) as $item)
      <x-convenios.card :nombre="$item['nombre']" :logo="$item['logo']" :siglas="$item['siglas']" x-on:click="open('{{ $item['id'] }}')" />
    @endforeach
  </div>

  <div class="convenio-panel">
    <div x-show="active === null" x-cloak class="convenio-empty">
      <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="Horus Group" class="convenios-center-logo" />
      <h3>Convenios estratégicos</h3>
      <p>Selecciona un convenio para conocer sus beneficios</p>
    </div>

    <div x-show="active !== null" x-cloak x-transition class="convenio-detail">
      <button type="button" @click="close()" aria-label="Volver a convenios" class="convenio-close">&times;</button>
      <img :src="active ? '{{ url('/') }}/' + active.imagen : ''" :alt="active ? active.nombre : ''" class="convenio-detail-image" />
      <h3 x-text="active ? active.nombre : ''"></h3>
      <p x-text="active ? active.descripcion : ''"></p>
    </div>
  </div>

  <div class="convenios-list">
    @foreach (array_slice($items, 3, 3) as $item)
      <x-convenios.card :nombre="$item['nombre']" :logo="$item['logo']" :siglas="$item['siglas']" x-on:click="open('{{ $item['id'] }}')" />
    @endforeach
  </div>
</div>
