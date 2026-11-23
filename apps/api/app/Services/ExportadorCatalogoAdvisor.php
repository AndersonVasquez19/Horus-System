<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Entrada;
use App\Support\PaginasServicios;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * @phpstan-type ItemCatalogo array{stable_id: string, type: string, publication_status: string, title: string, summary: string, details?: string|null, modalities?: list<string>, schedule_text?: string|null, duration_text?: string|null, start_date?: string|null, certification_text?: string|null, requirements?: list<string>, public_price?: mixed, inquiry?: string|null, keywords?: list<string>, url_path?: string|null, updated_at: string, valid_from?: string|null, valid_until?: string|null}
 * @phpstan-type Catalogo array{schema_version: string, catalog_version: string, source_system: string, generated_at: string, expires_at: string, managed_types?: list<string>, items: list<ItemCatalogo>, signature: string}
 */
class ExportadorCatalogoAdvisor
{
    public function __construct(
        private FirmaCatalogoAdvisor $firma,
        private CatalogoPublicoServicios $servicios,
        private ContenidoPublicoServicios $paginasServicios,
    ) {}

    /**
     * Construye un snapshot en memoria. No publica, guarda ni transmite contenido.
     *
     * @return Catalogo
     */
    public function construir(User $actor): array
    {
        Gate::forUser($actor)->authorize('cursos.publicar');

        return $this->crearSnapshot(false);
    }

    /** Snapshot completo para un job interno post-commit; cada origen vuelve a validar su autorización editorial. */
    /** @return Catalogo */
    public function construirParaSincronizacion(): array
    {
        return $this->crearSnapshot(true);
    }

