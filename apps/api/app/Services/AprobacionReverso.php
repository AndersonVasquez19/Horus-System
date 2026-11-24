<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\TransaccionPago;
use App\Models\User;
use App\Support\Entrada;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprobación interna de reversos de pago por umbral (Fase 3.10). Un reverso compensa dinero ya confirmado, así que desde
 * `config('panel.aprobacion_reverso_umbral')` soles (S/ 100 por defecto; 0 la apaga) exige la aprobación de Gerencia (rol admin)
 * por el módulo de Aprobaciones: se solicita con un motivo, otra persona con rol admin la aprueba (nadie decide sobre su propia
 * solicitud) y sirve para un solo reverso. Por debajo del umbral el reverso sigue como antes.
 *
 * El sistema no tiene descuentos (docs de finanzas: «sin descuentos ni mora»): esta regla cubre los reversos, que es donde hoy se
 * mueve dinero hacia atrás. Anular una obligación exige que no tenga pagos, así que no mueve dinero y no entra en el umbral.
 */
class AprobacionReverso
{
    /** Umbral en céntimos; 0 = sin aprobación. */
    public function umbral(): int
    {
        return max(0, GestionPagos::leer(config('panel.aprobacion_reverso_umbral', 100)));
    }

    public function requerida(int $centimos): bool
    {
        $umbral = $this->umbral();

        return $umbral > 0 && $centimos >= $umbral;
    }

    /** Solicita la aprobación del reverso de un pago confirmado. Una solicitud pendiente por transacción. */
    public function solicitar(User $actor, TransaccionPago $original, string $motivo): AprobacionInterna
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Explica el motivo del reverso (mínimo 5 caracteres).']);
        }

        return DB::transaction(function () use ($actor, $original, $motivo): AprobacionInterna {
            $bloqueada = TransaccionPago::query()->lockForUpdate()->findOrFail($original->id);
            abort_unless($bloqueada->tipo === 'pago' && $bloqueada->estado === 'confirmada', 409, 'Solo se reversa un pago confirmado.');
            $monto = GestionPagos::leer($bloqueada->monto);
            abort_unless($this->requerida($monto), 409, 'Este reverso no requiere aprobación.');
            abort_if(TransaccionPago::query()->where('reversa_de_id', $bloqueada->id)->exists(), 409, 'Este pago ya fue reversado.');
            $referencia = self::referencia($bloqueada);
            abort_if(
                AprobacionInterna::query()->where('referencia', $referencia)->where('estado', AprobacionInterna::ESTADO_PENDIENTE)->exists(),
                409,
                'Ya hay una solicitud de reverso pendiente para este pago.',
            );

            $solicitud = app(GestionAprobaciones::class)->registrar($actor, [
                'titulo' => 'Reverso de pago de S/ '.GestionPagos::formato($monto),
                'tipo' => AprobacionInterna::TIPO_REVERSO_PAGO,
                'descripcion' => $motivo,
                'referencia' => $referencia,
            ]);
            AuditLog::record('reverso_aprobacion_solicitada', TransaccionPago::class, $bloqueada->id, ['aprobacion' => $solicitud->id, 'monto' => GestionPagos::formato($monto)], $actor->id);

            return $solicitud;
        });
    }

    /**
     * Consume la aprobación vigente del reverso (se llama dentro de la transacción de reversar). Falla con 409 si no hay una
     * aprobada; al usarla la marca para que no sirva otra vez.
     */
    public function consumir(TransaccionPago $original): void
    {
        $aprobacion = AprobacionInterna::query()->lockForUpdate()
            ->where('referencia', self::referencia($original))
            ->where('estado', AprobacionInterna::ESTADO_APROBADO)
            ->orderByDesc('id')->first();
        abort_if(
            $aprobacion === null,
            409,
            'Un reverso de S/ '.GestionPagos::formato($this->umbral()).' o más requiere la aprobación de Gerencia. Solicítala primero.',
        );

        $aprobacion->referencia = self::referencia($original).':usada';
        $aprobacion->save();
    }

    /**
     * Estado de la aprobación de cada transacción dada (por identificador): `pendiente`, `aprobado`, `rechazado` o `ninguna`.
     * Solo para pagos que la requieren; una consulta para todos.
     *
     * @param  iterable<TransaccionPago>  $transacciones
     * @return array<string, array{requerida: bool, estado: string}>
     */
    public function estados(iterable $transacciones): array
    {
        $referencias = [];
        $requeridas = [];
        foreach ($transacciones as $t) {
            if ($t->tipo === 'pago' && $t->estado === 'confirmada' && $this->requerida(GestionPagos::leer($t->monto))) {
                $referencias[self::referencia($t)] = $t->identificador;
                $requeridas[$t->identificador] = true;
            }
        }
        $estados = [];
        if ($referencias !== []) {
            foreach (AprobacionInterna::query()->whereIn('referencia', array_keys($referencias))->orderBy('id')->get(['referencia', 'estado']) as $a) {
                $estados[$referencias[Entrada::texto($a->referencia)]] = Entrada::texto($a->estado);
            }
        }

        $salida = [];
        foreach ($requeridas as $identificador => $_) {
            $salida[$identificador] = ['requerida' => true, 'estado' => $estados[$identificador] ?? 'ninguna'];
        }

        return $salida;
    }

    private static function referencia(TransaccionPago $t): string
    {
        return 'reverso:'.$t->identificador;
    }
}
