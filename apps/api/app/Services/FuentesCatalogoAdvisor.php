<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Entrada;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Procedencia local del texto editorial exacto; no concede confianza al prefijo cat_. */
class FuentesCatalogoAdvisor
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Etiquetas espejo de Python (`chat_engine.MODALITY_ASKED`), en el orden fijo de `understanding.MODALITY_WORDS`. */
    private const MODALIDADES_PEDIDAS = [
        'presencial' => 'presencial',
        'semipresencial' => 'semipresencial',
        'virtual' => 'virtual u online',
    ];

    /** @return list<list<'presencial'|'semipresencial'|'virtual'>> */
    private static function combinacionesModalidad(): array
    {
        return [
            ['presencial'],
            ['semipresencial'],
            ['virtual'],
            ['presencial', 'semipresencial'],
            ['presencial', 'virtual'],
            ['semipresencial', 'virtual'],
            ['presencial', 'semipresencial', 'virtual'],
        ];
    }

    /** «5 de diciembre de 2026»: la misma frase que compone Python (app/services/catalog.py, long_date). */
    public static function fechaLarga(string $iso): string
    {
        $fecha = Carbon::createFromFormat('!Y-m-d', substr($iso, 0, 10));
        if ($fecha === null) {
            throw new \InvalidArgumentException('Fecha de inicio inválida.');
        }

        return $fecha->day.' de '.self::MESES[$fecha->month - 1].' de '.$fecha->year;
    }

    /** Certificación sin punto final (Python: certification_line). */
    public static function lineaCertificacion(string $texto): string
    {
        return rtrim(trim($texto), '.');
    }

    /** @return array<string, array{title: string, url_path: string|null, answer: string, fragments?: list<string>}> */
    public function actuales(): array
    {
        if (! config('horus-catalog.sync_enabled', false)) {
            return [];
        }
        try {
            $esperada = Cache::get(Entrada::texto(config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version')));
            if (! is_string($esperada)) {
                return [];
            }
            $catalogo = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
            if (! hash_equals($esperada, $catalogo['catalog_version'])
                || ! app(FirmaCatalogoAdvisor::class)->verificar($catalogo)
                || Carbon::parse($catalogo['expires_at'])->isPast()) {
                return [];
            }
            $fuentes = [];
            $cursos = [];
            $detallesCursos = [];
            $servicios = [];
            foreach ($catalogo['items'] as $item) {
                if ($item['publication_status'] !== 'vigente' || ! in_array($item['type'], ['curso', 'servicio'], true)) {
                    continue;
                }
                $id = rtrim(substr('cat_'.preg_replace('/[^a-z0-9]+/', '_', $item['stable_id']), 0, 64), '_');
                $partes = [$item['title'].': '.$item['summary']];
                $esHibrido = empty($item['modalities']) && str_contains(strtolower((string) ($item['details'] ?? '')), 'modalidad: hibrido.');
                if (! empty($item['modalities'])) {
                    $partes[] = 'Modalidad: '.implode(', ', $item['modalities']).'.';
                } elseif ($esHibrido) {
                    $partes[] = 'Modalidad: híbrido.';
                }
                $inicio = empty($item['start_date']) ? null : self::fechaLarga($item['start_date']);
                $certificacion = empty($item['certification_text']) ? null : self::lineaCertificacion($item['certification_text']);
                if (! empty($item['duration_text'])) {
                    $partes[] = 'Duración: '.$item['duration_text'].'.';
                }
                if ($inicio !== null) {
                    $partes[] = 'Inicio: '.$inicio.'.';
                }
                if (! empty($item['schedule_text'])) {
                    $partes[] = 'Horario: '.$item['schedule_text'].'.';
                }
                if (! empty($item['requirements'])) {
                    $partes[] = 'Requisitos: '.implode('; ', $item['requirements']).'.';
                }
                if ($certificacion !== null) {
                    $partes[] = 'Certificación: '.$certificacion.'.';
                }
                // El exportador autorizado actual no publica precios individuales.
                if (! empty($item['public_price'])) {
                    return [];
                }
                if (! empty($item['inquiry'])) {
                    $partes[] = 'Consultas: '.$item['inquiry'].'.';
                }
                $respuestaDetallada = mb_substr(implode(' ', $partes), 0, 1500);
                $fuentes[$id] = ['title' => $item['title'], 'url_path' => $item['url_path'] ?? null,
                    'answer' => $respuestaDetallada,
                    'fragments' => [$item['title'].': '.$item['summary'], ...array_map(static fn (string $texto): string => $item['title'].': '.$texto,
                        FragmentosCatalogoAdvisor::textos(($item['details'] ?? '') ?: $item['summary']))]];
                if (! empty($item['duration_text'])) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: dura '.$item['duration_text'].'.';
                } else {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: la duración la confirma un asesor.';
                }
                if (! empty($item['schedule_text'])) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: el horario es '.$item['schedule_text'].'.';
                }
                // Frases de seguimiento de Python (chat_engine._attribute_line), carácter por carácter.
                if ($inicio !== null) {
                    $fuentes[$id]['fragments'][] = empty($item['schedule_text'])
                        ? '«'.$item['title'].'»: inicia el '.$inicio.'.'
                        : '«'.$item['title'].'»: inicia el '.$inicio.'; el horario es '.$item['schedule_text'].'.';
                } elseif (empty($item['schedule_text'])) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: las fechas y horarios los confirma un asesor.';
                } else {
                    // Horario publicado sin fecha de inicio: Python lo dice en la misma frase.
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: el horario es '.$item['schedule_text'].'; la fecha de inicio la confirma un asesor.';
                }
                if ($certificacion !== null) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: certificación: '.$certificacion.'.';
                } else {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: si incluye certificado lo confirma un asesor.';
                }
                if (! empty($item['modalities'])) {
                    $publicadas = implode(', ', $item['modalities']);
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: es '.$publicadas.'.';
                    $ofrecidas = array_map(static fn (string $m): string => $m === 'online' ? 'virtual' : $m, $item['modalities']);
                    foreach (self::combinacionesModalidad() as $combo) {
                        $etiquetas = array_map(
                            static fn (string $m): string => (in_array($m, $ofrecidas, true) ? 'sí es ' : 'no es ').self::MODALIDADES_PEDIDAS[$m],
                            $combo,
                        );
                        $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: '.implode('; ', $etiquetas).' (modalidad: '.$publicadas.').';
                    }
                } elseif ($esHibrido) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: es híbrido.';
                    foreach (self::combinacionesModalidad() as $combo) {
                        $etiquetas = array_map(
                            static fn (string $m): string => ($m === 'semipresencial' ? 'sí es ' : 'no es ').self::MODALIDADES_PEDIDAS[$m],
                            $combo,
                        );
                        $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: '.implode('; ', $etiquetas).' (modalidad: híbrido).';
                    }
                } else {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: la modalidad la confirma un asesor.';
                }
                if (! empty($item['requirements'])) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: requisitos: '.implode('; ', $item['requirements']).'.';
                } else {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: los requisitos los confirma un asesor.';
                }
                $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: el precio lo confirma un asesor.';
                $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: los cupos los confirma un asesor.';
                $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: el temario lo confirma un asesor.';
                $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: el plazo de atención lo confirma un asesor.';
                $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: el público al que se dirige lo confirma un asesor.';
                if ($item['type'] === 'curso') {
                    $cursos[] = $item['title'];
                    $detallesCursos[] = $respuestaDetallada;
                } else {
                    $servicios[] = $item['title'];
                }
            }
            if ($cursos !== []) {
                $titulos = implode('; ', array_slice($cursos, 0, 15));
                $listadoTexto = mb_substr('Cursos y capacitaciones vigentes: '.$titulos.'. Pregúntame por cualquiera para ver su modalidad o requisitos.', 0, 1500);
                $listadoDetallado = mb_substr(implode("\n", array_slice($detallesCursos, 0, 10)), 0, 1500);
                $fuentes['cat_listado_formacion'] = ['title' => 'Cursos y capacitaciones vigentes', 'url_path' => '/educacion/cursos',
                    'answer' => $listadoTexto,
                    'fragments' => [
                        $listadoTexto,
                        $listadoDetallado,
                    ]];
            }
            if ($servicios !== []) {
                $titulos = implode('; ', array_slice($servicios, 0, 15));
                $fuentes['cat_listado_servicios'] = ['title' => 'Servicios tecnológicos vigentes', 'url_path' => null,
                    'answer' => mb_substr('Según el catálogo publicado por Horus Group, están vigentes: '.$titulos.'. Pregúntame por cualquiera para ver su contenido publicado.', 0, 1500)];
            }

            return $fuentes;
        } catch (Throwable) {
            return [];
        }
    }
}
