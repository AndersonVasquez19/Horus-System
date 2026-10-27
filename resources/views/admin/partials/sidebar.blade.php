<aside
    class="fixed inset-y-0 left-0 z-40 flex w-[260px] -translate-x-full flex-col bg-ink text-stone-300 transition-transform duration-200 md:w-[72px] md:translate-x-0 xl:w-[260px]"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
    aria-label="Navegación administrativa"
>
    <a href="/admin" class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gold font-bold text-ink">H</span>
        <span class="hidden min-w-0 xl:block">
            <span class="block truncate text-sm font-bold text-white">Horus Group</span>
            <span class="block text-xs text-stone-400">Administración</span>
        </span>
    </a>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-5">
        <a href="/admin" title="Panel de Control" @class([
            'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
            'bg-gold font-semibold text-ink' => request()->is('admin'),
            'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin'),
        ])>
            <i class="fa-solid fa-chart-line w-5 text-center" aria-hidden="true"></i>
            <span class="hidden xl:inline">Panel de Control</span>
        </a>

        @can('estudiantes.ver')
            <a href="/admin/estudiantes" title="Estudiantes" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/estudiantes*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/estudiantes*'),
            ])>
                <i class="fa-solid fa-user-graduate w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Estudiantes</span>
            </a>
        @endcan

        @can('cursos.ver')
            <a href="/admin/cursos" title="Cursos" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/cursos*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/cursos*'),
            ])>
                <i class="fa-solid fa-book-open w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Cursos</span>
            </a>
        @endcan

        @can('matriculas.ver')
            <a href="/admin/matriculas" title="Matrículas" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/matriculas*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/matriculas*'),
            ])>
                <i class="fa-solid fa-clipboard-check w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Matrículas</span>
            </a>
        @endcan

        @can('asistente.ver')
            <a href="/admin/asistente" title="Asistente Virtual" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/asistente*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/asistente*'),
            ])>
                <i class="fa-solid fa-robot w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Asistente Virtual</span>
            </a>
        @endcan

        @can('procesos.ver')
            <a href="{{ route('admin.procesos.index') }}" title="Procesos internos" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/procesos*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/procesos*'),
            ])>
                <i class="fa-solid fa-diagram-project w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Procesos</span>
            </a>
        @endcan

        @can('aprobaciones.ver')
            <a href="{{ route('admin.aprobaciones.index') }}" title="Aprobaciones" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/aprobaciones*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/aprobaciones*'),
            ])>
                <i class="fa-solid fa-check-to-slot w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Aprobaciones</span>
            </a>
        @endcan

        <a href="{{ route('admin.notificaciones.index') }}" title="Notificaciones" @class([
            'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
            'bg-gold font-semibold text-ink' => request()->is('admin/notificaciones*'),
            'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/notificaciones*'),
        ])>
            <i class="fa-solid fa-bell w-5 text-center" aria-hidden="true"></i>
            <span class="hidden xl:inline">Notificaciones</span>
        </a>

        @can('usuarios.ver')
            <a href="{{ route('admin.usuarios.index') }}" title="Usuarios" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/usuarios*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/usuarios*'),
            ])>
                <i class="fa-solid fa-users w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Usuarios</span>
            </a>
        @endcan

        @can('roles.ver')
            <a href="{{ route('admin.roles.index') }}" title="Roles" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/roles*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/roles*'),
            ])>
                <i class="fa-solid fa-shield-halved w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Roles</span>
            </a>
        @endcan

        @can('reportes.ver')
            <a href="{{ route('admin.reportes.index') }}" title="Reportes" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/reportes*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/reportes*'),
            ])>
                <i class="fa-solid fa-file-export w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Reportes</span>
            </a>
        @endcan

        @can('configuracion.ver')
            <a href="{{ route('admin.configuracion.index') }}" title="Configuración" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/configuracion*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/configuracion*'),
            ])>
                <i class="fa-solid fa-gear w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Configuración</span>
            </a>
        @endcan

        @can('auditoria.ver')
            <a href="{{ route('admin.auditoria.index') }}" title="Auditoría" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/auditoria*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/auditoria*'),
            ])>
                <i class="fa-solid fa-shield-halved w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Auditoría</span>
            </a>
        @endcan

        @can('respaldo.ver')
            <a href="{{ route('admin.respaldo.index') }}" title="Respaldo" @class([
                'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
                'bg-gold font-semibold text-ink' => request()->is('admin/respaldo*'),
                'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/respaldo*'),
            ])>
                <i class="fa-solid fa-database w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Respaldo</span>
            </a>
        @endcan

        <a href="{{ route('admin.busqueda.index') }}" title="Buscar" @class([
            'flex items-center gap-3 rounded-xl px-3 py-3 text-sm transition',
            'bg-gold font-semibold text-ink' => request()->is('admin/buscar*'),
            'text-stone-300 hover:bg-white/10 hover:text-white' => ! request()->is('admin/buscar*'),
        ])>
            <i class="fa-solid fa-magnifying-glass w-5 text-center" aria-hidden="true"></i>
            <span class="hidden xl:inline">Buscar</span>
        </a>

        <div class="my-5 border-t border-white/10"></div>

        <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer" title="Ver sitio público" class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm text-stone-300 transition hover:bg-white/10 hover:text-white">
            <i class="fa-solid fa-arrow-up-right-from-square w-5 text-center" aria-hidden="true"></i>
            <span class="hidden xl:inline">Ver sitio público</span>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" title="Cerrar sesión" class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm text-stone-300 transition hover:bg-white/10 hover:text-white">
                <i class="fa-solid fa-right-from-bracket w-5 text-center" aria-hidden="true"></i>
                <span class="hidden xl:inline">Cerrar sesión</span>
            </button>
        </form>
    </nav>
</aside>
