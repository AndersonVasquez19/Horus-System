<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FiltroPrivacidadChat;
use App\Support\PaginasServicios;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Adaptador de salida. No consulta a Python ni publica, sincroniza o modifica contenidos. */
class PresentacionRecomendacionesHorus
{
    private const RUTAS = [
        '/educacion/cursos', '/educacion/capacitaciones', '/contactos', '/preguntas-frecuentes',
        '/tecnologias/camaras-seguridad', '/tecnologias/cableado-estructurado',
        '/tecnologias/soporte-mantenimiento', '/politicas-devolucion', '/quienes-somos',
    ];

    public function __construct(
        private ExportadorCatalogoAdvisor $exportador,
        private FirmaCatalogoAdvisor $firma,
        private ContenidoPublicoServicios $servicios,
        private FiltroPrivacidadChat $privacidad,
    ) {}

    /**
     * @param  array{respuesta: string, intent: string|null, fuente: string, recomendaciones?: list<array{id: string, title: string, url_path: string|null}>}  $resultado
     * @return array<string, mixed>
     */
    public function paraFrontend(array $resultado): array
    {
        $referencias = $resultado['recomendaciones'] ?? [];
        unset($resultado['recomendaciones']);
        if ($resultado['fuente'] !== 'advisor' || $referencias === []
            || ! config('horus-advisor.enabled') || ! config('horus-catalog.sync_enabled')) {
            return $resultado;
        }
        try {
            $esperada = Cache::get(config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version'));
            $catalogo = $this->exportador->construirParaSincronizacion();
            if (! is_string($esperada) || ! hash_equals($esperada, $catalogo['catalog_version'])
                || ! $this->firma->verificar($catalogo) || Carbon::parse($catalogo['expires_at'])->isPast()
                || count($referencias) > 5) {
                return $resultado;
            }
            $items = [];
            foreach ($catalogo['items'] as $item) {
                if (($item['publication_status'] ?? null) !== 'vigente' || ! in_array($item['type'] ?? null, ['curso', 'servicio'], true)) {
                    continue;
                }
                $id = rtrim(substr('cat_'.preg_replace('/[^a-z0-9]+/', '_', $item['stable_id']), 0, 64), '_');
                if (isset($items[$id])) {
                    return $resultado;
                }
                $items[$id] = $item;
            }
            $tarjetas = [];
            foreach ($referencias as $referencia) {
                $item = $items[$referencia['id']] ?? null;
                if ($item === null || isset($tarjetas[$referencia['id']])
                    || $referencia['title'] !== $item['title'] || $referencia['url_path'] !== ($item['url_path'] ?? null)
                    || ! $this->textoPublico($item['title'], 120) || ! $this->textoPublico($item['summary'], 500)
                    || (($item['url_path'] ?? null) !== null && ! in_array($item['url_path'], self::RUTAS, true))) {
                    return $resultado;
                }
                $url = $item['url_path'] ?? ($item['type'] === 'curso' ? '/educacion/cursos' : '/contactos');
                $tarjetas[$referencia['id']] = [
                    'id' => $referencia['id'], 'tipo' => $item['type'], 'titulo' => $item['title'], 'descripcion' => $item['summary'],
                    'imagen' => $this->imagenPublicada($item), 'url' => $url,
                    'accion' => isset($item['url_path']) ? 'Ver información' : ($item['type'] === 'curso' ? 'Ver cursos' : 'Consultar servicio'),
                    'procedencia' => ['id' => $referencia['id'], 'sistema' => 'horus-laravel',
                        'version' => $catalogo['catalog_version'], 'url' => $item['url_path'] ?? null, 'actualizado' => $item['updated_at']],
                ];
            }
            $resultado['tarjetas'] = array_values($tarjetas);
        } catch (Throwable) {
            // Fallo de presentación: conservar el texto previamente validado, sin filtrar excepciones ni datos.
        }

        return $resultado;
    }

    private function textoPublico(mixed $texto, int $maximo): bool
    {
        return is_string($texto) && trim($texto) !== '' && mb_strlen($texto) <= $maximo
            && ! preg_match('~[<>@\x00-\x1f]|https?://|www\.|\d[\d\s-]{6,}\d~iu', $texto)
            && ! $this->privacidad->contieneDatosPrivadosEnRespuesta($texto);
    }

    /** @param array<string, mixed> $item */
    private function imagenPublicada(array $item): ?string
    {
        if ($item['type'] !== 'servicio') {
            return null;
        }
        foreach (PaginasServicios::codigos() as $codigo) {
            if ($item['stable_id'] !== 'servicio:pagina-'.strtolower($codigo)) {
                continue;
            }
            // La lectura existente aplica su interruptor y autorización editorial; no se habilitan aquí.
            $pagina = $this->servicios->pagina($codigo);
            if ($pagina === null || $pagina['titulo'] !== $item['title']
                || ($pagina['parrafos'][0] ?? '') !== $item['summary']
                || ($item['url_path'] ?? null) !== (PaginasServicios::de($codigo)['url'] ?? null)) {
                return null;
            }
            $url = $pagina['imagen_url'];
            if (! is_string($url)) {
                return null;
            }
            $partes = parse_url($url);
            $origen = parse_url((string) config('app.url'));
            if ($partes === false || $origen === false || isset($partes['query']) || isset($partes['fragment'])
                || isset($partes['user']) || isset($partes['pass'])
                || (isset($partes['host']) && (($partes['host'] !== ($origen['host'] ?? null))
                    || ($partes['scheme'] ?? null) !== ($origen['scheme'] ?? null)
                    || ($partes['port'] ?? null) !== ($origen['port'] ?? null)))) {
                return null;
            }
            $ruta = $partes['path'] ?? '';
            if (str_starts_with($ruta, '/assets/images/')) {
                $relativa = ltrim($ruta, '/');
                $archivo = realpath(public_path($relativa));
                $base = realpath(public_path('assets/images'));
                if (! ImagenesServicios::rutaValida($relativa) || $archivo === false || $base === false
                    || ! str_starts_with($archivo, $base.DIRECTORY_SEPARATOR) || ! is_file($archivo)
                    || ! in_array((new \finfo(FILEINFO_MIME_TYPE))->file($archivo), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return null;
                }

                return $ruta;
            }
            if (config('filesystems.disks.public.driver') === 'local'
                && preg_match('~\A/storage/(servicios/[0-9a-f-]{36}\.(?:jpg|png|webp))\z~D', $ruta, $coincidencias)
                && ImagenesServicios::rutaValida($coincidencias[1]) && Storage::disk(ImagenesServicios::DISCO)->exists($coincidencias[1])
                && in_array(Storage::disk(ImagenesServicios::DISCO)->mimeType($coincidencias[1]), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                return $ruta;
            }
        }

        return null;
    }
}
