<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CierreCaja;
use App\Models\TransaccionPago;
use App\Models\User;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Caja del día (Fase 3.2). Lo esperado sale solo del libro de pagos: pagos confirmados de la fecha menos los reversos confirmados de
 * la fecha (un reverso cuenta el día en que se hizo, no el del pago original). Todo en céntimos enteros. El cierre guarda esa cifra,
 * lo que declara quien cuenta el dinero por método y la diferencia, y no se puede modificar.
 *
 * @phpstan-type FilaMetodo array{codigo: string, metodo: string, cantidad: int, cantidad_reversos: int, cobrado: string, reversado: string, total: string}
 * @phpstan-type FilaUsuario array{usuario: string, cantidad: int, total: string}
 * @phpstan-type Movimiento array{tipo: string, matricula_id: int, estudiante: string, metodo: string, referencia: string|null, monto: string}
 * @phpstan-type Resumen array{fecha: string, cantidad: int, cantidad_reversos: int, cobrado: string, reversado: string, total: string, por_metodo: list<FilaMetodo>, por_usuario: list<FilaUsuario>, movimientos: list<Movimiento>, pendientes_por_confirmar: int, cierre: array<string, mixed>|null}
 */
class CajaDiaria
{
    /** @return Resumen */
    public function resumen(string $fecha): array
    {
        $txs = TransaccionPago::query()
            ->whereIn('tipo', ['pago', 'reverso'])
            ->where('estado', 'confirmada')
            ->whereDate('fecha_pago', $fecha)
            ->with(['metodo:id,codigo,nombre', 'obligacion.matricula.estudiante:id,nombres,apellidos'])
            ->orderBy('id')
            ->get();

        $nombresUsuario = User::query()->whereIn('id', $txs->pluck('registrado_por')->filter()->unique()->all())->pluck('name', 'id');

        $cobrado = $reversado = $nPagos = $nReversos = 0;
        /** @var array<string, array{codigo: string, metodo: string, cantidad: int, cantidad_reversos: int, cobrado: int, reversado: int}> $metodos */
        $metodos = [];
        /** @var array<string, array{usuario: string, cantidad: int, total: int}> $usuarios */
        $usuarios = [];
        $movimientos = [];

        foreach ($txs as $tx) {
            $centimos = GestionPagos::leer($tx->monto);
            $esReverso = $tx->tipo === 'reverso';
            $firmado = $esReverso ? -$centimos : $centimos;
            $codigo = $tx->metodo->codigo ?? 'otro';
            $metodos[$codigo] ??= ['codigo' => $codigo, 'metodo' => $tx->metodo->nombre ?? 'Otro', 'cantidad' => 0, 'cantidad_reversos' => 0, 'cobrado' => 0, 'reversado' => 0];
            $nombreUsuario = Entrada::texto($nombresUsuario[$tx->registrado_por] ?? 'Sin usuario');
            $usuarios[$nombreUsuario] ??= ['usuario' => $nombreUsuario, 'cantidad' => 0, 'total' => 0];
            $usuarios[$nombreUsuario]['cantidad']++;
            $usuarios[$nombreUsuario]['total'] += $firmado;

            if ($esReverso) {
                $reversado += $centimos;
                $nReversos++;
                $metodos[$codigo]['cantidad_reversos']++;
                $metodos[$codigo]['reversado'] += $centimos;
            } else {
                $cobrado += $centimos;
                $nPagos++;
                $metodos[$codigo]['cantidad']++;
                $metodos[$codigo]['cobrado'] += $centimos;
            }

            $est = $tx->obligacion?->matricula?->estudiante;
            $movimientos[] = [
                'tipo' => $tx->tipo,
                'matricula_id' => $tx->obligacion->matricula_id ?? 0,
                'estudiante' => $est ? trim($est->nombres.' '.$est->apellidos) : 'Estudiante',
                'metodo' => $metodos[$codigo]['metodo'],
                'referencia' => $tx->referencia,
                'monto' => GestionPagos::formato($firmado),
            ];
        }

        $cierre = CierreCaja::query()->where('fecha', $fecha)->first();

        return [
            'fecha' => $fecha,
            'cantidad' => $nPagos,
            'cantidad_reversos' => $nReversos,
            'cobrado' => GestionPagos::formato($cobrado),
            'reversado' => GestionPagos::formato($reversado),
            'total' => GestionPagos::formato($cobrado - $reversado),
            'por_metodo' => array_values(array_map(static fn (array $m): array => [
                'codigo' => $m['codigo'],
                'metodo' => $m['metodo'],
                'cantidad' => $m['cantidad'],
                'cantidad_reversos' => $m['cantidad_reversos'],
                'cobrado' => GestionPagos::formato($m['cobrado']),
                'reversado' => GestionPagos::formato($m['reversado']),
                'total' => GestionPagos::formato($m['cobrado'] - $m['reversado']),
            ], $metodos)),
            'por_usuario' => array_values(array_map(static fn (array $u): array => [
                'usuario' => $u['usuario'],
                'cantidad' => $u['cantidad'],
                'total' => GestionPagos::formato($u['total']),
            ], $usuarios)),
            'movimientos' => $movimientos,
            'pendientes_por_confirmar' => TransaccionPago::query()->where('tipo', 'pago')->where('estado', 'pendiente')->whereDate('fecha_pago', $fecha)->count(),
            'cierre' => $cierre === null ? null : $this->presentarCierre($cierre),
        ];
    }

