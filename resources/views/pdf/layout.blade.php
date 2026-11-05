<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>@yield('title', 'Documento Institucional &middot; Horus Group SRL')</title>
    <style>
        /* ==========================================================================
           Configuración de Página y Márgenes (DomPDF)
           ========================================================================== */
        @page {
            margin: 18mm 14mm 16mm 14mm;
            size: @yield('page_size', 'a4 portrait');
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8.5pt;
            line-height: 1.4;
            color: #172B3A;
            background-color: #FFFFFF;
            margin: 0;
            padding: 0;
        }

        /* ==========================================================================
           Encabezado Institucional
           ========================================================================== */
        .header-container {
            text-align: center;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 2.5px solid #176B9A;
        }

        .header-logo {
            max-height: 52px;
            width: auto;
            margin: 0 auto 6px auto;
            display: block;
        }

        .header-institution {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 1.5px;
            color: #0A2540;
            margin: 0 0 3px 0;
            text-transform: uppercase;
        }

        .header-title {
            font-size: 14.5pt;
            font-weight: bold;
            color: #176B9A;
            margin: 0 0 3px 0;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .header-subtitle {
            font-size: 8.5pt;
            color: #64748B;
            margin: 0;
            font-weight: normal;
        }

        .header-accent-bar {
            height: 2px;
            background-color: #19A7A1;
            margin-top: 6px;
            width: 80px;
            margin-left: auto;
            margin-right: auto;
        }

        /* ==========================================================================
           Bloque de Información y Metadatos
           ========================================================================== */
        .meta-card {
            background-color: #F4F7FB;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 8pt;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table td {
            padding: 3px 6px;
            vertical-align: top;
            font-size: 8pt;
            border: none;
            line-height: 1.35;
        }

        .meta-label {
            color: #64748B;
            font-size: 7.5pt;
            font-weight: normal;
            display: block;
            margin-bottom: 1px;
        }

        .meta-value {
            color: #172B3A;
            font-size: 8pt;
            font-weight: 600;
        }

        .meta-notice {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px dashed #CBD5E1;
            color: #854D0E;
            font-size: 7.5pt;
            font-weight: 600;
            line-height: 1.3;
        }

        .order-note {
            font-size: 7.5pt;
            color: #64748B;
            margin: 4px 0 8px 0;
        }

        /* ==========================================================================
           Tablas de Datos
           ========================================================================== */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            font-size: 8pt;
        }

        .data-table thead {
            display: table-header-group;
        }

        .data-table tr {
            page-break-inside: avoid;
        }

        .data-table th {
            background-color: #0A2540;
            color: #FFFFFF;
            font-weight: 600;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 7px 8px;
            border-top: 1px solid #0A2540;
            border-bottom: 1px solid #0A2540;
            border-left: none;
            border-right: none;
        }

        .data-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #E2E8F0;
            border-top: none;
            border-left: none;
            border-right: none;
            color: #172B3A;
            vertical-align: middle;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .data-table tbody tr:nth-child(even) {
            background-color: #F8FAFC;
        }

        .data-table tbody tr:nth-child(odd) {
            background-color: #FFFFFF;
        }

        .data-table-empty {
            text-align: center;
            padding: 18px 12px;
            color: #64748B;
            font-style: italic;
        }

        /* ==========================================================================
           Badges y Pastillas de Estado
           ========================================================================== */
        .badge {
            display: inline-block;
            padding: 2px 7px;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-radius: 4px;
            text-align: center;
            white-space: nowrap;
        }

        .badge-success {
            background-color: #DCFCE7;
            color: #15803D;
            border: 1px solid #BBF7D0;
        }

        .badge-warning {
            background-color: #FEF9C3;
            color: #854D0E;
            border: 1px solid #FEF08A;
        }

        .badge-danger {
            background-color: #FEE2E2;
            color: #B91C1C;
            border: 1px solid #FECACA;
        }

        .badge-neutral {
            background-color: #F1F5F9;
            color: #475569;
            border: 1px solid #E2E8F0;
        }

        /* ==========================================================================
           Utilidades de Alineación y Texto
           ========================================================================== */
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .font-semibold { font-weight: 600; }
        .font-bold { font-weight: bold; }
        .text-muted { color: #64748B; }
        .text-dark { color: #172B3A; }

        /* ==========================================================================
           Pie de Página Fijo (Todas las páginas)
           ========================================================================== */
        .pdf-footer {
            position: fixed;
            bottom: -11mm;
            left: 0;
            right: 0;
            height: 7mm;
            border-top: 1px solid #E2E8F0;
            padding-top: 3px;
            font-size: 7pt;
            color: #64748B;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .footer-table td {
            padding: 0;
            border: none;
            font-size: 7pt;
            color: #64748B;
            vertical-align: middle;
        }

        .page-number:after {
            content: counter(page);
        }

        @yield('extra_css')
    </style>
</head>
<body>

    {{-- Pie de página institucional visible en todas las páginas --}}
    @include('pdf.footer')

    {{-- Contenido principal del documento --}}
    <main>
        @yield('content')
    </main>

</body>
</html>
