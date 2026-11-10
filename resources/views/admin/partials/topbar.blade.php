<header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-[#E2E8F0] bg-white/95 px-4 shadow-[0_1px_3px_rgba(10,37,64,0.04)] backdrop-blur-md sm:px-6 lg:px-8">
    <div class="flex min-w-0 items-center gap-3">
        <button
            type="button"
            x-ref="botonMenu"
            @click="alternarMenu()"
            aria-controls="admin-sidebar"
            aria-expanded="false"
            :aria-expanded="sidebarOpen.toString()"
            class="admin-icon-btn md:hidden"
            aria-label="Abrir menú de navegación"
            :aria-label="sidebarOpen ? 'Cerrar menú de navegación' : 'Abrir menú de navegación'"
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
            {{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}
        </span>

        @php
            $pendientesCampana = \App\Support\NotificacionesPendientes::para(auth()->user());
            $contadorCampana = $pendientesCampana > 99 ? '99+' : (string) $pendientesCampana;
            $etiquetaCampana = match (true) {
                $pendientesCampana === 0 => 'Notificaciones, ninguna sin leer',
                $pendientesCampana === 1 => 'Notificaciones, 1 sin leer',
                default => "Notificaciones, {$pendientesCampana} sin leer",
            };
        @endphp
        <div
            class="relative"
            x-data="campanaNotificaciones"
            data-pendientes="{{ $pendientesCampana }}"
            data-url-recientes="{{ route('admin.notificaciones.recientes') }}"
            data-url-leidas="{{ route('admin.notificaciones.leidas') }}"
            @keydown.escape.stop="cerrar(true)"
            @click.outside="cerrar(false)"
        >
            {{-- Sin JavaScript es un enlace a la bandeja; con Alpine abre el desplegable. --}}
            <a
                href="{{ route('admin.notificaciones.index') }}"
                x-ref="boton"
                data-campana-boton
                :role="'button'"
                aria-haspopup="true"
                aria-expanded="false"
                :aria-expanded="abierto.toString()"
                aria-controls="campana-panel"
                aria-label="{{ $etiquetaCampana }}"
                :aria-label="etiqueta"
                @click.prevent="alternar()"
                @keydown.space.prevent="alternar()"
                class="admin-icon-btn relative"
            >
                <i class="fa-solid fa-bell" aria-hidden="true"></i>
                <span
                    data-campana-contador
                    x-show="pendientes > 0"
                    x-text="contador"
                    aria-hidden="true"
                    @if ($pendientesCampana === 0) style="display: none" @endif
                    class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-white"
                >{{ $contadorCampana }}</span>
            </a>

            <div
                id="campana-panel"
                x-cloak
                x-show="abierto"
                x-transition.opacity
                role="region"
                aria-label="Notificaciones recientes"
                class="absolute right-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-[#E2E8F0] bg-white text-left shadow-xl"
            >
                <div class="flex items-center justify-between gap-3 border-b border-[#E2E8F0] px-4 py-3">
                    <p class="text-sm font-bold text-[#172B3A]">Notificaciones</p>
                    <button
                        type="button"
                        x-show="pendientes > 0"
                        @click="marcarTodas()"
                        :disabled="ocupado"
                        :aria-busy="ocupado.toString()"
                        class="text-xs font-semibold text-[#176B9A] hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#176B9A] disabled:opacity-60"
                    >Marcar todas como leídas</button>
                </div>

                <p class="sr-only" role="status" x-text="anuncio"></p>

                <div class="max-h-96 overflow-y-auto">
                    <p x-show="cargando" class="px-4 py-6 text-center text-sm text-[#64748B]">Cargando…</p>
                    <p x-show="error" x-text="error" role="alert" class="px-4 py-4 text-sm text-red-700"></p>
                    <p x-show="cargado && !cargando && !error && items.length === 0" class="px-4 py-6 text-center text-sm text-[#64748B]">No tienes notificaciones sin leer.</p>
                    <ul x-show="items.length > 0" class="divide-y divide-[#E2E8F0]">
                        <template x-for="item in items" :key="item.id">
                            <li class="space-y-1 px-4 py-3">
                                <p class="text-sm font-semibold text-[#172B3A]" x-text="item.titulo"></p>
                                <p class="text-xs leading-5 text-[#64748B]" x-text="item.mensaje"></p>
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-[11px] font-medium text-[#64748B]" x-text="item.fecha"></p>
                                    <a x-show="item.vinculo" :href="item.vinculo" class="text-xs font-semibold text-[#176B9A] hover:underline">Ver</a>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="border-t border-[#E2E8F0] px-4 py-3 text-center">
                    <a href="{{ route('admin.notificaciones.index') }}" class="text-xs font-semibold text-[#176B9A] hover:underline">Ver todas las notificaciones</a>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2.5 rounded-full border border-[#E2E8F0] bg-[#F4F7FB] py-1 pl-1 pr-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#0A2540] text-xs font-bold text-white ring-2 ring-[#19A7A1]/40">
                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </span>
            <span class="hidden max-w-36 truncate text-xs font-semibold text-[#172B3A] sm:block">{{ auth()->user()->name }}</span>
        </div>
    </div>
</header>
