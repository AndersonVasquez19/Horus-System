<header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-gold/20 bg-white px-4 sm:px-6 lg:px-8">
    <div class="flex min-w-0 items-center gap-3">
        <button
            type="button"
            @click="sidebarOpen = !sidebarOpen"
            class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-stone-600 hover:bg-stone-100 md:hidden"
            aria-label="Abrir menú de navegación"
        >
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink">@yield('breadcrumb', 'Panel de Control')</p>
            <p class="hidden text-xs text-stone-500 sm:block">Sistema de gestión académica</p>
        </div>
    </div>

    <div class="flex items-center gap-3 sm:gap-5">
        <form action="{{ route('admin.busqueda.index') }}" method="GET" class="hidden items-center gap-2 rounded-full border border-stone-200 bg-stone-50 px-3 py-2 lg:flex">
            <i class="fa-solid fa-magnifying-glass text-xs text-stone-500" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Buscar en el sistema" class="w-56 border-0 bg-transparent text-sm text-ink placeholder:text-stone-400 focus:outline-none" />
        </form>
        <span class="hidden rounded-full bg-gold/10 px-3 py-1.5 text-xs font-semibold text-gold-dark sm:inline-flex">Horus Group SRL</span>
        <div class="flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-ink text-sm font-semibold text-gold">
                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </span>
            <span class="hidden max-w-36 truncate text-sm font-medium sm:block">{{ auth()->user()->name }}</span>
        </div>
    </div>
</header>
