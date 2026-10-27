@use('App\Support\OldInput')
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('assets/images/index/logos/logo-horus.png') }}">
    <title>Restablecer contraseña | Horus Group SRL</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-100 px-4 py-10 font-sans text-ink">
    <main class="w-full max-w-md">
        <div class="mb-8 text-center">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-3" aria-label="Volver al sitio de Horus Group">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-ink text-xl font-bold text-gold">H</span>
                <span class="text-left">
                    <span class="block text-lg font-bold">Horus Group SRL</span>
                    <span class="block text-sm text-stone-600">Seguridad</span>
                </span>
            </a>
        </div>

        <section class="rounded-3xl border border-gold/20 bg-white p-7 shadow-xl shadow-black/5 sm:p-9">
            <div class="mb-7">
                <p class="mb-2 text-sm font-semibold uppercase tracking-[0.16em] text-gold-dark">Acceso administrativo</p>
                <h1 class="text-2xl font-bold">Establecer nueva contraseña</h1>
                <p class="mt-2 text-sm leading-6 text-stone-600">Elige una contraseña segura para proteger tu cuenta.</p>
            </div>

            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    <p class="font-semibold">No se pudo actualizar la contraseña.</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label for="email" class="mb-2 block text-sm font-semibold">Correo electrónico</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ OldInput::value('email', $email) }}"
                        readonly
                        class="w-full rounded-xl border border-stone-200 bg-stone-50 px-4 py-3 text-stone-700 outline-none"
                    >
                </div>

                <div>
                    <label for="password" class="mb-2 block text-sm font-semibold">Nueva contraseña</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="w-full rounded-xl border border-stone-200 px-4 py-3 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
                        placeholder="Ingrese su nueva contraseña"
                    >
                </div>

                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-semibold">Confirmar contraseña</label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="w-full rounded-xl border border-stone-200 px-4 py-3 outline-none transition focus:border-gold focus:ring-1 focus:ring-gold"
                        placeholder="Repita la contraseña"
                    >
                </div>

                <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-gold px-5 py-3 font-semibold text-ink transition hover:bg-gold-dark">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    Actualizar contraseña
                </button>
            </form>

            <div class="mt-6 text-center text-sm text-stone-600">
                <a href="{{ route('login') }}" class="font-medium text-gold-dark transition hover:text-gold">Volver al inicio de sesión</a>
            </div>
        </section>
    </main>
</body>
</html>
