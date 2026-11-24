<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ObligacionPago;
use App\Models\SeguimientoCobranza;
use App\Models\User;
use App\Support\BusquedaLike;
use App\Support\Entrada;
use App\Support\HoraLocal;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cobranza asistida (Fase 3.1). La cola prioriza las obligaciones vigentes con cuotas vencidas sin pagar (saldo vencido > 0), por
 * antigüedad de la deuda más vieja; cada fila trae el último contacto y la promesa de pago. Los importes se calculan en céntimos
 * desde las cuotas y las asignaciones del libro (neto de reversos). No envía nada: los contactos son manuales (el enlace de WhatsApp
 * o el correo se abren desde el panel) y aquí solo se registra lo hecho.
 *
 * Promesa: la última promesa registrada. «vigente» hasta su fecha inclusive; pasada la fecha, mientras siga habiendo saldo vencido,
 * «incumplida». No se intenta adivinar si un pago posterior cumplió una promesa concreta: la cola solo contiene deuda que sigue viva.
 *
 * @phpstan-type FilaCola array{obligacion: string, matricula_id: int, estudiante: string|null, dni: string|null, curso: array{codigo: string, nombre: string}|null, concepto: string, dias_mora: int, tramo: string, monto_vencido: string, saldo: string, cuotas_vencidas: int, ultimo_contacto: array{fecha: string|null, canal: string, resultado: string}|null, promesa: array{estado: string, fecha: string, monto: string|null}|null}
 */
class CobranzaAsistida
{
    public const LIMITE_FILAS = 200;

    public const TRAMOS = ['reciente', 'media', 'critica'];

