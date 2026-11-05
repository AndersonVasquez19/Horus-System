<header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-[#E2E8F0] bg-white/95 px-4 shadow-[0_1px_3px_rgba(10,37,64,0.04)] backdrop-blur-md sm:px-6 lg:px-8">
    <div class="flex min-w-0 items-center gap-3">
        <button
            type="button"
            @click="sidebarOpen = !sidebarOpen"
            aria-expanded="false"
            :aria-expanded="sidebarOpen.toString()"
            class="admin-icon-btn md:hidden"
            aria-label="Abrir menú de navegación"
        >
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <span class="hidden h-2 w-2 shrink-0 rounded-full bg-[#19A7A1] sm:inline-block" aria-hidden="true"></span>
                <p class="truncate text-sm font-semibold text-[#172B3A]">@yield('breadcrumb', 'Panel de Control')</p>
            </div>
            <p class="hidden text-xs text-[#64748B] sm:block">Sistema de gestión académica y corporativa</p>
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-3 sm:gap-4">
        <form action="{{ route('admin.busqueda.index') }}" method="GET" role="search" class="hidden items-center gap-2 rounded-xl border border-[#E2E8F0] bg-[#F4F7FB] px-3.5 py-2 transition focus-within:border-[#176B9A] focus-within:bg-white focus-within:ring-2 focus-within:ring-[#176B9A]/15 lg:flex">
            <label for="topbar-global-search" class="sr-only">Buscar en el sistema</label>
            <i class="fa-solid fa-magnifying-glass text-xs text-[#64748B]" aria-hidden="true"></i>
            <input
                id="topbar-global-search"
                type="search"
                name="q"
                value="{{ is_string(request('q')) ? request('q') : '' }}"
                placeholder="Buscar en el sistema..."
                class="w-56 border-0 bg-transparent text-sm text-[#172B3A] placeholder:text-[#64748B] focus:outline-none"
            />
        </form>

        <span class="hidden items-center gap-1.5 rounded-full border border-[#176B9A]/20 bg-[#176B9A]/10 px-3 py-1.5 text-xs font-semibold text-[#176B9A] sm:inline-flex">
            <i class="fa-solid fa-building-shield text-[11px] text-[#19A7A1]" aria-hidden="true"></i>
            Horus Group SRL
        </span>

        <div class="flex items-center gap-2.5 rounded-full border border-[#E2E8F0] bg-[#F4F7FB] py-1 pl-1 pr-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#0A2540] text-xs font-bold text-white ring-2 ring-[#19A7A1]/40">
                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </span>
            <span class="hidden max-w-36 truncate text-xs font-semibold text-[#172B3A] sm:block">{{ auth()->user()->name }}</span>
        </div>
    </div>
</header>
