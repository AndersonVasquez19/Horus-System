<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoCertificado;
use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Certificado;
use App\Models\Convocatoria;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reapertura de un acta cerrada (Fase 3.6). Es una excepción: el acta cerrada deja de poder cambiar, así que reabrirla exige
 * (1) una solicitud con motivo, (2) la aprobación de Gerencia (rol admin) por el módulo de Aprobaciones y (3) la reapertura
 * propiamente dicha, que solo puede hacerse una vez por aprobación.
 *
 * Al reabrir: la edición vuelve a `en_curso`, se limpian `nota_final` y `resultado` de sus matrículas (se recalculan al cerrar de
 * nuevo; así un certificado no puede emitirse con una nota que ya no es vigente) y la revisión aumenta. Los certificados ya emitidos
 * NO se anulan: llevan su propia copia de la nota; se informa cuántos hay para que se anulen y reemitan si corresponde.
 */
class ReaperturaActa
{
    public function __construct(private readonly GestionAprobaciones $aprobaciones) {}

    /** Crea la solicitud de aprobación (Gerencia) de reapertura. Una pendiente por edición. */
    public function solicitar(User $actor, string $identificador, string $motivo): AprobacionInterna
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages(['motivo' => 'Explica el motivo de la reapertura (mínimo 10 caracteres).']);
        }

        return DB::transaction(function () use ($actor, $identificador, $motivo): AprobacionInterna {
            $convocatoria = $this->bloquear($identificador);
            abort_unless($convocatoria->estado === 'cerrada', 409, 'Solo se reabre el acta de una edición cerrada.');
            $referencia = self::referencia($convocatoria);
            abort_if(
                AprobacionInterna::query()->where('referencia', $referencia)->where('estado', AprobacionInterna::ESTADO_PENDIENTE)->exists(),
                409,
                'Ya hay una solicitud de reapertura pendiente para esta edición.',
            );

            $solicitud = $this->aprobaciones->registrar($actor, [
                'titulo' => "Reapertura de acta {$convocatoria->codigo}",
                'tipo' => AprobacionInterna::TIPO_REAPERTURA_ACTA,
                'descripcion' => $motivo,
                'referencia' => $referencia,
            ]);
            AuditLog::record('acta_reapertura_solicitada', Convocatoria::class, $convocatoria->id, ['aprobacion' => $solicitud->id], $actor->id);

            return $solicitud;
        });
    }

    /**
     * Reabre el acta con una solicitud aprobada y aún no usada.
     *
     * @return array{certificados_emitidos: int, matriculas: int}
     */
    public function reabrir(User $actor, string $identificador): array
    {
        return DB::transaction(function () use ($actor, $identificador): array {
            $convocatoria = $this->bloquear($identificador);
            abort_unless($convocatoria->estado === 'cerrada', 409, 'Solo se reabre el acta de una edición cerrada.');

            $aprobacion = AprobacionInterna::query()->lockForUpdate()
                ->where('referencia', self::referencia($convocatoria))
                ->where('estado', AprobacionInterna::ESTADO_APROBADO)
                ->orderByDesc('id')->first();
            abort_if($aprobacion === null, 409, 'La reapertura requiere una solicitud aprobada por Gerencia. Solicítala primero.');

            $matriculas = Matricula::query()->where('convocatoria_id', $convocatoria->id)->update(['nota_final' => null, 'resultado' => null]);
            $emitidos = Certificado::query()->where('convocatoria_id', $convocatoria->id)->where('estado', EstadoCertificado::Emitido->value)->count();

            $convocatoria->estado = 'en_curso';
            $convocatoria->revision++;
            $convocatoria->save();
            // Una aprobación sirve para una sola reapertura: se marca como usada (la referencia deja de coincidir).
            $aprobacion->referencia = self::referencia($convocatoria).':usada:'.$convocatoria->revision;
            $aprobacion->save();

            AuditLog::record('acta_reabierta', Convocatoria::class, $convocatoria->id,
                ['aprobacion' => $aprobacion->id, 'matriculas' => $matriculas, 'certificados_emitidos' => $emitidos, 'revision' => $convocatoria->revision], $actor->id);

            return ['certificados_emitidos' => $emitidos, 'matriculas' => $matriculas];
        });
    }

    private function bloquear(string $identificador): Convocatoria
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $identificador) === 1, 404);

        return Convocatoria::query()->lockForUpdate()->where('identificador', strtolower($identificador))->firstOrFail();
    }

    private static function referencia(Convocatoria $convocatoria): string
    {
        return 'acta:'.$convocatoria->identificador;
    }
}
