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
            $servicios = [];
            foreach ($catalogo['items'] as $item) {
                if ($item['publication_status'] !== 'vigente' || ! in_array($item['type'], ['curso', 'servicio'], true)) {
                    continue;
                }
                $id = rtrim(substr('cat_'.preg_replace('/[^a-z0-9]+/', '_', $item['stable_id']), 0, 64), '_');
                $partes = [$item['title'].': '.$item['summary']];
                if (! empty($item['modalities'])) {
                    $partes[] = 'Modalidad: '.implode(', ', $item['modalities']).'.';
                }
                if (! empty($item['duration_text'])) {
                    $partes[] = 'Duración: '.$item['duration_text'].'.';
                }
                if (! empty($item['schedule_text'])) {
                    $partes[] = 'Horario: '.$item['schedule_text'].'.';
                }
                if (! empty($item['requirements'])) {
                    $partes[] = 'Requisitos: '.implode('; ', $item['requirements']).'.';
                }
                // El exportador autorizado actual no publica precios individuales.
                if (! empty($item['public_price'])) {
                    return [];
                }
                if (! empty($item['inquiry'])) {
                    $partes[] = 'Consultas: '.$item['inquiry'].'.';
                }
                $fuentes[$id] = ['title' => $item['title'], 'url_path' => $item['url_path'] ?? null,
                    'answer' => mb_substr(implode(' ', $partes), 0, 1500),
                    'fragments' => [$item['title'].': '.$item['summary'], ...array_map(static fn (string $texto): string => $item['title'].': '.$texto,
                        FragmentosCatalogoAdvisor::textos(($item['details'] ?? '') ?: $item['summary']))]];
                if (! empty($item['duration_text'])) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: dura '.$item['duration_text'].'.';
                }
                if (! empty($item['schedule_text'])) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: el horario es '.$item['schedule_text'].'.';
                }
                if (! empty($item['modalities'])) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: es '.implode(', ', $item['modalities']).'.';
                }
                if (! empty($item['requirements'])) {
                    $fuentes[$id]['fragments'][] = '«'.$item['title'].'»: requisitos: '.implode('; ', $item['requirements']).'.';
                }
                if ($item['type'] === 'curso') {
                    $cursos[] = $item['title'];
                } else {
                    $servicios[] = $item['title'];
                }
            }
            if ($cursos !== []) {
                $titulos = implode('; ', array_slice($cursos, 0, 15));
                $fuentes['cat_listado_formacion'] = ['title' => 'Cursos y capacitaciones vigentes', 'url_path' => '/educacion/cursos',
                    'answer' => mb_substr('Cursos y capacitaciones vigentes: '.$titulos.'. Pregúntame por cualquiera para ver su modalidad o requisitos.', 0, 1500),
                    'fragments' => [
                        mb_substr('Cursos y capacitaciones vigentes: '.$titulos.'. Pregúntame por cualquiera para ver su modalidad o requisitos.', 0, 1500),
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
