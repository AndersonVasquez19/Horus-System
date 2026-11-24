<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Certificado {{ $certificado->codigo }} &middot; {{ $empresa }}</title>
    <style>
        @page { margin: 18px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 0;
        }
        .marco-exterior {
            border: 4px solid #0b2447;
            padding: 10px;
            min-height: 660px;
            box-sizing: border-box;
            position: relative;
        }
        .marco-interior {
            border: 1.5px solid #c9a227;
            padding: 34px 44px 28px;
            min-height: 630px;
            box-sizing: border-box;
            position: relative;
        }
        .sello-anulado {
            position: absolute;
            top: 42%;
            left: 24%;
            font-size: 64px;
            font-weight: bold;
            color: rgba(185, 28, 28, 0.16);
            border: 6px solid rgba(185, 28, 28, 0.22);
            padding: 8px 36px;
            letter-spacing: 8px;
            transform: rotate(-18deg);
        }
        .encabezado {
            text-align: center;
            margin-bottom: 20px;
        }
        .institucion {
            font-size: 13px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #1e3a8a;
            font-weight: bold;
        }
        .titulo {
            font-size: 34px;
            letter-spacing: 5px;
            text-transform: uppercase;
            color: #0b2447;
            margin: 10px 0 4px;
            font-weight: bold;
        }
        .linea-dorada {
            width: 140px;
            height: 3px;
            background: #c9a227;
            margin: 8px auto 0;
        }
        .cuerpo {
            text-align: center;
            margin-top: 22px;
        }
        .otorga {
            font-size: 13px;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .estudiante {
            font-size: 28px;
            font-weight: bold;
            color: #0b2447;
            margin: 14px 0 6px;
            border-bottom: 1px solid #cbd5e1;
            display: inline-block;
            padding: 0 28px 6px;
        }
        .dni {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 18px;
        }
        .descripcion {
            font-size: 13.5px;
            line-height: 1.65;
            color: #1e293b;
            max-width: 780px;
            margin: 0 auto;
        }
        .curso-nombre {
            font-size: 20px;
            font-weight: bold;
            color: #1e3a8a;
            margin: 10px 0;
        }
        .detalles {
            font-size: 11.5px;
            color: #475569;
            margin-top: 10px;
        }
        .pie {
            width: 100%;
            margin-top: 44px;
            border-collapse: collapse;
        }
        .pie td {
            vertical-align: bottom;
        }
        .firma-linea {
            border-top: 1px solid #334155;
            width: 240px;
            margin: 0 auto 6px;
        }
        .firma-cargo {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
        }
        .firma-sub {
            font-size: 10px;
            color: #64748b;
        }
        .verificacion {
            font-size: 9.5px;
            color: #475569;
            line-height: 1.5;
        }
        .codigo-badge {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 11px;
            font-weight: bold;
            color: #0b2447;
        }
    </style>
</head>
<body>
@php
    $est = $certificado->estudiante;
    $curso = $certificado->curso;
    $conv = $certificado->convocatoria;
    $nombreCompleto = $est ? trim($est->nombres.' '.$est->apellidos) : 'Estudiante';
    $anulado = $certificado->estado === \App\Enums\EstadoCertificado::Anulado;
@endphp
<div class="marco-exterior">
    <div class="marco-interior">
        @if ($anulado)
            <div class="sello-anulado">ANULADO</div>
        @endif

        <div class="encabezado">
            <div class="institucion">{{ $empresa }}</div>
            <div class="titulo">Certificado</div>
            <div class="linea-dorada"></div>
        </div>

        <div class="cuerpo">
            <div class="otorga">Otorgado a</div>
            <div>
                <span class="estudiante">{{ $nombreCompleto }}</span>
            </div>
            <div class="dni">DNI: {{ $est?->dni ?? '—' }}</div>

            <div class="descripcion">
                Por haber culminado satisfactoriamente el curso de especialización:
            </div>
            <div class="curso-nombre">{{ $curso?->nombre ?? 'Curso institucional' }}</div>

            <div class="detalles">
                @if ($curso?->duracion_horas)
                    Duración académica: <strong>{{ $curso->duracion_horas }} horas</strong>
                @endif
                @if ($conv?->fecha_inicio && $conv?->fecha_fin)
                    &nbsp;&middot;&nbsp; Edición <strong>{{ $conv->codigo }}</strong>
                    ({{ $conv->fecha_inicio->format('d/m/Y') }} al {{ $conv->fecha_fin->format('d/m/Y') }})
                @endif
                @if ($certificado->nota_final !== null)
                    &nbsp;&middot;&nbsp; Nota final: <strong>{{ number_format((float) $certificado->nota_final, 2) }}</strong>
                @endif
                @if ($certificado->porcentaje_asistencia !== null)
                    &nbsp;&middot;&nbsp; Asistencia: <strong>{{ number_format((float) $certificado->porcentaje_asistencia, 1) }}%</strong>
                @endif
            </div>
        </div>

        <table class="pie">
            <tr>
                <td style="width: 34%; text-align: left;">
                    <div class="verificacion">
                        <div>Código de verificación:</div>
                        <div class="codigo-badge">{{ $certificado->codigo }}</div>
                        <div>Fecha de emisión: {{ $certificado->emitido_en->format('d/m/Y') }}</div>
                        @if ($anulado)
                            <div style="color: #b91c1c; font-weight: bold;">Documento sin validez (anulado)</div>
                        @endif
                    </div>
                </td>
                <td style="width: 44%; text-align: center;">
                    <div class="firma-linea"></div>
                    <div class="firma-cargo">Dirección Académica</div>
                    <div class="firma-sub">{{ $empresa }}</div>
                </td>
                <td style="width: 22%; text-align: right;">
                    <img src="{{ $qrDataUri }}" alt="QR Certificado" style="width: 78px; height: 78px;" />
                </td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
