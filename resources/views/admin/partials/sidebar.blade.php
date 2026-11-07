<aside
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
            <span class="block truncate text-sm font-bold tracking-tight text-white">Horus Group</span>
            <span class="block text-[11px] font-medium text-[#19A7A1]">Panel Corporativo</span>
        </span>
    </a>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <p class="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Principal</p>

        <a href="/admin" title="Panel de Control" @if(request()->is('admin')) aria-current="page" @endif @class([
            'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
            'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin'),
            'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin'),
        ])>
            @if(request()->is('admin'))
                <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
            @endif
            <i @class([
                'fa-solid fa-chart-line w-5 shrink-0 text-center transition-colors',
                'text-[#19A7A1]' => request()->is('admin'),
                'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin'),
            ]) aria-hidden="true"></i>
            <span class="truncate md:hidden xl:inline">Panel de Control</span>
        </a>

        <p class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Gestión académica</p>

        @can('estudiantes.ver')
            <a href="/admin/estudiantes" title="Estudiantes" @if(request()->is('admin/estudiantes*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/estudiantes*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/estudiantes*'),
            ])>
                @if(request()->is('admin/estudiantes*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-user-graduate w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/estudiantes*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/estudiantes*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Estudiantes</span>
            </a>
        @endcan

        @can('cursos.ver')
            <a href="/admin/cursos" title="Cursos" @if(request()->is('admin/cursos*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/cursos*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/cursos*'),
            ])>
                @if(request()->is('admin/cursos*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-book-open w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/cursos*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/cursos*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Cursos</span>
            </a>
        @endcan

        @can('matriculas.ver')
            <a href="/admin/matriculas" title="Matrículas" @if(request()->is('admin/matriculas*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/matriculas*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/matriculas*'),
            ])>
                @if(request()->is('admin/matriculas*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-clipboard-check w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/matriculas*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/matriculas*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Matrículas</span>
            </a>
        @endcan

        <p class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Comercial y atención</p>

        @can('servicios.ver')
            <a href="{{ route('admin.servicios.index') }}" title="Servicios tecnológicos" @if(request()->is('admin/servicios*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/servicios*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/servicios*'),
            ])>
                @if(request()->is('admin/servicios*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-network-wired w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/servicios*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/servicios*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Servicios tecnológicos</span>
            </a>
        @endcan

        @if(auth()->user()?->hasRole('admin') && auth()->user()?->can('cotizaciones.ver'))
            <a href="{{ route('admin.cotizaciones.index') }}" title="Solicitudes de cotización" @if(request()->is('admin/cotizaciones*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/cotizaciones*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/cotizaciones*'),
            ])>
                @if(request()->is('admin/cotizaciones*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-file-lines w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/cotizaciones*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/cotizaciones*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Solicitudes de cotización</span>
            </a>
        @endif

        @can('reclamaciones.ver')
            <a href="{{ route('admin.reclamaciones.index') }}" title="Libro de Reclamaciones" @if(request()->is('admin/reclamaciones*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/reclamaciones*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/reclamaciones*'),
            ])>
                @if(request()->is('admin/reclamaciones*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-book-bookmark w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/reclamaciones*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/reclamaciones*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Libro de Reclamaciones</span>
            </a>
        @endcan

        @can('asistente.ver')
            <a href="/admin/asistente" title="Asistente Virtual" @if(request()->is('admin/asistente*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/asistente*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/asistente*'),
            ])>
                @if(request()->is('admin/asistente*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-robot w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/asistente*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/asistente*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Asistente Virtual</span>
            </a>
        @endcan

        <p class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Operación interna</p>

        @can('aprobaciones.ver')
            <a href="{{ route('admin.aprobaciones.index') }}" title="Aprobaciones" @if(request()->is('admin/aprobaciones*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/aprobaciones*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/aprobaciones*'),
            ])>
                @if(request()->is('admin/aprobaciones*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-check-to-slot w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/aprobaciones*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/aprobaciones*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Aprobaciones</span>
            </a>
        @endcan

        <a href="{{ route('admin.notificaciones.index') }}" title="Notificaciones" @if(request()->is('admin/notificaciones*')) aria-current="page" @endif @class([
            'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
            'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/notificaciones*'),
            'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/notificaciones*'),
        ])>
            @if(request()->is('admin/notificaciones*'))
                <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
            @endif
            <i @class([
                'fa-solid fa-bell w-5 shrink-0 text-center transition-colors',
                'text-[#19A7A1]' => request()->is('admin/notificaciones*'),
                'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/notificaciones*'),
            ]) aria-hidden="true"></i>
            <span class="truncate md:hidden xl:inline">Notificaciones</span>
        </a>

        @can('reportes.ver')
            <a href="{{ route('admin.reportes.index') }}" title="Reportes" @if(request()->is('admin/reportes*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/reportes*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/reportes*'),
            ])>
                @if(request()->is('admin/reportes*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-file-export w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/reportes*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/reportes*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Reportes</span>
            </a>
        @endcan

        <a href="{{ route('admin.busqueda.index') }}" title="Buscar" @if(request()->is('admin/buscar*')) aria-current="page" @endif @class([
            'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
            'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/buscar*'),
            'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/buscar*'),
        ])>
            @if(request()->is('admin/buscar*'))
                <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
            @endif
            <i @class([
                'fa-solid fa-magnifying-glass w-5 shrink-0 text-center transition-colors',
                'text-[#19A7A1]' => request()->is('admin/buscar*'),
                'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/buscar*'),
            ]) aria-hidden="true"></i>
            <span class="truncate md:hidden xl:inline">Buscar</span>
        </a>

        <p class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400 md:hidden xl:block">Sistema y seguridad</p>

        @can('usuarios.ver')
            <a href="{{ route('admin.usuarios.index') }}" title="Usuarios" @if(request()->is('admin/usuarios*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/usuarios*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/usuarios*'),
            ])>
                @if(request()->is('admin/usuarios*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-users w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/usuarios*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/usuarios*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Usuarios</span>
            </a>
        @endcan

        @can('roles.ver')
            <a href="{{ route('admin.roles.index') }}" title="Roles" @if(request()->is('admin/roles*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/roles*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/roles*'),
            ])>
                @if(request()->is('admin/roles*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-shield-halved w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/roles*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/roles*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Roles</span>
            </a>
        @endcan

        @can('configuracion.ver')
            <a href="{{ route('admin.configuracion.index') }}" title="Configuración" @if(request()->is('admin/configuracion*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/configuracion*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/configuracion*'),
            ])>
                @if(request()->is('admin/configuracion*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-gear w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/configuracion*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/configuracion*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Configuración</span>
            </a>
        @endcan

        @can('auditoria.ver')
            <a href="{{ route('admin.auditoria.index') }}" title="Auditoría" @if(request()->is('admin/auditoria*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/auditoria*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/auditoria*'),
            ])>
                @if(request()->is('admin/auditoria*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-clipboard-list w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/auditoria*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/auditoria*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Auditoría</span>
            </a>
        @endcan

        @can('respaldo.ver')
            <a href="{{ route('admin.respaldo.index') }}" title="Respaldo" @if(request()->is('admin/respaldo*')) aria-current="page" @endif @class([
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]',
                'bg-gradient-to-r from-[#176B9A] to-[#287FB5] font-semibold text-white shadow-sm shadow-[#0A2540]/40 ring-1 ring-[#19A7A1]/40' => request()->is('admin/respaldo*'),
                'text-slate-300 hover:bg-[#176B9A]/25 hover:text-white' => ! request()->is('admin/respaldo*'),
            ])>
                @if(request()->is('admin/respaldo*'))
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-[#19A7A1]" aria-hidden="true"></span>
                @endif
                <i @class([
                    'fa-solid fa-database w-5 shrink-0 text-center transition-colors',
                    'text-[#19A7A1]' => request()->is('admin/respaldo*'),
                    'text-slate-400 group-hover:text-[#19A7A1]' => ! request()->is('admin/respaldo*'),
                ]) aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Respaldo</span>
            </a>
        @endcan

        <div class="my-4 border-t border-white/10"></div>

        <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer" title="Ver sitio público" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-slate-300 transition hover:bg-[#176B9A]/25 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]">
            <i class="fa-solid fa-arrow-up-right-from-square w-5 shrink-0 text-center text-slate-400 transition-colors group-hover:text-[#19A7A1]" aria-hidden="true"></i>
            <span class="truncate md:hidden xl:inline">Ver sitio público</span>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" title="Cerrar sesión" class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm text-slate-300 transition hover:bg-[#176B9A]/25 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#19A7A1]">
                <i class="fa-solid fa-right-from-bracket w-5 shrink-0 text-center text-slate-400 transition-colors group-hover:text-[#19A7A1]" aria-hidden="true"></i>
                <span class="truncate md:hidden xl:inline">Cerrar sesión</span>
            </button>
        </form>
    </nav>
</aside>