    /** @return Catalogo */
    private function crearSnapshot(bool $sincronizacion): array
    {
        $ahora = now('America/Lima');
        $items = [];
        $ttlMinutos = max(5, min(60, Entrada::entero(config('horus-catalog.ttl_minutes', 30))));
        $vence = $ahora->copy()->addMinutes($ttlMinutos);
        if (Schema::hasTable('curso_publicaciones')) {
            $filas = DB::table('curso_publicaciones as p')->join('cursos as c', 'c.id', '=', 'p.curso_id')
                ->where('p.publicada', true)->where('c.estado', 'activo')->whereNull('c.deleted_at')
                ->whereNotNull('p.autorizado_por')->whereNotNull('p.autorizado_at')->where('p.autorizado_at', '<=', $ahora->copy()->timezone(Entrada::texto(config('app.timezone'))))
                ->whereDate('p.vigente_desde', '<=', $ahora->toDateString())->whereDate('p.vigente_hasta', '>=', $ahora->toDateString())
                ->orderBy('p.id')->limit(501)
                ->get(['p.id', 'p.titulo', 'p.resumen', 'p.modalidad', 'p.horario', 'p.inscripcion', 'p.vigente_desde', 'p.vigente_hasta', 'p.updated_at', 'p.autorizado_por',
                    'p.fecha_inicio', 'p.duracion', 'p.certificacion', 'p.temario']);
            if ($filas->count() > 500) {
                throw new InvalidArgumentException('El catálogo excede 500 fichas; no se exporta parcialmente.');
            }
            $autores = User::query()->whereIn('id', $filas->pluck('autorizado_por')->unique())->get()->keyBy('id');
            foreach ($filas as $p) {
                if (! ($autores->get($p->autorizado_por)?->can('cursos.publicar') ?? false)) {
                    continue;
                }
                $titulo = trim((string) $p->titulo);
                $resumen = trim((string) $p->resumen);
                if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 120 || mb_strlen($resumen) < 10 || mb_strlen($resumen) > 500) {
                    throw new InvalidArgumentException('Ficha editorial incompatible con el contrato Advisor.');
                }
                $detalles = trim((string) $p->inscripcion);
                $modalidad = (string) $p->modalidad;
                if (! in_array($modalidad, ['virtual', 'presencial', 'semipresencial', 'hibrido'], true)) {
                    throw new InvalidArgumentException('Modalidad editorial incompatible.');
                }
                // Python no tiene el enum hibrido. Se conserva como texto, sin equipararlo a otro.
                if ($modalidad === 'hibrido') {
                    $detalles = trim($detalles.' Modalidad: hibrido.');
                }
                $horario = $p->horario === null ? null : trim((string) $p->horario);
                $duracion = $this->textoOpcional($p->duracion ?? null);
                $certificacion = $this->textoOpcional($p->certificacion ?? null);
                $texto = implode(' ', [$titulo, $resumen, $detalles, $horario ?? '', $duracion ?? '', $certificacion ?? '']);
                if (preg_match(self::PRIVACIDAD, $texto)
                    || mb_strlen($detalles) > 1500 || ($horario !== null && mb_strlen($horario) > 200)
                    || ($duracion !== null && mb_strlen($duracion) > 200) || ($certificacion !== null && mb_strlen($certificacion) > 200)) {
                    throw new InvalidArgumentException('La ficha requiere revisión de privacidad o longitud.');
                }
                $detalles = $this->conTemario($detalles, $this->textoOpcional($p->temario ?? null));
                $fechaInicio = $this->textoOpcional($p->fecha_inicio ?? null);
                $fin = Carbon::parse((string) $p->vigente_hasta, 'America/Lima')->startOfDay()->addDay();
                if ($fin->lessThan($vence)) {
                    $vence = $fin;
                }
                $items[] = [
                    'stable_id' => 'curso:publicacion-'.$p->id,
                    'type' => 'curso', 'publication_status' => 'vigente',
                    'title' => $titulo, 'summary' => $resumen,
                    'details' => $detalles === '' ? null : $detalles,
                    'modalities' => $modalidad === 'hibrido' ? [] : [$modalidad],
                    'schedule_text' => $horario,
                    'duration_text' => $duracion,
                    'start_date' => $fechaInicio === null ? null : substr($fechaInicio, 0, 10),
                    'certification_text' => $certificacion,
                    // No hay página por curso: se enlaza el listado público donde aparece la ficha.
                    'url_path' => '/educacion/cursos',
                    'updated_at' => Carbon::parse((string) $p->updated_at, Entrada::texto(config('app.timezone')))->toIso8601String(),
                    'valid_from' => substr((string) $p->vigente_desde, 0, 10),
                    'valid_until' => substr((string) $p->vigente_hasta, 0, 10),
                ];
            }
        }
        array_push($items, ...$this->serviciosPublicados($ahora, $vence));
        if (count($items) > 500) {
            throw new InvalidArgumentException('El catálogo excede 500 fichas; no se exporta parcialmente.');
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['stable_id'], $b['stable_id']));
        $managedTypes = ['curso', 'servicio'];
        $version = $sincronizacion
            ? $this->firma->versionContenido(['managed_types' => $managedTypes, 'items' => $items])
            : $ahora->copy()->utc()->format('YmdHis').'-'.bin2hex(random_bytes(6));
        $generado = $sincronizacion
            ? $ahora->copy()->utc()->format('Y-m-d\\TH:i:s.u\\Z')
            : $ahora->toIso8601String();
        $catalogo = [
            'schema_version' => '1.0', 'catalog_version' => $version,
            'source_system' => 'horus-laravel', 'generated_at' => $generado,
            'expires_at' => $sincronizacion
                ? $vence->copy()->utc()->format('Y-m-d\\TH:i:s.u\\Z')
                : $vence->toIso8601String(),
            ...($sincronizacion ? ['managed_types' => $managedTypes] : []),
            'items' => $items,
        ];
        $catalogo['signature'] = $this->firma->firmar($catalogo);
        if (strlen(json_encode($catalogo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 1048576) {
            throw new InvalidArgumentException('El catálogo excede el límite de transporte.');
        }

        return $catalogo;
    }

    /** Correos, teléfonos o documentos, HTML y vocabulario administrativo: no salen hacia Horus AI. */
    private const PRIVACIDAD = '/@|\d[\d\s-]{6,}\d|<|>|\b(dni|password|token|estudiantes|matriculados|cotizaciones|clientes)\b/iu';

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        return $texto === '' ? null : $texto;
    }

    /**
     * Añade el temario publicado a los detalles. Si parece contener un dato personal se omite solo el temario
     * (no la ficha); si no cabe en el límite del contrato se recorta por palabra.
     */
    private function conTemario(string $detalles, ?string $temario): string
    {
        if ($temario === null || preg_match(self::PRIVACIDAD, $temario)) {
            return $detalles;
        }
        $prefijo = trim($detalles.' Temario: ');
        $disponible = 1500 - mb_strlen($prefijo) - 2;
        if ($disponible < 20) {
            return $detalles;
        }
        $cuerpo = rtrim(preg_replace('/\s+/u', ' ', $temario) ?? $temario, '. ');
        if (mb_strlen($cuerpo) > $disponible) {
            $corte = mb_substr($cuerpo, 0, $disponible - 1);
            $espacio = mb_strrpos($corte, ' ');
            $cuerpo = rtrim($espacio === false ? $corte : mb_substr($corte, 0, $espacio), ' ,;:').'…';
        }

        return $prefijo.' '.$cuerpo.'.';
    }

    /** @return list<ItemCatalogo> */
    private function serviciosPublicados(Carbon $ahora, Carbon &$vence): array
    {
        // Reutiliza las lecturas existentes y sus permisos; el flag de presentación web no se cambia fuera de esta operación.
        $flagAnterior = (bool) config('catalogo-servicios.enabled', false);
        config(['catalogo-servicios.enabled' => true]);
        try {
            $resultado = [];
            $pagina = 1;
            do {
                $lote = $this->servicios->pagina($pagina, 50);
                foreach ($lote['servicios'] as $fila) {
                    if (count($resultado) >= 500) {
                        throw new InvalidArgumentException('El catálogo excede 500 fichas; no se exporta parcialmente.');
                    }
                    $resultado[] = $this->itemServicio(
                        'servicio:'.strtolower((string) $fila['identificador_publico']),
                        (string) $fila['titulo'], (string) $fila['resumen'], (string) $fila['descripcion'],
                        null, (string) $fila['vigente_desde'], (string) $fila['vigente_hasta'], $ahora, $vence,
                    );
                }
                $pagina++;
            } while (($pagina - 1) * 50 < $lote['total']);

            foreach (PaginasServicios::todas() as $codigo => $paginaPublica) {
                $ficha = $this->paginasServicios->pagina($codigo);
                if ($ficha === null) {
                    continue;
                }
                $parrafos = $ficha['parrafos'];
                $resumen = trim((string) ($parrafos[0] ?? ''));
                $detalles = implode(' ', array_slice($parrafos, 1));
                foreach ($ficha['tarjetas'] as $tarjeta) {
                    $detalles = trim($detalles.' '.$tarjeta['titulo'].'. '.$tarjeta['descripcion']);
                    foreach ($tarjeta['caracteristicas'] as $caracteristica) {
                        $detalles = trim($detalles.' '.$caracteristica.'.');
                    }
                }
                $ruta = $paginaPublica['ruta'];
                $resultado[] = $this->itemServicio(
                    'servicio:pagina-'.strtolower($codigo), (string) $ficha['titulo'], $resumen,
                    $detalles === '' ? $resumen : $detalles, $ruta, null, null, $ahora, $vence,
                );
            }

            return $resultado;
        } finally {
            config(['catalogo-servicios.enabled' => $flagAnterior]);
        }
    }

    /** @return ItemCatalogo */
    private function itemServicio(
        string $stableId,
        string $titulo,
        string $resumen,
        string $detalles,
        ?string $url,
        ?string $vigenteDesde,
        ?string $vigenteHasta,
        Carbon $ahora,
        Carbon &$vence,
    ): array {
        if (! preg_match('/\\Aservicio:[a-z0-9][a-z0-9-]{1,50}\\z/D', $stableId)
            || mb_strlen($titulo) < 3 || mb_strlen($titulo) > 120
            || mb_strlen($resumen) < 10 || mb_strlen($resumen) > 600
            || mb_strlen($detalles) > 1500
            || ($url !== null && ! preg_match('~\\A/[a-z0-9\\-/#]*\\z~D', $url))) {
            throw new InvalidArgumentException('La ficha de servicio no cumple el contrato público Advisor.');
        }
        if (preg_match('/@|\\d[\\d\\s-]{6,}\\d|<|>|\\b(dni|password|token|estudiantes|matriculados|cotizaciones|clientes)\\b/iu', $titulo.' '.$resumen.' '.$detalles)) {
            throw new InvalidArgumentException('La ficha requiere revisión de privacidad.');
        }
        if ($vigenteHasta !== null) {
            $fin = Carbon::parse($vigenteHasta, 'America/Lima')->startOfDay()->addDay();
            if ($fin->lessThan($vence)) {
                $vence = $fin;
            }
        }

        return [
            'stable_id' => $stableId, 'type' => 'servicio', 'publication_status' => 'vigente',
            'title' => trim($titulo), 'summary' => trim($resumen), 'details' => trim($detalles),
            'keywords' => [trim($titulo)], 'url_path' => $url,
            'updated_at' => $ahora->toIso8601String(),
            'valid_from' => $vigenteDesde === null ? null : substr($vigenteDesde, 0, 10),
            'valid_until' => $vigenteHasta === null ? null : substr($vigenteHasta, 0, 10),
        ];
    }
}
