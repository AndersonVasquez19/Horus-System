<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel de Control') | Horus Group SRL</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-100 font-sans text-ink antialiased">
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
            class="fixed inset-0 z-30 bg-black/50 md:hidden"
            aria-hidden="true"
        ></div>

        @include('admin.partials.sidebar')

        <div class="min-h-screen md:pl-[72px] xl:pl-[260px]">
            @include('admin.partials.topbar')
            <main class="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">
                @yield('content')
            </main>
        </div>

        @include('admin.partials.toast')
        @include('admin.partials.confirm-modal')
    </div>
</body>
</html>
