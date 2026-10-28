@props(['items' => []])
<div x-data="convenios(@js($items))" class="convenios-layout">
  <div class="convenios-list">
    @foreach ($items as $item)
      <x-convenios.card :id="$item['id']" :nombre="$item['nombre']" :logo="$item['logo']" :siglas="$item['siglas']" :vigencia="$item['vigencia']" x-on:click="toggle('{{ $item['id'] }}')" />
    @endforeach
  </div>

  <div class="convenio-panel" x-ref="panel" aria-live="polite">
    <div x-show="active === null" x-cloak class="convenio-empty">
      <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="Horus Group" class="convenios-center-logo" />
      <h3>Convenios estratégicos</h3>
      <p>Selecciona un convenio para conocer sus beneficios</p>
    </div>

    <div x-show="active !== null" x-cloak x-transition class="convenio-detail">
      <button type="button" @click="close()" aria-label="Cerrar detalle del convenio" class="convenio-close">&times;</button>
      <img x-show="active && active.imagen" :src="active && active.imagen ? '{{ url('/') }}/' + active.imagen : ''" :alt="active ? active.nombre : ''" :style="active && active.posicion ? 'object-position:' + active.posicion : ''" class="convenio-detail-image" />
      <div x-show="active && !active.imagen" class="convenio-detail-logo">
        <img :src="active ? '{{ url('/') }}/' + active.logo : ''" :alt="active ? active.nombre : ''" />
      </div>
      <span class="convenio-detail-badge" x-text="active ? active.siglas : ''"></span>
      <h3 x-text="active ? active.nombre : ''"></h3>
      <p class="convenio-detail-vigencia"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i> Vigente hasta <strong x-text="active ? active.vigencia : ''"></strong></p>
      <p class="convenio-detail-text" x-text="active ? active.descripcion : ''"></p>
    </div>
  </div>
</div>
