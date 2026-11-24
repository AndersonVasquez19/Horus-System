<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ComprobantePago;
use App\Models\CuotaObligacion;
use App\Models\Matricula;
use App\Models\MetodoPago;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use App\Models\User;
use App\Support\Entrada;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finanzas (Horus Academic, Fase 7). Única implementación de las reglas del libro de pagos:
 *
 *  - solo PEN; importes en CÉNTIMOS enteros (nunca `float` en las cuentas); sin descuentos ni mora;
 *  - una obligación vigente por matrícula; la suma de cuotas iguala el total; sin sobrepagos (el saldo descuenta lo pendiente);
 *  - el libro es inmutable: un pago nace `pendiente` y se confirma o rechaza; un pago confirmado solo se compensa con un REVERSO;
 *  - registrar y confirmar deben ser personas distintas, salvo `admin`;
 *  - registrar es idempotente por `Idempotency-Key` (misma clave y mismos datos devuelven el pago original; distintos datos, 409);
 *  - orden de bloqueo: obligación → transacción; el total confirmado se refleja en `matriculas.monto_pagado` (derivado del libro);
 *  - la auditoría guarda ids e importes; jamás referencias ni motivos (texto libre).
 */
class GestionPagos
{
    public const MAX_CENTIMOS = 9_999_999_999;

    public const MAX_CUOTAS = 24;

    /** Convierte «123.45» (hasta 8 enteros y 2 decimales, > 0) en céntimos; si no es válido, error de validación en `$campo`. */
    public static function centimos(mixed $valor, string $campo): int
    {
        if ((! is_string($valor) && ! is_int($valor) && ! is_float($valor)) || ! preg_match('/\A(0|[1-9]\d{0,7})(\.\d{1,2})?\z/', (string) $valor)) {
            throw ValidationException::withMessages([$campo => 'Indica un importe válido (hasta 8 enteros y 2 decimales).']);
        }
        [$e, $d] = array_pad(explode('.', (string) $valor), 2, '0');
        $c = (int) $e * 100 + (int) str_pad($d, 2, '0');
        if ($c <= 0) {
            throw ValidationException::withMessages([$campo => 'El importe debe ser mayor que cero.']);
        }

        return $c;
    }

    public static function formato(int $centimos): string
    {
        return number_format($centimos / 100, 2, '.', '');
    }

    /** Huella de la intención de pago (misma clave + misma huella = repetición idempotente). */
    public static function huella(string $metodo, int $centimos, string $fechaPago, ?string $referencia, bool $confirmar): string
    {
        return hash('sha256', json_encode([$metodo, $centimos, $fechaPago, $referencia, $confirmar], JSON_THROW_ON_ERROR));
    }

    /** Céntimos de un importe leído de la base (numérico o texto). */
    public static function leer(mixed $valor): int
    {
        return (int) round((Entrada::decimal($valor)) * 100);
    }

    /**
     * @param  array{concepto: string, plan: string, monto_total: mixed, cuotas?: list<array{monto: mixed, vence: string}>}  $datos
     */
    public function crearObligacion(Request $request, int $matriculaId, array $datos): ObligacionPago
    {
        return DB::transaction(function () use ($request, $matriculaId, $datos): ObligacionPago {
            $matricula = Matricula::query()->lockForUpdate()->findOrFail($matriculaId);
            $autor = $this->autorizar($request, 'pagos.planificar');

            return $this->crearObligacionBloqueada($matricula, $autor, $datos);
        });
    }

