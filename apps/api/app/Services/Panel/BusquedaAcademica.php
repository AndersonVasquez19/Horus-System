<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Convocatoria;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\TransaccionPago;
use App\Models\User;
use App\Services\GestionPagos;
use App\Support\BusquedaLike;

/**
 * Búsqueda global del panel académico: estudiantes, matrículas y ediciones. Cada grupo se incluye SOLO si la
 * persona tiene el permiso de su módulo (el servidor no envía lo que no podría consultar). Devuelve lo mínimo para identificar y
 * enlazar (hasta {@see self::POR_GRUPO} por grupo); nunca montos, notas, enlaces virtuales ni códigos de verificación.
 */
final class BusquedaAcademica
{
    public const POR_GRUPO = 5;

    public const MIN = 2;

    public const MAX = 100;

    /** @return array<string, list<array<string, mixed>>> */
    public function buscar(User $user, string $texto): array
    {
        $texto = trim($texto);
        $grupos = [];
        if (mb_strlen($texto) < self::MIN) {
            return $grupos;
        }
        $texto = mb_substr($texto, 0, self::MAX);

        if ($user->can('estudiantes.ver')) {
            $q = Estudiante::query();
            BusquedaLike::contiene($q, ['dni', 'nombres', 'apellidos', 'telefono'], $texto);
            $grupos['estudiantes'] = $q->orderBy('apellidos')->orderBy('id')->limit(self::POR_GRUPO)->get()->map(fn (Estudiante $e): array => [
                'id' => $e->id,
                'texto' => trim($e->apellidos.', '.$e->nombres),
                'detalle' => implode(' · ', array_filter(['DNI '.$e->dni, $e->telefono ? 'Tel. '.$e->telefono : null])),
            ])->all();
        }

        if ($user->can('matriculas.ver')) {
            $numeroMatricula = ltrim($texto, '#');
            $consultaMatriculas = Matricula::query()->with(['estudiante:id,nombres,apellidos,dni', 'curso:id,codigo,nombre', 'convocatoria:id,codigo']);
            $consultaMatriculas->where(function ($w) use ($texto, $numeroMatricula): void {
                $w->whereHas('estudiante', fn ($e) => BusquedaLike::contiene($e, ['dni', 'nombres', 'apellidos'], $texto))
                    ->orWhereHas('curso', fn ($c) => BusquedaLike::contiene($c, ['codigo', 'nombre'], $texto))
                    ->orWhereHas('convocatoria', fn ($cv) => BusquedaLike::contiene($cv, ['codigo'], $texto));
                if ($numeroMatricula !== '' && ctype_digit($numeroMatricula) && strlen($numeroMatricula) <= 18) {
                    $w->orWhere('id', (int) $numeroMatricula);
                }
            });

            $grupos['matriculas'] = $consultaMatriculas->latest('id')->limit(self::POR_GRUPO)->get()->map(function (Matricula $m): array {
                $dni = $m->estudiante?->dni;
                $cursoModelo = $m->curso;
                $curso = $cursoModelo === null ? '' : trim(($cursoModelo->codigo ? $cursoModelo->codigo.' — ' : '').$cursoModelo->nombre);
                $partesDetalle = array_filter([
                    $dni !== null ? 'DNI '.$dni : null,
                    $curso,
                    $m->convocatoria?->codigo,
                    $m->estado,
                ]);

                return [
                    'id' => $m->id,
                    'texto' => '#'.$m->id.' · '.trim(($m->estudiante->apellidos ?? '').', '.($m->estudiante->nombres ?? '')),
                    'detalle' => implode(' · ', $partesDetalle),
                ];
            })->all();
        }

        if ($user->can('convocatorias.ver')) {
            $q = Convocatoria::query()->with('curso:id,codigo,nombre');
            $q->where(function ($w) use ($texto): void {
                BusquedaLike::contiene($w, ['codigo', 'nombre'], $texto);
                $w->orWhereHas('curso', fn ($c) => BusquedaLike::contiene($c, ['codigo', 'nombre'], $texto));
            });
            $grupos['ediciones'] = $q->orderByDesc('fecha_inicio')->limit(self::POR_GRUPO)->get()->map(fn (Convocatoria $c): array => [
                'identificador' => $c->identificador, 'texto' => $c->codigo, 'detalle' => $c->curso->nombre ?? '',
            ])->all();
        }

        if ($user->can('pagos.ver')) {
            $q = TransaccionPago::query()
                ->where('tipo', 'pago')
                ->whereNotNull('referencia')
                ->with(['obligacion.matricula.estudiante:id,nombres,apellidos']);
            BusquedaLike::contiene($q, ['referencia'], $texto);
            $grupos['pagos'] = $q->latest('id')->limit(self::POR_GRUPO)->get()->map(function (TransaccionPago $t): array {
                $est = $t->obligacion?->matricula?->estudiante;
                $nombre = $est ? trim($est->apellidos.', '.$est->nombres) : 'Pago';

                return [
                    'identificador' => $t->obligacion->identificador ?? '',
                    'texto' => 'Op. '.($t->referencia ?? '').' · '.$nombre,
                    'detalle' => 'S/ '.GestionPagos::formato(GestionPagos::leer($t->monto)).' · '.$t->estado,
                ];
            })->filter(fn (array $item): bool => $item['identificador'] !== '')->values()->all();
        }

        return array_map(static fn (array $grupo): array => array_values($grupo), $grupos);
    }
}
