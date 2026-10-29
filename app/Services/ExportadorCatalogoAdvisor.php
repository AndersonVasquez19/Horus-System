<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ExportadorCatalogoAdvisor
{
    public function __construct(private FirmaCatalogoAdvisor $firma) {}

    /**
     * Construye un snapshot en memoria. No publica, guarda ni transmite contenido.
     *
     * @return array<string, mixed>
     */
    public function construir(User $actor): array
    {
        Gate::forUser($actor)->authorize('cursos.publicar');
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
        $catalogo = [
            'schema_version' => '1.0', 'catalog_version' => $ahora->copy()->utc()->format('YmdHis').'-'.bin2hex(random_bytes(6)),
            'source_system' => 'horus-laravel', 'generated_at' => $ahora->toIso8601String(),
            'expires_at' => $vence->toIso8601String(), 'items' => $items,
        ];
        $catalogo['signature'] = $this->firma->firmar($catalogo);
        if (strlen(json_encode($catalogo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 1048576) {
            throw new InvalidArgumentException('El catálogo excede el límite de transporte.');
        }

        return $catalogo;
    }
}