    /**
     * Registra un pago sobre una MATRÍCULA. Si todavía no tiene obligación vigente, la crea en la misma transacción como pago
     * completo por el precio del curso: quien cobra no planifica nada, solo sube lo que la persona pagó. Basta `pagos.registrar`
     * (crear la obligación aquí es una consecuencia del pago, no una planificación); confirmar sigue exigiendo `pagos.confirmar`.
     * Reutiliza {@see self::registrarPago()}: mismas reglas de importe, saldo, fecha, método e idempotencia.
     *
     * @param  array{metodo: string, monto: mixed, fecha_pago: string, referencia?: ?string, confirmar?: bool}  $datos
     * @return array{0: TransaccionPago, 1: ObligacionPago, 2: bool} el pago, su obligación y si se creó ahora (false = repetición idempotente)
     */
    public function registrarPagoDeMatricula(Request $request, int $matriculaId, array $datos, ?string $clave): array
    {
        if ($clave === null || ! preg_match('/\A[A-Za-z0-9_-]{8,64}\z/', $clave)) {
            abort(428, 'Envía una Idempotency-Key (8 a 64 caracteres) por cada intención de pago.');
        }

        return DB::transaction(function () use ($request, $matriculaId, $datos, $clave): array {
            // Orden de bloqueo: matrícula → obligación (dentro de registrarPago) → transacción.
            $matricula = Matricula::query()->lockForUpdate()->findOrFail($matriculaId);
            $autor = $this->autorizar($request, 'pagos.registrar');

            $obligacion = ObligacionPago::query()->where('matricula_id', $matricula->id)->where('estado', 'vigente')->first();
            if ($obligacion === null) {
                $obligacion = $this->crearObligacionAutomaticaBloqueada($matricula, $autor);
            }

            [$t, $creada] = $this->registrarPago($request, $obligacion->identificador, $datos, $clave);

            return [$t, $obligacion, $creada];
        });
    }

    /**
     * Regulariza en 1 clic una matrícula activa sin obligación vigente, generando su deuda por el precio del curso.
     *
     * @return array{0: ObligacionPago, 1: bool} obligación y si fue creada ahora (false = ya existía)
     */
    public function generarObligacionAutomatica(Request $request, int $matriculaId): array
    {
        return DB::transaction(function () use ($request, $matriculaId): array {
            $matricula = Matricula::query()->with(['curso', 'convocatoria'])->lockForUpdate()->findOrFail($matriculaId);
            $autor = $this->autorizar($request, 'pagos.registrar');

            $existente = ObligacionPago::query()->where('matricula_id', $matricula->id)->where('estado', 'vigente')->first();
            if ($existente !== null) {
                return [$existente, false];
            }

            return [$this->crearObligacionAutomaticaBloqueada($matricula, $autor), true];
        });
    }

    /**
     * Crea automáticamente la obligación de pago dentro de la transacción de matrícula nueva (si el curso tiene precio > 0).
     */
    public function crearObligacionDesdeMatricula(Matricula $matricula, int $actorId): ?ObligacionPago
    {
        if ($matricula->trashed() || $matricula->estado === 'anulada') {
            return null;
        }
        if (ObligacionPago::query()->where('matricula_id', $matricula->id)->where('estado', 'vigente')->exists()) {
            return null;
        }
        $curso = $matricula->curso;
        if ($curso === null) {
            return null;
        }
        $precio = self::leer($curso->precio);
        if ($precio <= 0) {
            return null;
        }
        $autor = User::query()->find($actorId);
        if ($autor === null) {
            return null;
        }

        return $this->crearObligacionAutomaticaBloqueada($matricula, $autor);
    }

    private function crearObligacionAutomaticaBloqueada(Matricula $matricula, User $autor): ObligacionPago
    {
        abort_if($matricula->trashed() || $matricula->estado === 'anulada', 409, 'La matrícula no admite pagos.');
        $curso = $matricula->curso;
        abort_if($curso === null, 409, 'La matrícula no tiene un curso asociado.');
        $precio = self::leer($curso->precio);
        if ($precio <= 0) {
            throw ValidationException::withMessages(['monto' => 'El curso no tiene un precio definido: define el precio del curso antes de generar el cobro.']);
        }
        $prefijo = $matricula->convocatoria !== null
            ? 'Matrícula · '.$matricula->convocatoria->codigo.' '
            : 'Matrícula · '.$curso->codigo.' ';
        $concepto = mb_substr($prefijo.$curso->nombre, 0, 120);

        return $this->crearObligacionBloqueada($matricula, $autor, [
            'concepto' => trim($concepto),
            'plan' => 'completo',
            'monto_total' => self::formato($precio),
        ]);
    }

