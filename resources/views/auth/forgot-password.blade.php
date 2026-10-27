<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Recuperar contraseña | Horus Group SRL</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-100 px-4 py-10 font-sans text-ink">
    <main class="w-full max-w-md">
        <div class="mb-8 text-center">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-3" aria-label="Volver al sitio de Horus Group">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-ink text-xl font-bold text-gold">H</span>
                <span class="text-left">
                    <span class="block text-lg font-bold">Horus Group SRL</span>
                    <span class="block text-sm text-stone-600">Recuperación de acceso</span>
                </span>
            </a>
        </div>

        <section class="rounded-3xl border border-gold/20 bg-white p-7 shadow-xl shadow-black/5 sm:p-9">
            <div class="mb-7">
                <p class="mb-2 text-sm font-semibold uppercase tracking-[0.16em] text-gold-dark">Acceso administrativo</p>
                <h1 class="text-2xl font-bold">Recuperar contraseña</h1>
                <p class="mt-2 text-sm leading-6 text-stone-600">Ingresa tu correo para recibir un enlace seguro de restablecimiento.</p>
            </div>

            @if (session('status'))
                <div class="mb-5 rounded-xl border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    <p class="font-semibold">No pudimos procesar la solicitud.</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email" class="mb-2 block text-sm font-semibold">Correo electrónico</label>
                    <div class="relative">
                        <i class="fa-regular fa-envelope pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-stone-400" aria-hidden="true"></i>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            required
                            autofocus
                            class="w-full rounded-xl border border-stone-200 py-3 pl-11 pr-4 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
                            placeholder="nombre@horusgroup.com"
                        >
                    </div>
                </div>

                <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-gold px-5 py-3 font-semibold text-ink transition hover:bg-gold-dark">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                    Enviar enlace
                </button>
            </form>

            <div class="mt-6 flex items-center justify-between gap-4 text-sm text-stone-600">
                <a href="{{ route('login') }}" class="inline-flex items-center gap-2 font-medium transition hover:text-ink">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Volver al login
                </a>
                <a href="{{ route('home') }}" class="font-medium transition hover:text-ink">Inicio</a>
            </div>
        </section>
    </main>
</body>
</html>