    /**
     * @param  array{tramo?: string|null, promesa?: string|null, busqueda?: string|null}  $filtros
     * @return array{rows: list<FilaCola>, resumen: array<string, mixed>, truncado: bool}
     */
    public function cola(array $filtros = []): array
    {
        $hoy = HoraLocal::hoy();
        $consulta = ObligacionPago::query()
            ->where('estado', 'vigente')
            ->whereHas('cuotas', fn (Builder $c) => $c->where('vence', '<', $hoy))
            ->whereHas('matricula', function (Builder $m) use ($filtros): void {
                $m->where('estado', '!=', 'anulada');
                $b = trim(Entrada::texto($filtros['busqueda'] ?? ''));
                if ($b !== '') {
                    $m->where(function (Builder $sub) use ($b): void {
                        $sub->whereHas('estudiante', fn (Builder $e) => BusquedaLike::contiene($e, ['dni', 'nombres', 'apellidos'], $b))
                            ->orWhereHas('curso', fn (Builder $c) => BusquedaLike::contiene($c, ['codigo', 'nombre'], $b));
                    });
                }
            })
            ->with(['matricula.estudiante:id,dni,nombres,apellidos', 'matricula.curso:id,codigo,nombre', 'cuotas'])
            ->orderBy('id');

        $obligaciones = $consulta->get();
        $ids = array_values(array_map(static fn (ObligacionPago $o): int => $o->id, $obligaciones->all()));
        $pagado = $this->pagadoPorCuota($ids);
        $seguimientos = $this->seguimientosPorObligacion($ids);

        $filas = [];
        $resumen = ['obligaciones' => 0, 'monto_vencido' => 0, 'tramos' => [], 'promesas_vigentes' => 0, 'promesas_incumplidas' => 0, 'sin_contacto' => 0];
        foreach (self::TRAMOS as $t) {
            $resumen['tramos'][$t] = ['cantidad' => 0, 'monto' => 0];
        }

        foreach ($obligaciones as $ob) {
            $vencido = 0;
            $saldo = 0;
            $masVieja = null;
            $nVencidas = 0;
            foreach ($ob->cuotas as $cuota) {
                $debe = GestionPagos::leer($cuota->monto) - ($pagado[$cuota->id] ?? 0);
                if ($debe <= 0) {
                    continue;
                }
                $saldo += $debe;
                $vence = substr((string) $cuota->vence, 0, 10);
                if ($vence < $hoy) {
                    $vencido += $debe;
                    $nVencidas++;
                    $masVieja = $masVieja === null || $vence < $masVieja ? $vence : $masVieja;
                }
            }
            if ($vencido <= 0 || $masVieja === null) {
                continue;
            }

            $dias = (new DateTimeImmutable($masVieja.' UTC'))->diff(new DateTimeImmutable($hoy.' UTC'))->days;
            $tramo = $dias <= 7 ? 'reciente' : ($dias <= 30 ? 'media' : 'critica');
            $lista = $seguimientos[$ob->id] ?? [];
            $ultimo = $lista[0] ?? null;
            $promesa = null;
            foreach ($lista as $s) {
                if ($s->promesa_fecha !== null) {
                    $fechaPromesa = substr((string) $s->promesa_fecha, 0, 10);
                    $promesa = ['estado' => $fechaPromesa >= $hoy ? 'vigente' : 'incumplida', 'fecha' => $fechaPromesa, 'monto' => $s->promesa_monto === null ? null : GestionPagos::formato(GestionPagos::leer($s->promesa_monto))];
                    break;
                }
            }

            $resumen['obligaciones']++;
            $resumen['monto_vencido'] += $vencido;
            $resumen['tramos'][$tramo]['cantidad']++;
            $resumen['tramos'][$tramo]['monto'] += $vencido;
            $resumen['sin_contacto'] += $ultimo === null ? 1 : 0;
            if ($promesa !== null) {
                $resumen[$promesa['estado'] === 'vigente' ? 'promesas_vigentes' : 'promesas_incumplidas']++;
            }

            if (($filtros['tramo'] ?? null) !== null && $filtros['tramo'] !== '' && $filtros['tramo'] !== $tramo) {
                continue;
            }
            $filtroPromesa = $filtros['promesa'] ?? null;
            if ($filtroPromesa === 'ninguna' && $promesa !== null) {
                continue;
            }
            if (in_array($filtroPromesa, ['vigente', 'incumplida'], true) && ($promesa['estado'] ?? null) !== $filtroPromesa) {
                continue;
            }

            $est = $ob->matricula?->estudiante;
            $curso = $ob->matricula?->curso;
            $filas[] = [
                'fila' => [
                    'obligacion' => $ob->identificador,
                    'matricula_id' => $ob->matricula_id,
                    'estudiante' => $est ? trim($est->nombres.' '.$est->apellidos) : null,
                    'dni' => $est?->dni,
                    'curso' => $curso ? ['codigo' => $curso->codigo, 'nombre' => $curso->nombre] : null,
                    'concepto' => $ob->concepto,
                    'dias_mora' => $dias,
                    'tramo' => $tramo,
                    'monto_vencido' => GestionPagos::formato($vencido),
                    'saldo' => GestionPagos::formato($saldo),
                    'cuotas_vencidas' => $nVencidas,
                    'ultimo_contacto' => $ultimo === null ? null : [
                        'fecha' => $ultimo->created_at?->toIso8601String(),
                        'canal' => $ultimo->canal,
                        'resultado' => $ultimo->resultado,
                    ],
                    'promesa' => $promesa,
                ],
                // Orden: primero lo que más urge (promesa vigente al final), luego la deuda más vieja y de mayor monto.
                'orden' => [$promesa !== null && $promesa['estado'] === 'vigente' ? 1 : 0, -$dias, -$vencido],
            ];
        }

        usort($filas, static fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);
        $truncado = count($filas) > self::LIMITE_FILAS;
        $filas = array_slice($filas, 0, self::LIMITE_FILAS);

        $resumen['monto_vencido'] = GestionPagos::formato($resumen['monto_vencido']);
        foreach ($resumen['tramos'] as $t => $v) {
            $resumen['tramos'][$t]['monto'] = GestionPagos::formato($v['monto']);
        }

        return ['rows' => array_map(static fn (array $f): array => $f['fila'], $filas), 'resumen' => $resumen, 'truncado' => $truncado];
    }

