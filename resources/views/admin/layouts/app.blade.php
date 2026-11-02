<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('assets/images/index/logos/logo-horus.png') }}">
    <title>@yield('title', 'Panel de Control') | Horus Group SRL</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
                @yield('content')
            </main>
        </div>

        @include('admin.partials.toast')
        @include('admin.partials.confirm-modal')
    </div>
</body>
</html>
