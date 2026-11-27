<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Entrada;
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
        '/educacion/cursos', '/educacion/capacitaciones', '/contactos', '/legales/preguntas-frecuentes',
        '/tecnologias/camaras-seguridad', '/tecnologias/cableado-estructurado',
        '/tecnologias/soporte-mantenimiento', '/legales/politicas-devolucion',
        '/legales/politicas-privacidad', '/legales/politicas-cookies', '/quienes-somos', '/galeria',
    ];

    public function __construct(
        private ExportadorCatalogoAdvisor $exportador,
        private FirmaCatalogoAdvisor $firma,
        private ContenidoPublicoServicios $servicios,
        private CatalogoPublicoCursos $cursos,
        private FiltroPrivacidadChat $privacidad,
    ) {}

    /**
     * @param  array{respuesta: string, intent: string|null, fuente: string, recomendaciones?: list<array{id: string, title: string, url_path: string|null}>, enlaces?: list<string>, sugerencias?: list<string>, miembros?: true}  $resultado
     * @return array<string, mixed>
     */
    public function paraFrontend(array $resultado): array
    {
        $incluirMiembros = ($resultado['miembros'] ?? false) === true;
        unset($resultado['miembros']);
        $referencias = $resultado['recomendaciones'] ?? [];
        unset($resultado['recomendaciones']);
        if ($resultado['fuente'] !== 'advisor') {
            unset($resultado['enlaces']);
        }
        if ($resultado['fuente'] === 'advisor' && $incluirMiembros && config('horus-advisor.enabled')) {
            $miembros = $this->tarjetasMiembrosPublicos();
            if ($miembros !== []) {
                $resultado['tarjetas_miembros'] = $miembros;
            }
        }
        if ($resultado['fuente'] !== 'advisor' || $referencias === []
            || ! config('horus-advisor.enabled') || ! config('horus-catalog.sync_enabled')) {
            return $resultado;
        }
        try {
            $esperada = Cache::get(Entrada::texto(config('horus-catalog.expected_version_cache_key', 'horus-ai.catalog.expected-version')));
            $catalogo = $this->exportador->construirParaSincronizacion();
            if (! is_string($esperada) || ! hash_equals($esperada, $catalogo['catalog_version'])
                || ! $this->firma->verificar($catalogo) || Carbon::parse($catalogo['expires_at'])->isPast()
                || count($referencias) > 5) {
                return $resultado;
            }
            $items = [];
            foreach ($catalogo['items'] as $item) {
                if ($item['publication_status'] !== 'vigente' || ! in_array($item['type'], ['curso', 'servicio'], true)) {
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
            // Las tarjetas ya llevan el título, el detalle y el botón: el texto no los repite ni duplica el enlace.
            $resultado['respuesta'] = $this->sinListaRepetida($resultado['respuesta'], $resultado['tarjetas']);
            $urls = array_column($resultado['tarjetas'], 'url');
            $resultado['enlaces'] = array_values(array_diff($resultado['enlaces'] ?? [], $urls));
            if ($resultado['enlaces'] === []) {
                unset($resultado['enlaces']);
            }
        } catch (Throwable) {
            // Fallo de presentación: conservar el texto previamente validado, sin filtrar excepciones ni datos.
        }

        return $resultado;
    }

    /**
     * Quita del texto la lista numerada (o el resumen idéntico a la tarjeta) cuando las tarjetas ya muestran lo mismo.
     *
     * @param  list<array{titulo: string, descripcion: string, ...}>  $tarjetas
     */
    private function sinListaRepetida(string $respuesta, array $tarjetas): string
    {
        $intro = count($tarjetas) === 1 ? 'Te muestro la opción que se ajusta a lo que buscas.' : 'Te muestro las opciones que se ajustan a lo que buscas.';
        $sinLista = preg_replace('/\AEstas opciones se ajustan a lo que buscas:\R(?:\d+\. [^
]*(?:\R|\z))+/u', $intro."
", $respuesta, 1, $cambios);
        if (is_string($sinLista) && $cambios === 1) {
            return trim($sinLista);
        }
        if (count($tarjetas) === 1 && $respuesta === $tarjetas[0]['titulo'].': '.$tarjetas[0]['descripcion']) {
            return $intro;
        }

        return $respuesta;
    }

    /** @phpstan-assert-if-true non-empty-string $texto */
    private function textoPublico(mixed $texto, int $maximo): bool
    {
        return is_string($texto) && trim($texto) !== '' && mb_strlen($texto) <= $maximo
            && ! preg_match('~[<>@\x00-\x1f]|https?://|www\.|\d[\d\s-]{6,}\d~iu', $texto)
            && ! $this->privacidad->contieneDatosPrivadosEnRespuesta($texto);
    }

    /**
     * Tarjetas públicas del equipo de Horus Group SRL (Quiénes somos), leídas y revalidadas localmente desde
     * `knowledge.json`. Si algún campo o enlace no cumple la lista blanca, se descarta todo el lote.
     *
     * @return list<array{id: string, nombre: string, cargo: string, descripcion: string, foto: string, redes: list<array{tipo: string, etiqueta: string, url: string}>}>
     */
    private function tarjetasMiembrosPublicos(): array
    {
        $ruta = Entrada::texto(config('horus-knowledge.path'));
        if ($ruta === '' || ! is_file($ruta) || ! is_readable($ruta)) {
            return [];
        }
        try {
            $datos = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (! is_array($datos) || ! is_array($datos['records'] ?? null)) {
            return [];
        }
        $registro = null;
        foreach ($datos['records'] as $item) {
            if (is_array($item) && ($item['id'] ?? null) === 'empresa_equipo' && ($item['status'] ?? null) === 'verified') {
                $registro = $item;
                break;
            }
        }
        $brutos = is_array($registro) ? ($registro['public_members'] ?? null) : null;
        if (! is_array($brutos) || ! array_is_list($brutos) || $brutos === [] || count($brutos) > 10) {
            return [];
        }
        $tarjetas = [];
        $vistos = [];
        foreach ($brutos as $miembro) {
            if (! is_array($miembro)) {
                return [];
            }
            $id = $miembro['id'] ?? null;
            $nombre = $miembro['nombre'] ?? null;
            $cargo = $miembro['cargo'] ?? null;
            $descripcion = $miembro['descripcion'] ?? null;
            $foto = $miembro['foto'] ?? null;
            $redesBrutas = $miembro['redes'] ?? null;
            if (! is_string($id) || preg_match('/\A[a-z0-9_-]{1,32}\z/D', $id) !== 1 || isset($vistos[$id])
                || ! $this->textoPublico($nombre, 80) || ! $this->textoPublico($cargo, 80)
                || ! $this->textoPublico($descripcion, 320)
                || ! is_string($foto) || str_contains($foto, '..')
                || preg_match('~\A/images/somos/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)\z~D', $foto) !== 1
                || ! is_array($redesBrutas) || ! array_is_list($redesBrutas) || $redesBrutas === [] || count($redesBrutas) > 4) {
                return [];
            }
            $redes = [];
            foreach ($redesBrutas as $red) {
                if (! is_array($red)) {
                    return [];
                }
                $tipo = $red['tipo'] ?? null;
                $etiqueta = $red['etiqueta'] ?? null;
                $url = $red['url'] ?? null;
                if (! is_string($tipo) || ! $this->textoPublico($etiqueta, 32) || ! is_string($url)) {
                    return [];
                }
                $urlValida = match ($tipo) {
                    'facebook' => preg_match('~\Ahttps://(?:www|web)\.facebook\.com/[A-Za-z0-9./_%?=&-]+\z~D', $url) === 1,
                    'whatsapp' => $url === 'https://wa.me/51972021374',
                    default => false,
                };
                if (! $urlValida) {
                    return [];
                }
                $redes[] = ['tipo' => $tipo, 'etiqueta' => $etiqueta, 'url' => $url];
            }
            $vistos[$id] = true;
            $tarjetas[] = [
                'id' => $id,
                'nombre' => $nombre,
                'cargo' => $cargo,
                'descripcion' => $descripcion,
                'foto' => $foto,
                'redes' => $redes,
            ];
        }

        return $tarjetas;
    }

    /**
     * Portada de la ficha pública del curso, solo si la web la mostraría ahora y el archivo es una imagen permitida.
     *
     * @param  array<string, mixed>  $item
     */
    private function portadaDeCurso(array $item): ?string
    {
        if (preg_match('~\Acurso:publicacion-([1-9][0-9]{0,17})\z~D', Entrada::texto($item['stable_id']), $coincidencias) !== 1) {
            return null;
        }
        $ficha = $this->cursos->portadaAutorizada((int) $coincidencias[1]);
        $ruta = $ficha['imagen_ruta'] ?? null;
        if ($ficha === null || trim($ficha['titulo']) !== trim(Entrada::texto($item['title']))
            || $ruta === null || ! ImagenesCursos::rutaValida($ruta)
            || config('filesystems.disks.public.driver') !== 'local'
            || ! Storage::disk(ImagenesCursos::DISCO)->exists($ruta)
            // Por el contenido: `Storage::mimeType` recurre a la extensión y aceptaría un archivo que no es imagen.
            || ! in_array((new \finfo(FILEINFO_MIME_TYPE))->file(Storage::disk(ImagenesCursos::DISCO)->path($ruta)), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        return '/storage/'.$ruta;
    }

    /** @param array<string, mixed> $item */
    private function imagenPublicada(array $item): ?string
    {
        if ($item['type'] === 'curso') {
            return $this->portadaDeCurso($item);
        }
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
            $origen = parse_url(Entrada::texto(config('app.url')));
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