    /**
     * Crea la obligación con la matrícula ya bloqueada y la persona ya autorizada (el permiso lo comprueba quien llama).
     *
     * @param  array{concepto: string, plan: string, monto_total: mixed, cuotas?: list<array{monto: mixed, vence: string}>}  $datos
     */
    private function crearObligacionBloqueada(Matricula $matricula, User $autor, array $datos): ObligacionPago
    {
        abort_if($matricula->trashed() || $matricula->estado === 'anulada', 409, 'La matrícula no admite obligaciones de pago.');
        if (ObligacionPago::query()->where('matricula_id', $matricula->id)->where('estado', 'vigente')->exists()) {
            throw ValidationException::withMessages(['plan' => 'La matrícula ya tiene una obligación de pago vigente.']);
        }

        $total = self::centimos($datos['monto_total'], 'monto_total');
        $cuotas = [];
        if ($datos['plan'] === 'completo') {
            if (! empty($datos['cuotas'])) {
                throw ValidationException::withMessages(['cuotas' => 'El pago completo no lleva cuotas.']);
            }
            $cuotas[] = ['monto' => $total, 'vence' => CarbonImmutable::now('America/Lima')->toDateString()];
        } else {
            $lista = $datos['cuotas'] ?? [];
            if (count($lista) < 2 || count($lista) > self::MAX_CUOTAS) {
                throw ValidationException::withMessages(['cuotas' => 'Un plan fraccionado lleva entre 2 y '.self::MAX_CUOTAS.' cuotas.']);
            }
            $previa = '';
            foreach ($lista as $i => $c) {
                $vence = $c['vence'];
                if ($vence < $previa) {
                    throw ValidationException::withMessages(["cuotas.{$i}.vence" => 'Las cuotas deben ir en orden de vencimiento.']);
                }
                $previa = $vence;
                $cuotas[] = ['monto' => self::centimos($c['monto'] ?? null, "cuotas.{$i}.monto"), 'vence' => $vence];
            }
            if (array_sum(array_column($cuotas, 'monto')) !== $total) {
                throw ValidationException::withMessages(['cuotas' => 'La suma de las cuotas debe igualar el monto total.']);
            }
        }

        $obligacion = new ObligacionPago;
        $obligacion->identificador = (string) Str::uuid();
        $obligacion->matricula_id = $matricula->id;
        $obligacion->concepto = $datos['concepto'];
        $obligacion->plan = $datos['plan'];
        $obligacion->monto_total = self::formato($total);
        $obligacion->moneda = 'PEN';
        $obligacion->estado = 'vigente';
        $obligacion->creado_por = $autor->id;
        $obligacion->save();
        foreach ($cuotas as $n => $c) {
            CuotaObligacion::query()->create(['obligacion_pago_id' => $obligacion->id, 'numero' => $n + 1, 'monto' => self::formato($c['monto']), 'vence' => $c['vence']]);
        }
        AuditLog::record('obligacion_pago_creada', ObligacionPago::class, $obligacion->id, ['matricula' => $matricula->id, 'plan' => $obligacion->plan, 'monto_total' => $obligacion->monto_total, 'cuotas' => count($cuotas)], $autor->id);

        return $obligacion;
    }

    public function anularObligacion(Request $request, string $identificador, string $motivo): ObligacionPago
    {
        return DB::transaction(function () use ($request, $identificador, $motivo): ObligacionPago {
            $obligacion = $this->bloquearObligacion($identificador);
            $autor = $this->autorizar($request, 'pagos.planificar');
            if ($obligacion->estado === 'anulada') {
                return $obligacion;
            }
            $s = $this->saldos($obligacion);
            abort_if($s['confirmado'] !== 0 || $s['pendiente'] !== 0, 409, 'La obligación tiene pagos: reviértelos o recházalos antes de anularla.');

            $obligacion->estado = 'anulada';
            $obligacion->motivo_anulacion = $motivo;
            $obligacion->save();
            AuditLog::record('obligacion_pago_anulada', ObligacionPago::class, $obligacion->id, ['matricula' => $obligacion->matricula_id], $autor->id);

            return $obligacion;
        });
    }

