<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('assets/images/index/logos/logo-horus.png') }}">
    <title>@yield('title', 'Panel de Control') | Horus Group SRL</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <noscript><style>
        dialog[data-admin-modal] { display: block; position: static; max-height: none; margin: 1rem auto; }
        [data-admin-modal-close], [data-admin-modal-open] { display: none; }
    </style></noscript>
</head>
<body class="admin-surface min-h-screen overflow-x-hidden font-sans text-[#172B3A] antialiased">
    <div
        x-data="{ sidebarOpen: false }"
        @keydown.escape.window="sidebarOpen = false"
        class="min-h-screen"
    >
        <div
            x-cloak
            x-show="sidebarOpen"
            x-transition.opacity
            @click="sidebarOpen = false"
            class="fixed inset-0 z-30 bg-[#0A2540]/60 backdrop-blur-[2px] md:hidden"
            aria-hidden="true"
        ></div>

        @include('admin.partials.sidebar')

        <div class="flex min-h-screen min-w-0 flex-col md:pl-[72px] xl:pl-[260px]">
            @include('admin.partials.topbar')
            <main id="admin-main-content" class="mx-auto w-full min-w-0 max-w-[1600px] flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                @php
                    $rutaFormulario = request()->routeIs(
                        'admin.estudiantes.create', 'admin.estudiantes.edit',
                        'admin.cursos.create', 'admin.cursos.edit', 'admin.cursos.publicacion.edit',
                        'admin.matriculas.create', 'admin.matriculas.edit',
                        'admin.usuarios.create', 'admin.usuarios.edit', 'admin.configuracion.index',
                    );
                    $moduloFormulario = explode('.', request()->route()?->getName() ?? '')[1] ?? '';
                    $volverFormulario = $rutaFormulario
                        ? route($moduloFormulario === 'configuracion' ? 'admin.dashboard' : 'admin.'.$moduloFormulario.'.index')
                        : null;
                @endphp
                @if ($rutaFormulario)
                    <div class="admin-page-header">
                        <h1 class="admin-page-title">@yield('title')</h1>
                        <a href="{{ $volverFormulario }}" class="admin-btn-outline">Volver al panel</a>
                    </div>
                    <dialog data-admin-modal data-admin-page-form data-auto-open data-return-url="{{ $volverFormulario }}" aria-label="@yield('title')" class="admin-form-modal">
                        <div class="admin-modal-heading">
                            <p class="admin-eyebrow">Formulario de gestión</p>
                            <button type="button" data-admin-modal-close class="admin-icon-btn" aria-label="Cerrar formulario">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="admin-modal-body">@yield('content')</div>
                    </dialog>
                @else
                    @yield('content')
                @endif
            </main>
        </div>

        @include('admin.partials.toast')
        @include('admin.partials.confirm-modal')
    </div>
</body>
</html>
