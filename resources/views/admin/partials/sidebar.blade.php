<aside
    id="admin-sidebar"
    x-ref="sidebar"
    :inert="menuInerte"
    class="fixed inset-y-0 left-0 z-40 flex w-[260px] -translate-x-full flex-col border-r border-white/10 bg-[#0A2540] text-slate-300 shadow-xl shadow-[#0A2540]/20 transition-transform duration-200 md:w-[72px] md:translate-x-0 xl:w-[260px]"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
    aria-label="Navegación administrativa"
>
    <a href="/admin" class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-4 md:justify-center md:px-2 xl:justify-start xl:px-5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[#19A7A1]">
        <img
            src="{{ asset('assets/images/index/logos/logo-horus.png') }}"
            alt="Logo Horus Group"
            class="h-9 w-auto max-w-[44px] shrink-0 object-contain drop-shadow-[0_2px_8px_rgba(25,167,161,0.3)]"
        >
        <span class="min-w-0 md:hidden xl:block">
            <span class="block truncate text-sm font-bold tracking-tight text-white">{{ \App\Support\ConfiguracionSistema::nombreEmpresa() }}</span>
            <span class="block text-[11px] font-medium text-[#19A7A1]">Panel Corporativo</span>
        </span>
    </a>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <p class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Principal</p>

        <x-admin.nav-item href="/admin" title="Panel de Control" icon="fa-chart-line" :active="request()->is('admin')" />

        <p class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Gestión académica</p>

        @can('estudiantes.ver')
            <x-admin.nav-item href="/admin/estudiantes" title="Estudiantes" icon="fa-user-graduate" :active="request()->is('admin/estudiantes*')" />
        @endcan

        @can('cursos.ver')
            <x-admin.nav-item href="/admin/cursos" title="Cursos" icon="fa-book-open" :active="request()->is('admin/cursos*')" />
        @endcan

        @can('matriculas.ver')
            <x-admin.nav-item href="/admin/matriculas" title="Matrículas" icon="fa-clipboard-check" :active="request()->is('admin/matriculas*')" />
        @endcan

        @can('solicitudes-matricula.ver')
            <x-admin.nav-item :href="route('admin.solicitudes-matricula.index')" title="Solicitudes de matrícula" icon="fa-file-pen" :active="request()->routeIs('admin.solicitudes-matricula.*')" />
        @endcan

        <p class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Comercial y atención</p>

        @can('servicios.ver')
            <x-admin.nav-item :href="route('admin.servicios.index')" title="Servicios tecnológicos" icon="fa-network-wired" :active="request()->is('admin/servicios*')" />
        @endcan

        @if(auth()->user()?->hasRole('admin') && auth()->user()?->can('cotizaciones.ver'))
            <x-admin.nav-item :href="route('admin.cotizaciones.index')" title="Solicitudes de cotización" icon="fa-file-lines" :active="request()->is('admin/cotizaciones*')" />
        @endif

        @can('reclamaciones.ver')
            <x-admin.nav-item :href="route('admin.reclamaciones.index')" title="Libro de Reclamaciones" icon="fa-book-bookmark" :active="request()->is('admin/reclamaciones*')" />
        @endcan

        @can('asistente.ver')
            <x-admin.nav-item href="/admin/asistente" title="Asistente Virtual" icon="fa-robot" :active="request()->is('admin/asistente*')" />
        @endcan

        <p class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Operación interna</p>

        @can('aprobaciones.ver')
            <x-admin.nav-item :href="route('admin.aprobaciones.index')" title="Aprobaciones" icon="fa-check-to-slot" :active="request()->is('admin/aprobaciones*')" />
        @endcan

        <x-admin.nav-item :href="route('admin.notificaciones.index')" title="Notificaciones" icon="fa-bell" :active="request()->is('admin/notificaciones*')" />

        @can('reportes.ver')
            <x-admin.nav-item :href="route('admin.reportes.index')" title="Reportes" icon="fa-file-export" :active="request()->is('admin/reportes*')" />
        @endcan

        <x-admin.nav-item :href="route('admin.busqueda.index')" title="Buscar" icon="fa-magnifying-glass" :active="request()->is('admin/buscar*')" />

        <p class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Sistema y seguridad</p>

        @can('usuarios.ver')
            <x-admin.nav-item :href="route('admin.usuarios.index')" title="Usuarios" icon="fa-users" :active="request()->is('admin/usuarios*')" />
        @endcan

        @can('roles.ver')
            <x-admin.nav-item :href="route('admin.roles.index')" title="Roles" icon="fa-shield-halved" :active="request()->is('admin/roles*')" />
        @endcan

        @can('configuracion.ver')
            <x-admin.nav-item :href="route('admin.configuracion.index')" title="Configuración" icon="fa-gear" :active="request()->is('admin/configuracion*')" />
        @endcan

        @can('auditoria.ver')
            <x-admin.nav-item :href="route('admin.auditoria.index')" title="Auditoría" icon="fa-clipboard-list" :active="request()->is('admin/auditoria*')" />
        @endcan

        @can('respaldo.ver')
            <x-admin.nav-item :href="route('admin.respaldo.index')" title="Respaldo" icon="fa-database" :active="request()->is('admin/respaldo*')" />
        @endcan

        <div class="my-4 border-t border-white/10"></div>

        <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer" title="Ver sitio público" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-slate-300 transition hover:bg-[#176B9A]/25 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]">
            <i class="fa-solid fa-arrow-up-right-from-square w-5 shrink-0 text-center text-slate-400 transition-colors group-hover:text-[#19A7A1]" aria-hidden="true"></i>
            <span class="truncate md:hidden xl:inline">Ver sitio público</span>
        </a>
        <x-admin.nav-item :href="route('admin.perfil.edit')" title="Mi perfil" icon="fa-user" :active="request()->is('admin/perfil*')" />
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" title="Cerrar sesión" class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm text-slate-300 transition hover:bg-[#176B9A]/25 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]">
                <i class="fa-solid fa-right-from-bracket w-5 shrink-0 text-center text-slate-400 transition-colors group-hover:text-[#19A7A1]" aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Cerrar sesión</span>
            </button>
        </form>
    </nav>
</aside>
