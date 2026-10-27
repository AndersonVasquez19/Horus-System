@props(['stats' => []])
<div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
  @foreach ($stats as $stat)
    <div class="stat-card">
      <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gold/10 text-2xl text-gold">
        <i class="{{ $stat['icon'] }}"></i>
      </div>
      <h3 class="text-3xl font-bold text-ink">{{ $stat['value'] }}</h3>
      <p class="mt-3 text-sm leading-6 text-slate-600">{{ $stat['label'] }}</p>
    </div>
  @endforeach
</div>
