@props(['slides' => [], 'stats' => [], 'highlights' => []])
<div x-data="carousel({{ count($slides) }})" x-on:mouseenter="pause()" x-on:mouseleave="resume()" x-on:focusin="pause()" x-on:focusout="resume()" x-on:keydown.left="prev()" x-on:keydown.right="next()" class="carousel-frame hero-carousel" role="region" aria-roledescription="carrusel" aria-label="Servicios de Horus Group SRL">
  <h1 class="sr-only">Horus Group SRL: tecnología, capacitaciones y soporte en Cajamarca</h1>

  <div class="hero-backdrops" aria-hidden="true">
    @foreach ($slides as $index => $slide)
      <div class="hero-backdrop {{ $index === 0 ? 'is-active' : '' }}" :class="{ 'is-active': current === {{ $index }} }">
        <img src="{{ asset($slide['image']) }}" alt="" loading="{{ $index === 0 ? 'eager' : 'lazy' }}" fetchpriority="{{ $index === 0 ? 'high' : 'low' }}" decoding="async">
      </div>
    @endforeach
    <div class="hero-overlay"></div>
  </div>

  <div class="section-shell hero-body">
    <div class="hero-copy-stack">
      @foreach ($slides as $index => $slide)
        <div class="hero-copy {{ $index === 0 ? 'is-active' : '' }}" :class="{ 'is-active': current === {{ $index }} }" role="group" aria-roledescription="diapositiva" aria-label="{{ $index + 1 }} de {{ count($slides) }}">
          <span class="hero-badge"><i class="{{ $slide['icon'] }}" aria-hidden="true"></i> {{ $slide['eyebrow'] }}</span>
          <h2 class="hero-title">{{ $slide['title'] }} <span>{{ $slide['highlight'] }}</span></h2>
          <p class="hero-text">{{ $slide['text'] }}</p>
          <div class="hero-actions">
            <a href="{{ $slide['cta']['url'] }}" class="hero-btn hero-btn--primary">{{ $slide['cta']['label'] }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            <a href="{{ $slide['cta2']['url'] }}" class="hero-btn hero-btn--ghost">{{ $slide['cta2']['label'] }}</a>
          </div>
        </div>
      @endforeach
    </div>

    @if ($stats)
      <dl class="hero-stats">
        @foreach ($stats as $stat)
          <div class="hero-stat">
            <dt>{{ $stat['value'] }}</dt>
            <dd>{{ $stat['label'] }}</dd>
          </div>
        @endforeach
      </dl>
    @endif
  </div>

  @if ($highlights)
    <ul class="hero-highlights">
      @foreach ($highlights as $item)
        <li>
          <span class="hero-highlight-icon"><i class="{{ $item['icon'] }}" aria-hidden="true"></i></span>
          <span><strong>{{ $item['title'] }}</strong><small>{{ $item['text'] }}</small></span>
        </li>
      @endforeach
    </ul>
  @endif

  <button type="button" @click="prev()" aria-label="Diapositiva anterior" class="hero-arrow hero-arrow--prev"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
  <button type="button" @click="next()" aria-label="Diapositiva siguiente" class="hero-arrow hero-arrow--next"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>

  <div class="hero-dots">
    @foreach ($slides as $index => $slide)
      <button type="button" @click="go({{ $index }})" :class="{ 'is-active': current === {{ $index }} }" :aria-current="current === {{ $index }}" class="hero-dot {{ $index === 0 ? 'is-active' : '' }}" aria-label="Ir a la diapositiva {{ $index + 1 }}"></button>
    @endforeach
  </div>
</div>