    /**
     * @param  array{metodo: string, monto: mixed, fecha_pago: string, referencia?: ?string, confirmar?: bool}  $datos
     * @return array{0: TransaccionPago, 1: bool} la transacción y si se creó ahora (false = repetición idempotente)
     */
    public function registrarPago(Request $request, string $identificador, array $datos, ?string $clave): array
    {
        if ($clave === null || ! preg_match('/\A[A-Za-z0-9_-]{8,64}\z/', $clave)) {
            abort(428, 'Envía una Idempotency-Key (8 a 64 caracteres) por cada intención de pago.');
        }
        $confirmar = (bool) ($datos['confirmar'] ?? false);
        $monto = self::centimos($datos['monto'], 'monto');
        $huella = self::huella($datos['metodo'], $monto, $datos['fecha_pago'], $datos['referencia'] ?? null, $confirmar);

        return DB::transaction(function () use ($request, $identificador, $datos, $clave, $confirmar, $monto, $huella): array {
            $obligacion = $this->bloquearObligacion($identificador);
            $autor = $this->autorizar($request, 'pagos.registrar');
            if ($confirmar) {
                $this->autorizar($request, 'pagos.confirmar');
            }

            $previa = TransaccionPago::query()->where('obligacion_pago_id', $obligacion->id)->where('clave_idempotencia', $clave)->first();
            if ($previa !== null) {
                abort_unless(hash_equals((string) $previa->huella, $huella), 409, 'Esa Idempotency-Key ya se usó con otros datos.');

                return [$previa, false];
            }

            abort_unless($obligacion->estado === 'vigente', 409, 'La obligación está anulada.');
            $metodo = MetodoPago::query()->where('codigo', $datos['metodo'])->where('activo', true)->first();
            if ($metodo === null) {
                throw ValidationException::withMessages(['metodo' => 'El método de pago no existe o está inactivo.']);
            }
            if ($datos['fecha_pago'] > CarbonImmutable::now('America/Lima')->toDateString()) {
                throw ValidationException::withMessages(['fecha_pago' => 'La fecha de pago no puede ser futura.']);
            }
            $s = $this->saldos($obligacion);
            if ($monto > $s['saldo']) {
                throw ValidationException::withMessages(['monto' => 'El importe excede el saldo disponible ('.self::formato($s['saldo']).').']);
            }

            $t = new TransaccionPago;
            $t->identificador = (string) Str::uuid();
            $t->obligacion_pago_id = $obligacion->id;
            $t->metodo_pago_id = $metodo->id;
            $t->tipo = 'pago';
            $t->estado = 'pendiente';
            $t->monto = self::formato($monto);
            $t->fecha_pago = $datos['fecha_pago'];
            $t->referencia = isset($datos['referencia']) ? (trim((string) $datos['referencia']) ?: null) : null;
            $t->clave_idempotencia = $clave;
            $t->huella = $huella;
            $t->registrado_por = $autor->id;
            $t->save();
            AuditLog::record('pago_registrado', TransaccionPago::class, $t->id, ['obligacion' => $obligacion->id, 'monto' => $t->monto], $autor->id);

            if ($confirmar) {
                $this->aplicarConfirmacion($obligacion, $t, $autor);
            }

            return [$t, true];
        });
    }