    /**
     * Cierra la caja de `$fecha`. `$declarado` es el efectivo/importe contado por método (código → importe) y debe incluir todos los
     * métodos con movimientos. Si hay diferencia, la observación es obligatoria. Una fecha se cierra una sola vez.
     *
     * @param  array<string, mixed>  $declarado
     * @return array<string, mixed>
     */
    public function cerrar(User $usuario, string $fecha, array $declarado, ?string $observacion): array
    {
        if ($fecha > HoraLocal::hoy()) {
            throw ValidationException::withMessages(['fecha' => 'No se puede cerrar la caja de una fecha futura.']);
        }

        try {
            return DB::transaction(function () use ($usuario, $fecha, $declarado, $observacion): array {
                if (CierreCaja::query()->where('fecha', $fecha)->lockForUpdate()->exists()) {
                    abort(409, 'La caja de esa fecha ya está cerrada.');
                }

                $resumen = $this->resumen($fecha);
                $detalle = [];
                $totalEsperado = $totalDeclarado = 0;

                foreach ($resumen['por_metodo'] as $m) {
                    if (! array_key_exists($m['codigo'], $declarado) || ! is_string($declarado[$m['codigo']]) || preg_match('/^\d{1,10}(\.\d{1,2})?$/', $declarado[$m['codigo']]) !== 1) {
                        throw ValidationException::withMessages(["declarado.{$m['codigo']}" => "Indica el importe contado de {$m['metodo']} (hasta 2 decimales)."]);
                    }
                    $esperado = GestionPagos::leer($m['total']);
                    $contado = GestionPagos::leer($declarado[$m['codigo']]);
                    $totalEsperado += $esperado;
                    $totalDeclarado += $contado;
                    $detalle['por_metodo'][] = [
                        'codigo' => $m['codigo'], 'metodo' => $m['metodo'], 'esperado' => GestionPagos::formato($esperado),
                        'declarado' => GestionPagos::formato($contado), 'diferencia' => GestionPagos::formato($contado - $esperado),
                    ];
                }
                $detalle['por_metodo'] ??= [];
                $detalle['por_usuario'] = $resumen['por_usuario'];
                $detalle['pendientes_por_confirmar'] = $resumen['pendientes_por_confirmar'];

                $diferencia = $totalDeclarado - $totalEsperado;
                $observacion = $observacion === null ? null : trim($observacion);
                if ($diferencia !== 0 && ($observacion === null || mb_strlen($observacion) < 5)) {
                    throw ValidationException::withMessages(['observacion' => 'Hay diferencia entre lo esperado y lo contado: explica el motivo (mínimo 5 caracteres).']);
                }

                $cierre = new CierreCaja;
                $cierre->identificador = (string) Str::uuid();
                $cierre->fecha = $fecha;
                $cierre->cantidad_pagos = $resumen['cantidad'];
                $cierre->cantidad_reversos = $resumen['cantidad_reversos'];
                $cierre->total_cobrado = $resumen['cobrado'];
                $cierre->total_reversado = $resumen['reversado'];
                $cierre->total_esperado = GestionPagos::formato($totalEsperado);
                $cierre->total_declarado = GestionPagos::formato($totalDeclarado);
                $cierre->diferencia = GestionPagos::formato($diferencia);
                $cierre->detalle = $detalle;
                $cierre->observacion = $observacion === '' ? null : $observacion;
                $cierre->cerrado_por = $usuario->id;
                $cierre->cerrado_en = now();
                $cierre->save();

                AuditLog::record('caja_cerrada', CierreCaja::class, $cierre->id, ['fecha' => $fecha, 'esperado' => $cierre->total_esperado, 'declarado' => $cierre->total_declarado, 'diferencia' => $cierre->diferencia], $usuario->id);

                return $this->presentarCierre($cierre);
            });
        } catch (QueryException $e) {
            // Dos cierres simultáneos de la misma fecha: la restricción única deja pasar a uno solo. Otro error de base no se disfraza.
            if (! CierreCaja::query()->where('fecha', $fecha)->exists()) {
                throw $e;
            }
            abort(409, 'La caja de esa fecha ya está cerrada.');
        }
    }

    /**
     * Cierres más recientes primero.
     *
     * @return list<array<string, mixed>>
     */
    public function cierres(int $limite = 31): array
    {
        return array_values(CierreCaja::query()->with('cerradoPor:id,name')->orderByDesc('fecha')->limit($limite)->get()
            ->map(fn (CierreCaja $c): array => $this->presentarCierre($c))->all());
    }

    /** @return array<string, mixed> */
    private function presentarCierre(CierreCaja $c): array
    {
        $c->loadMissing('cerradoPor:id,name');

        return [
            'identificador' => $c->identificador,
            'fecha' => substr((string) $c->fecha, 0, 10),
            'cantidad_pagos' => $c->cantidad_pagos,
            'cantidad_reversos' => $c->cantidad_reversos,
            'total_cobrado' => (string) $c->total_cobrado,
            'total_reversado' => (string) $c->total_reversado,
            'total_esperado' => (string) $c->total_esperado,
            'total_declarado' => (string) $c->total_declarado,
            'diferencia' => (string) $c->diferencia,
            'detalle' => $c->detalle,
            'observacion' => $c->observacion,
            'cerrado_por' => $c->cerradoPor->name ?? null,
            'cerrado_en' => $c->cerrado_en?->toIso8601String(),
        ];
    }
}
