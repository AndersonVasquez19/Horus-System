{{--
    Hoja de reclamación imprimible (E3). El texto oficial es el de App\Support\HojaReclamacion::cuerpo(), el mismo
    que se guarda como copia al consumidor: los datos del proveedor ausentes figuran como «no configurado» y no se
    inventan. Todo se imprime con escape normal de Blade. Página independiente: sin recursos externos.
    Impresión: A4 con @page; desde la segunda página, el número de expediente se repite en el margen superior
    (cajas de margen de @page) y todas las páginas llevan «Página X de Y».
--}}
@php
    // Solo dígitos, letras y guion (formato NNNNNNNNN-AAAA): se inserta dentro de una cadena CSS.
    $numeroCss = preg_replace('/[^0-9A-Za-z-]/', '', (string) $reclamacion->numero);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Hoja de reclamación {{ $reclamacion->numero }}</title>
    <style>
        :root { --marino: #0A2540; --azul: #176B9A; --azul2: #287FB5; --turquesa: #19A7A1; --fondo: #F4F7FB; --texto: #172B3A; --borde: #E2E8F0; --gris: #64748B; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--fondo); color: var(--texto); font-family: "Segoe UI", Roboto, Arial, sans-serif; line-height: 1.5; }
        .barra { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; max-width: 820px; margin: 24px auto 0; padding: 0 16px; }
        .barra a, .barra button { font: inherit; font-size: 14px; font-weight: 600; border-radius: 10px; padding: 8px 14px; cursor: pointer; text-decoration: none; }
        .barra a { color: var(--azul); border: 1px solid var(--borde); background: #fff; }
        .barra button { color: #fff; border: 0; background: linear-gradient(90deg, var(--azul), var(--azul2)); }
        .hoja { max-width: 820px; margin: 16px auto 32px; background: #fff; border: 1px solid var(--borde); border-radius: 14px; overflow: hidden; }
        .cabecera { background: var(--marino); color: #fff; padding: 20px 28px; border-bottom: 4px solid var(--turquesa); }
        .cabecera h1 { margin: 0; font-size: 20px; letter-spacing: .02em; }
        .cabecera p { margin: 4px 0 0; font-size: 13px; color: #CBD5E1; }
        .cuerpo { padding: 24px 28px; }
        .texto { white-space: pre-wrap; word-break: break-word; font-family: inherit; font-size: 14px; margin: 0; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--azul); border-bottom: 1px solid var(--borde); padding-bottom: 6px; margin: 24px 0 10px; }
        .meta { font-size: 12px; color: var(--gris); }
        .pie { border-top: 1px solid var(--borde); padding: 14px 28px; font-size: 11px; color: var(--gris); }
        @page {
            size: A4;
            margin: 16mm 14mm 16mm 14mm;
            @top-right { content: "Hoja de Reclamación N.º {{ $numeroCss }}"; font: 600 9pt "Segoe UI", Roboto, Arial, sans-serif; color: #0A2540; }
            @bottom-right { content: "Página " counter(page) " de " counter(pages); font: 8pt "Segoe UI", Roboto, Arial, sans-serif; color: #64748B; }
        }
        /* En la primera página el número ya figura en la cabecera de la hoja. */
        @page :first { @top-right { content: none; } }
        @media print {
            body { background: #fff; }
            .barra { display: none; }
            .hoja { margin: 0; max-width: none; border: 0; border-radius: 0; }
            .cabecera { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <nav class="barra" aria-label="Acciones de la hoja">
        <a href="{{ route('admin.reclamaciones.show', $reclamacion) }}">&larr; Volver al detalle</a>
        <button type="button" data-imprimir>Imprimir</button>
    </nav>

    <main class="hoja">
        <header class="cabecera">
            <h1>Hoja de Reclamación N.º {{ $reclamacion->numero }}</h1>
            <p>{{ $reclamacion->tipo === 'reclamo' ? 'Reclamo' : 'Queja' }} · {{ $reclamacion->canal === 'respaldo' ? 'Libro físico de respaldo' . ($reclamacion->numero_hoja_respaldo ? ' (hoja ' . $reclamacion->numero_hoja_respaldo . ')' : '') : 'Libro virtual' }}</p>
        </header>

        <section class="cuerpo">
            <pre class="texto" data-hoja-oficial>{{ $hoja }}</pre>

            <h2>4. Observaciones y acciones adoptadas por el proveedor</h2>
            @if ($reclamacion->respuesta)
                <p class="meta">Fecha de comunicación de la respuesta: {{ $reclamacion->respondida_at?->timezone('America/Lima')->format('d/m/Y H:i') ?? '—' }} (Lima)</p>
                <pre class="texto">{{ $reclamacion->respuesta }}</pre>
            @else
                <p class="texto">Pendiente de respuesta.</p>
            @endif
        </section>

        <footer class="pie">
            Impreso desde el panel administrativo el {{ now('America/Lima')->format('d/m/Y H:i') }} (Lima). Documento con datos personales: uso exclusivo para la atención de la reclamación.
        </footer>
    </main>
    <script src="{{ asset('assets/js/hoja-imprimir.js') }}" defer></script>
</body>
</html>