    /**
     * Adjunta (o reemplaza) la captura del comprobante de un pago `pendiente`. El archivo anterior se borra solo después de guardar el
     * nuevo; si la transacción falla, se borra el nuevo y queda el anterior.
     */
    public function adjuntarComprobante(Request $request, string $identificador, UploadedFile $archivo): TransaccionPago
    {
        $comprobantes = app(ComprobantesPago::class);
        $guardado = null;
        $anterior = null;

        try {
            $t = DB::transaction(function () use ($request, $identificador, $archivo, $comprobantes, &$guardado, &$anterior): TransaccionPago {
                [$obligacion, $t] = $this->bloquearTransaccion($identificador);
                $autor = $this->autorizar($request, 'pagos.registrar');
                abort_unless($t->tipo === 'pago' && $t->estado === 'pendiente', 409, 'El comprobante solo se adjunta a un pago por confirmar.');
                abort_unless($obligacion->estado === 'vigente', 409, 'La obligación está anulada.');

                $guardado = $comprobantes->guardar($archivo, permitirPdf: true);
                $existente = ComprobantePago::query()->where('transaccion_pago_id', $t->id)->first();
                $anterior = $existente?->ruta;
                $existente?->delete();
                ComprobantePago::query()->create([
                    'transaccion_pago_id' => $t->id, 'ruta' => $guardado['ruta'], 'mime' => $guardado['mime'], 'tamano' => $guardado['tamano'],
                    'sha256' => $guardado['sha256'], 'subido_por' => $autor->id,
                ]);
                AuditLog::record('comprobante_adjuntado', TransaccionPago::class, $t->id, ['obligacion' => $obligacion->id, 'reemplazo' => $anterior !== null], $autor->id);
                $repetida = $this->imagenesRepetidas([$t->id])[$t->id] ?? null;
                if ($repetida !== null) {
                    AuditLog::record('comprobante_imagen_repetida', TransaccionPago::class, $t->id, ['obligacion' => $obligacion->id, 'coincide_con_matricula' => $repetida['matricula_id']], $autor->id);
                }

                return $t;
            });
        } catch (\Throwable $error) {
            $comprobantes->borrar($guardado['ruta'] ?? null);

            throw $error;
        }
        $comprobantes->borrar($anterior);

        return $t;
    }

    /**
     * Pagos cuya captura es EXACTAMENTE la misma imagen (misma huella `sha256`) que la de otro pago vigente (pendiente o confirmado y
     * no reversado). Solo avisa: la misma captura en dos pagos casi siempre es un error o un intento de cobrar dos veces, pero la
     * decisión es de quien confirma. Una captura recomprimida o recortada tiene otra huella y no se detecta aquí.
     *
     * @param  list<int>  $transaccionIds
     * @return array<int, array{matricula_id: int, fecha_pago: string}> por id de transacción, la coincidencia más reciente
     */
    public function imagenesRepetidas(array $transaccionIds): array
    {
        if ($transaccionIds === []) {
            return [];
        }
        /** @var array<int, string> $propias */
        $propias = [];
        foreach (DB::table('comprobantes_pago')->whereIn('transaccion_pago_id', $transaccionIds)->get(['transaccion_pago_id', 'sha256']) as $fila) {
            $propias[(int) $fila->transaccion_pago_id] = (string) $fila->sha256;
        }
        if ($propias === []) {
            return [];
        }

        $otros = $this->pagosVigentesConHuella(array_values(array_unique($propias)));

        $resultado = [];
        foreach ($propias as $id => $sha) {
            foreach ($otros as $otro) {
                if ((string) $otro->sha256 === $sha && (int) $otro->transaccion_pago_id !== $id) {
                    $resultado[$id] = ['matricula_id' => (int) $otro->matricula_id, 'fecha_pago' => substr((string) $otro->fecha_pago, 0, 10)];

                    break;
                }
            }
        }

        return $resultado;
    }

    /**
     * ¿Ya hay un pago vigente con esta misma imagen (huella `sha256` de lo guardado)? Para los flujos que confirman en el acto, donde
     * un aviso llegaría tarde.
     *
     * @return array{matricula_id: int, fecha_pago: string}|null
     */
    public function imagenYaUsada(string $sha256): ?array
    {
        $otro = $this->pagosVigentesConHuella([$sha256])->first();

        return $otro === null ? null : ['matricula_id' => (int) $otro->matricula_id, 'fecha_pago' => substr((string) $otro->fecha_pago, 0, 10)];
    }

