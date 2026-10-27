@props(['slides' => []])
<div x-data="carousel()" class="carousel-frame relative overflow-hidden rounded-[24px] shadow-gold">
  <div class="relative h-[350px] sm:h-[450px] lg:h-[600px]">
    @foreach ($slides as $index => $slide)
      <div x-show="current === {{ $index }}" x-cloak x-transition:enter="transition duration-700 ease-out" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition duration-500 ease-in" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-105" class="absolute inset-0">
        <img src="{{ asset($slide['image']) }}" alt="{{ $slide['alt'] }}" loading="{{ $index === 0 ? 'eager' : 'lazy' }}" fetchpriority="{{ $index === 0 ? 'high' : 'low' }}" decoding="async" class="h-full w-full object-contain object-center brightness-[0.85] contrast-[1.05] max-sm:object-cover" />
      </div>
    @endforeach
  </div>

  <button type="button" @click="prev()" aria-label="Imagen anterior" class="absolute left-4 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/80 text-lg text-ink shadow-lg hover:bg-gold"><i class="fa-solid fa-chevron-left"></i></button>
  <button type="button" @click="next()" aria-label="Imagen siguiente" class="absolute right-4 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/80 text-lg text-ink shadow-lg hover:bg-gold"><i class="fa-solid fa-chevron-right"></i></button>

  <div class="absolute bottom-5 left-1/2 flex -translate-x-1/2 items-center gap-2">
    @foreach ($slides as $index => $slide)
      <button type="button" @click="current = {{ $index }}" :class="current === {{ $index }} ? 'w-8 bg-gold' : 'w-2.5 bg-white/70'" class="h-2.5 rounded-full transition-all" aria-label="Ir al slide {{ $index + 1 }}"></button>
    @endforeach
  </div>
</div>
