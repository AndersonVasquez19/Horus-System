<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\PaginasServicios;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

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
     * @return array<string, mixed>
     */
    public function construir(User $actor): array
    {
        Gate::forUser($actor)->authorize('cursos.publicar');

        return $this->crearSnapshot(false);
    }

    /** Snapshot completo para un job interno post-commit; cada origen vuelve a validar su autorización editorial. */
    /** @return array<string, mixed> */
    public function construirParaSincronizacion(): array
    {
        return $this->crearSnapshot(true);
    }

    /** @return array<string, mixed> */
    private function crearSnapshot(bool $sincronizacion): array
    {
        $ahora = now('America/Lima');
        $items = [];
        $vence = $ahora->copy()->addMinutes(5);
        if (Schema::hasTable('curso_publicaciones')) {
            $filas = DB::table('curso_publicaciones as p')->join('cursos as c', 'c.id', '=', 'p.curso_id')
                ->where('p.publicada', true)->where('c.estado', 'activo')->whereNull('c.deleted_at')
                ->whereNotNull('p.autorizado_por')->whereNotNull('p.autorizado_at')->where('p.autorizado_at', '<=', $ahora->copy()->timezone(config('app.timezone')))
                ->whereDate('p.vigente_desde', '<=', $ahora->toDateString())->whereDate('p.vigente_hasta', '>=', $ahora->toDateString())
                ->orderBy('p.id')->limit(501)
                ->get(['p.id', 'p.titulo', 'p.resumen', 'p.modalidad', 'p.horario', 'p.inscripcion', 'p.vigente_desde', 'p.vigente_hasta', 'p.updated_at', 'p.autorizado_por']);
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
                    $detalles = trim($detalles.' Modalidad publicada: hibrido.');
                }
                $horario = $p->horario === null ? null : trim((string) $p->horario);
                $texto = implode(' ', [$titulo, $resumen, $detalles, $horario ?? '']);
                if (preg_match('/@|\d[\d\s-]{6,}\d|<|>|\b(dni|password|token|estudiantes|matriculados|cotizaciones|clientes)\b/iu', $texto)
                    || mb_strlen($detalles) > 1500 || ($horario !== null && mb_strlen($horario) > 200)) {
                    throw new InvalidArgumentException('La ficha requiere revisión de privacidad o longitud.');
                }
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
                    // La página actual es estática: no se inventa una URL de ficha dinámica.
                    'updated_at' => Carbon::parse((string) $p->updated_at, config('app.timezone'))->toIso8601String(),
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

    /** @return list<array<string, mixed>> */
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
                $ruta = route($paginaPublica['ruta'], [], false);
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

    /** @return array<string, mixed> */
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
