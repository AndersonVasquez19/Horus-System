{{--
    Plantilla autónoma de las páginas de error. No depende de la base de datos, de la sesión, de rutas con nombre
    ni de los recursos compilados (Vite): debe poder mostrarse incluso cuando el fallo es justamente uno de ellos.
    Estilos en línea (la CSP admite style-src 'unsafe-inline'); sin scripts. Nunca muestra el mensaje técnico de
    la excepción.
--}}
@php
    $enPanel = request()->is('admin') || request()->is('admin/*');
    $destino = $enPanel ? url('/admin') : url('/');
    $textoDestino = $enPanel ? 'Volver al panel' : 'Ir a la página principal';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('titulo') | Horus Group SRL</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: #F4F7FB; color: #172B3A;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; line-height: 1.5;
        }
        main {
            width: 100%; max-width: 520px; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 16px;
            padding: 40px 32px; text-align: center; box-shadow: 0 10px 30px rgba(10, 37, 64, 0.08);
        }
        .marca { margin: 0 0 24px; font-size: 13px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #176B9A; }
        .codigo { margin: 0; font-size: 56px; font-weight: 800; line-height: 1; color: #0A2540; }
        h1 { margin: 16px 0 8px; font-size: 22px; color: #0A2540; }
        p.mensaje { margin: 0 0 28px; color: #5B6B7A; }
        a.boton {
            display: inline-block; padding: 10px 20px; border-radius: 10px; background: #19A7A1; color: #FFFFFF;
            font-weight: 600; text-decoration: none;
        }
        a.boton:hover { background: #148F8A; }
        a.boton:focus-visible { outline: 3px solid #0A2540; outline-offset: 3px; }
    </style>
</head>
<body>
    <main>
        <p class="marca">Horus Group SRL</p>
        <p class="codigo" aria-hidden="true">@yield('codigo')</p>
        <h1>@yield('titulo')</h1>
        <p class="mensaje">@yield('mensaje')</p>
        <a class="boton" href="{{ $destino }}">{{ $textoDestino }}</a>
    </main>
</body>
</html>