    /**
     * Registra un contacto de cobro. Solo sobre una obligación vigente. Si es promesa, la fecha va de hoy a 90 días.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function registrar(User $usuario, string $identificador, array $datos): array
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return DB::transaction(function () use ($usuario, $identificador, $datos): array {
            $ob = ObligacionPago::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
            abort_unless($ob->estado === 'vigente', 409, 'Solo se registra seguimiento en una obligación vigente.');

            $resultado = Entrada::texto($datos['resultado'] ?? '');
            $fecha = isset($datos['promesa_fecha']) ? Entrada::texto($datos['promesa_fecha']) : null;
            if ($resultado === 'promesa_pago') {
                $hoy = HoraLocal::hoy();
                $limite = (new DateTimeImmutable($hoy.' UTC'))->modify('+90 days')->format('Y-m-d');
                if ($fecha === null || $fecha < $hoy || $fecha > $limite) {
                    throw ValidationException::withMessages(['promesa_fecha' => "La fecha de la promesa debe estar entre hoy ({$hoy}) y {$limite}."]);
                }
            }

            $nota = isset($datos['nota']) ? trim(Entrada::texto($datos['nota'])) : '';
            $s = new SeguimientoCobranza;
            $s->identificador = (string) Str::uuid();
            $s->obligacion_pago_id = $ob->id;
            $s->canal = Entrada::texto($datos['canal'] ?? '');
            $s->resultado = $resultado;
            $s->nota = $nota === '' ? null : $nota;
            $s->promesa_fecha = $resultado === 'promesa_pago' ? $fecha : null;
            $s->promesa_monto = $resultado === 'promesa_pago' && isset($datos['promesa_monto']) ? GestionPagos::formato(GestionPagos::leer($datos['promesa_monto'])) : null;
            $s->registrado_por = $usuario->id;
            $s->save();

            // Sin la nota (texto libre): solo qué se hizo y con qué resultado.
            AuditLog::record('cobranza_contacto', SeguimientoCobranza::class, $s->id, ['obligacion' => $ob->id, 'canal' => $s->canal, 'resultado' => $s->resultado, 'promesa' => $s->promesa_fecha], $usuario->id);

            return $this->presentar($s->setRelation('autor', $usuario));
        });
    }

    /**
     * Historial de contactos de una obligación, el más reciente primero (hasta 50).
     *
     * @return list<array<string, mixed>>
     */
    public function historial(string $identificador): array
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);
        $ob = ObligacionPago::query()->where('identificador', strtolower($identificador))->firstOrFail();

        return array_values(SeguimientoCobranza::query()->with('autor:id,name')->where('obligacion_pago_id', $ob->id)->orderByDesc('id')->limit(50)->get()
            ->map(fn (SeguimientoCobranza $s): array => $this->presentar($s))->all());
    }

    /**
     * Cifras de la cola para avisos (sin filas): obligaciones, monto vencido, promesas incumplidas.
     *
     * @return array{obligaciones: int, monto_vencido: string, promesas_incumplidas: int}
     */
    public function resumenParaAviso(): array
    {
        $r = $this->cola()['resumen'];

        return ['obligaciones' => Entrada::entero($r['obligaciones']), 'monto_vencido' => Entrada::texto($r['monto_vencido']), 'promesas_incumplidas' => Entrada::entero($r['promesas_incumplidas'])];
    }

    /**
     * Pagado de cada cuota (neto de reversos) de las obligaciones dadas, en céntimos.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function pagadoPorCuota(array $ids): array
    {
        $porCuota = [];
        foreach (array_chunk($ids, 500) as $lote) {
            $filas = DB::table('asignaciones_pago as a')->join('cuotas_obligacion as c', 'c.id', '=', 'a.cuota_obligacion_id')
                ->whereIn('c.obligacion_pago_id', $lote)->get(['a.cuota_obligacion_id', 'a.monto']);
            foreach ($filas as $f) {
                $id = Entrada::entero($f->cuota_obligacion_id);
                $porCuota[$id] = ($porCuota[$id] ?? 0) + GestionPagos::leer($f->monto);
            }
        }

        return $porCuota;
    }

    /**
     * Seguimientos de las obligaciones dadas, el más reciente primero, agrupados por obligación.
     *
     * @param  list<int>  $ids
     * @return array<int, list<SeguimientoCobranza>>
     */
    private function seguimientosPorObligacion(array $ids): array
    {
        $porObligacion = [];
        foreach (array_chunk($ids, 500) as $lote) {
            foreach (SeguimientoCobranza::query()->whereIn('obligacion_pago_id', $lote)->orderByDesc('id')->get() as $s) {
                $porObligacion[$s->obligacion_pago_id][] = $s;
            }
        }

        return $porObligacion;
    }

    /** @return array<string, mixed> */
    private function presentar(SeguimientoCobranza $s): array
    {
        return [
            'identificador' => $s->identificador,
            'canal' => $s->canal,
            'resultado' => $s->resultado,
            'nota' => $s->nota,
            'promesa_fecha' => $s->promesa_fecha === null ? null : substr((string) $s->promesa_fecha, 0, 10),
            'promesa_monto' => $s->promesa_monto === null ? null : GestionPagos::formato(GestionPagos::leer($s->promesa_monto)),
            'registrado_por' => $s->autor->name ?? null,
            'creado' => $s->created_at?->toIso8601String(),
        ];
    }
}