    /**
     * Comprobantes de pagos vigentes (pendientes o confirmados y no reversados) con alguna de las huellas, el más reciente primero.
     *
     * @param  list<string>  $huellas
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function pagosVigentesConHuella(array $huellas): \Illuminate\Support\Collection
    {
        return DB::table('comprobantes_pago as c')
            ->join('transacciones_pago as t', 't.id', '=', 'c.transaccion_pago_id')
            ->join('obligaciones_pago as o', 'o.id', '=', 't.obligacion_pago_id')
            ->whereIn('c.sha256', $huellas)
            ->where('t.tipo', 'pago')
            ->whereIn('t.estado', ['pendiente', 'confirmada'])
            ->whereNotExists(function (\Illuminate\Database\Query\Builder $q): void {
                $q->from('transacciones_pago as rev')->whereColumn('rev.reversa_de_id', 't.id')->where('rev.estado', 'confirmada');
            })
            ->orderByDesc('t.id')
            ->get(['c.sha256', 'c.transaccion_pago_id', 'o.matricula_id', 't.fecha_pago']);
    }

    /**
     * Bytes y tipo del comprobante de una transacción (con constancia de la consulta, sin la ruta).
     *
     * @return array{bytes: string, tipo: string}
     */
    public function leerComprobante(Request $request, string $identificador): array
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);
        $t = TransaccionPago::query()->where('identificador', strtolower($identificador))->firstOrFail();
        $autor = $this->autorizar($request, 'pagos.ver');
        $fila = ComprobantePago::query()->where('transaccion_pago_id', $t->id)->first();
        $contenido = $fila === null ? null : app(ComprobantesPago::class)->contenido($fila->ruta);
        abort_if($contenido === null, 404);
        AuditLog::record('comprobante_consultado', TransaccionPago::class, $t->id, ['obligacion' => $t->obligacion_pago_id], $autor->id);

        return $contenido;
    }

    public function confirmar(Request $request, string $identificador): TransaccionPago
    {
        return DB::transaction(function () use ($request, $identificador): TransaccionPago {
            [$obligacion, $t] = $this->bloquearTransaccion($identificador);
            $autor = $this->autorizar($request, 'pagos.confirmar');
            if ($t->tipo === 'pago' && $t->estado === 'confirmada') {
                return $t;
            }
            abort_unless($t->tipo === 'pago' && $t->estado === 'pendiente', 409, 'Solo se confirma un pago pendiente.');
            abort_unless($obligacion->estado === 'vigente', 409, 'La obligación está anulada.');
            abort_if($t->registrado_por === $autor->id && ! $autor->hasRole('admin'), 403, 'Quien registra un pago no puede confirmarlo.');
            abort_unless(ComprobantePago::query()->where('transaccion_pago_id', $t->id)->exists(), 409, 'Adjunta la captura del comprobante de pago antes de confirmar.');

            $this->aplicarConfirmacion($obligacion, $t, $autor);

            return $t;
        });
    }

    public function rechazar(Request $request, string $identificador, string $motivo): TransaccionPago
    {
        return DB::transaction(function () use ($request, $identificador, $motivo): TransaccionPago {
            [, $t] = $this->bloquearTransaccion($identificador);
            $autor = $this->autorizar($request, 'pagos.confirmar');
            if ($t->tipo === 'pago' && $t->estado === 'rechazada') {
                return $t;
            }
            abort_unless($t->tipo === 'pago' && $t->estado === 'pendiente', 409, 'Solo se rechaza un pago pendiente.');

            $t->estado = 'rechazada';
            $t->motivo = $motivo;
            $t->resuelto_por = $autor->id;
            $t->resuelto_en = now();
            $t->save();
            AuditLog::record('pago_rechazado', TransaccionPago::class, $t->id, ['obligacion' => $t->obligacion_pago_id], $autor->id);

            return $t;
        });
    }

    /** Compensa un pago confirmado con otra transacción (`reverso`); el original no se toca. */
    public function reversar(Request $request, string $identificador, string $motivo): TransaccionPago
    {
        return DB::transaction(function () use ($request, $identificador, $motivo): TransaccionPago {
            [$obligacion, $original] = $this->bloquearTransaccion($identificador);
            $autor = $this->autorizar($request, 'pagos.reversar');
            abort_unless($original->tipo === 'pago' && $original->estado === 'confirmada', 409, 'Solo se reversa un pago confirmado.');
            $existente = TransaccionPago::query()->where('reversa_de_id', $original->id)->first();
            if ($existente !== null) {
                return $existente;
            }

            // Desde el umbral, el reverso exige la aprobación de Gerencia (una por reverso; se consume dentro de esta transacción).
            $aprobacion = app(AprobacionReverso::class);
            if ($aprobacion->requerida(self::leer($original->monto))) {
                $aprobacion->consumir($original);
            }

            $r = new TransaccionPago;
            $r->identificador = (string) Str::uuid();
            $r->obligacion_pago_id = $obligacion->id;
            $r->metodo_pago_id = $original->metodo_pago_id;
            $r->tipo = 'reverso';
            $r->estado = 'confirmada';
            $r->monto = $original->monto;
            $r->fecha_pago = CarbonImmutable::now('America/Lima')->toDateString();
            $r->motivo = $motivo;
            $r->reversa_de_id = $original->id;
            $r->registrado_por = $autor->id;
            $r->resuelto_por = $autor->id;
            $r->resuelto_en = now();
            $r->save();
            foreach (DB::table('asignaciones_pago')->where('transaccion_pago_id', $original->id)->get() as $a) {
                DB::table('asignaciones_pago')->insert(['transaccion_pago_id' => $r->id, 'cuota_obligacion_id' => $a->cuota_obligacion_id,
                    'monto' => self::formato(-self::leer($a->monto)), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->sincronizarMonto($obligacion);
            AuditLog::record('pago_reversado', TransaccionPago::class, $r->id, ['obligacion' => $obligacion->id, 'original' => $original->id, 'monto' => $r->monto], $autor->id);

            return $r;
        });
    }

    /**
     * Cifras de la obligación en céntimos: `confirmado` (neto de reversos), `pendiente` (pagos por confirmar) y `saldo` (lo que aún
     * se puede registrar).
     *
     * @return array{total: int, confirmado: int, pendiente: int, saldo: int}
     */
    public function saldos(ObligacionPago $obligacion): array
    {
        $atributos = $obligacion->getAttributes();
        $total = self::leer($obligacion->monto_total);
        if (
            array_key_exists('monto_confirmado_calculado', $atributos)
            && array_key_exists('monto_pendiente_calculado', $atributos)
            && $atributos['monto_confirmado_calculado'] !== null
            && $atributos['monto_pendiente_calculado'] !== null
        ) {
            $confirmado = self::leer($atributos['monto_confirmado_calculado']);
            $pendiente = self::leer($atributos['monto_pendiente_calculado']);

            return ['total' => $total, 'confirmado' => $confirmado, 'pendiente' => $pendiente, 'saldo' => max(0, $total - $confirmado - $pendiente)];
        }

        $confirmado = 0;
        $pendiente = 0;
        $transacciones = $obligacion->relationLoaded('transacciones')
            ? $obligacion->transacciones
            : TransaccionPago::query()->where('obligacion_pago_id', $obligacion->id)->get(['tipo', 'estado', 'monto']);
        foreach ($transacciones as $t) {
            $m = self::leer($t->monto);
            if ($t->estado === 'confirmada') {
                $confirmado += $t->tipo === 'pago' ? $m : -$m;
            } elseif ($t->estado === 'pendiente') {
                $pendiente += $m;
            }
        }

        return ['total' => $total, 'confirmado' => $confirmado, 'pendiente' => $pendiente, 'saldo' => max(0, $total - $confirmado - $pendiente)];
    }

    /**
     * Lo pagado de cada cuota (neto de reversos), por id de cuota, en céntimos.
     *
     * @return array<int, int>
     */
    public function pagadoPorCuota(ObligacionPago $obligacion): array
    {
        $porCuota = [];
        $filas = DB::table('asignaciones_pago as a')->join('cuotas_obligacion as c', 'c.id', '=', 'a.cuota_obligacion_id')
            ->where('c.obligacion_pago_id', $obligacion->id)->get(['a.cuota_obligacion_id', 'a.monto']);
        foreach ($filas as $f) {
            $porCuota[$f->cuota_obligacion_id] = ($porCuota[$f->cuota_obligacion_id] ?? 0) + self::leer($f->monto);
        }

        return $porCuota;
    }

    private function aplicarConfirmacion(ObligacionPago $obligacion, TransaccionPago $t, User $autor): void
    {
        // Reparte el importe entre las cuotas, de la más antigua a la más reciente, sobre lo que cada una aún debe.
        $pagado = $this->pagadoPorCuota($obligacion);
        $restante = self::leer($t->monto);
        foreach (CuotaObligacion::query()->where('obligacion_pago_id', $obligacion->id)->orderBy('numero')->get() as $cuota) {
            $debe = self::leer($cuota->monto) - ($pagado[$cuota->id] ?? 0);
            if ($debe <= 0 || $restante <= 0) {
                continue;
            }
            $aplicar = min($debe, $restante);
            DB::table('asignaciones_pago')->insert(['transaccion_pago_id' => $t->id, 'cuota_obligacion_id' => $cuota->id, 'monto' => self::formato($aplicar), 'created_at' => now(), 'updated_at' => now()]);
            $restante -= $aplicar;
        }
        abort_if($restante !== 0, 409, 'El importe excede lo adeudado en las cuotas.');

        $t->estado = 'confirmada';
        $t->resuelto_por = $autor->id;
        $t->resuelto_en = now();
        $t->save();
        $this->sincronizarMonto($obligacion);
        AuditLog::record('pago_confirmado', TransaccionPago::class, $t->id, ['obligacion' => $obligacion->id, 'monto' => $t->monto], $autor->id);
    }

    /** `matriculas.monto_pagado` es un reflejo del libro: total confirmado neto de la obligación vigente. */
    private function sincronizarMonto(ObligacionPago $obligacion): void
    {
        $s = $this->saldos($obligacion);
        $cambios = ['monto_pagado' => self::formato($s['confirmado']), 'updated_at' => now()];

        // El estado también se deriva del libro: «pagada» cuando lo confirmado neto cubre el total y «pendiente» si un reverso lo
        // deshace. Nunca toca una matrícula anulada. Actualización directa: no dispara los avisos de bienvenida de matrícula.
        $matricula = DB::table('matriculas')->where('id', $obligacion->matricula_id)->first(['estado']);
        $nuevo = $s['confirmado'] >= $s['total'] ? 'pagada' : 'pendiente';
        if ($matricula !== null && $matricula->estado !== 'anulada' && $matricula->estado !== $nuevo) {
            $cambios['estado'] = $nuevo;
            AuditLog::record('matricula_estado_derivado', Matricula::class, $obligacion->matricula_id, ['estado_anterior' => $matricula->estado, 'estado_nuevo' => $nuevo, 'obligacion' => $obligacion->id]);
        }
        DB::table('matriculas')->where('id', $obligacion->matricula_id)->update($cambios);
    }

    private function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->can($permiso), 403);

        return $actor;
    }

    private function bloquearObligacion(string $identificador): ObligacionPago
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return ObligacionPago::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    /** @return array{0: ObligacionPago, 1: TransaccionPago} obligación → transacción, ambas bloqueadas */
    private function bloquearTransaccion(string $identificador): array
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);
        $previa = TransaccionPago::query()->where('identificador', strtolower($identificador))->firstOrFail();
        $obligacion = ObligacionPago::query()->lockForUpdate()->findOrFail($previa->obligacion_pago_id);
        $t = TransaccionPago::query()->lockForUpdate()->findOrFail($previa->id);

        return [$obligacion, $t];
    }
}
