<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoCertificado;
use App\Models\AuditLog;
use App\Models\Certificado;
use App\Models\Convocatoria;
use App\Models\Matricula;
use App\Models\User;
use App\Support\CodigoSecuencial;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Emisión, anulación y verificación pública de certificados académicos.
 *
 * Reglas:
 *  - Una matrícula anulada o eliminada no puede recibir certificado.
 *  - A lo sumo un certificado en estado `emitido` por matrícula (se bloquea la matrícula con `lockForUpdate`).
 *  - Código secuencial único `HORUS-AAAA-NNNN` tomado de `secuencias_codigo` dentro de la misma transacción.
 *  - La elegibilidad (deuda y, si se configura, edición cerrada) sale de {@see ElegibilidadCertificado}: ante un
 *    bloqueo solo un administrador puede emitir indicando un `motivo_excepcion`.
 *  - La verificación pública jamás expone DNI, correo, teléfono ni nombre completo (solo iniciales abreviadas).
 */
class GestionCertificados
{
    public function __construct(private readonly ElegibilidadCertificado $elegibilidad) {}

    public function emitirParaMatricula(Matricula $matricula, User $actor, ?string $motivoExcepcion = null): Certificado
    {
        $motivoLimpio = $motivoExcepcion !== null ? trim($motivoExcepcion) : '';
        $motivo = $motivoLimpio !== '' ? $motivoLimpio : null;

        $emitido = DB::transaction(function () use ($matricula, $actor, $motivo): Certificado {
            /** @var Matricula $bloqueada */
            $bloqueada = Matricula::query()
                ->with(['estudiante', 'curso', 'convocatoria'])
                ->lockForUpdate()
                ->findOrFail($matricula->id);

            abort_if($bloqueada->estado === 'anulada', 409, 'No se puede emitir un certificado para una matrícula anulada.');

            $vigente = Certificado::query()
                ->where('matricula_id', $bloqueada->id)
                ->where('estado', EstadoCertificado::Emitido->value)
                ->first();

            abort_if($vigente !== null, 409, "Esta matrícula ya cuenta con el certificado vigente {$vigente?->codigo}.");

            $errores = $this->elegibilidad->bloqueos($bloqueada);
            if ($errores !== []) {
                if ($motivo === null || ! $actor->hasRole('admin')) {
                    throw ValidationException::withMessages(['matricula' => implode(' ', array_column($errores, 'mensaje'))]);
                }
            }

            $codigo = CodigoSecuencial::siguiente('HORUS');

            $certificado = Certificado::query()->create([
                'identificador' => (string) Str::uuid(),
                'codigo' => $codigo,
                'matricula_id' => $bloqueada->id,
                'convocatoria_id' => $bloqueada->convocatoria_id,
                'estudiante_id' => $bloqueada->estudiante_id,
                'curso_id' => $bloqueada->curso_id,
                'estado' => EstadoCertificado::Emitido,
                'emitido_en' => HoraLocal::ahora(),
                'emitido_por' => $actor->id,
                'motivo_excepcion' => $errores !== [] ? $motivo : null,
            ]);

            AuditLog::record(
                'certificado_emitido',
                Certificado::class,
                $certificado->id,
                [
                    'codigo' => $certificado->codigo,
                    'matricula_id' => $bloqueada->id,
                    'convocatoria_id' => $bloqueada->convocatoria_id,
                    'con_excepcion' => $errores !== [],
                    'bloqueos' => array_column($errores, 'codigo'),
                ],
                $actor->id,
            );

            return $certificado->load(['estudiante', 'curso', 'convocatoria', 'emisor']);
        });

        // Fuera de la transacción: un fallo de la cola nunca deshace la emisión.
        app(CorreoCertificados::class)->encolarSiAutomatico($emitido);

        return $emitido;
    }

    /**
     * Emite en lote los certificados de todas las matrículas elegibles de una edición.
     *
     * @return array{emitidos: int, ya_emitidos: int, omitidos: int, omitidos_por_deuda: int}
     */
    public function emitirLoteEdicion(Convocatoria $convocatoria, User $actor): array
    {
        $matriculas = Matricula::query()
            ->with(['estudiante', 'curso', 'convocatoria'])
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado', '!=', 'anulada')
            ->get();

        $emitidosIds = Certificado::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->where('estado', EstadoCertificado::Emitido->value)
            ->pluck('matricula_id')
            ->flip();

        $emitidos = 0;
        $yaEmitidos = 0;
        $omitidos = 0;
        $porDeuda = 0;

        foreach ($matriculas as $matricula) {
            if ($emitidosIds->has($matricula->id)) {
                $yaEmitidos++;
                continue;
            }

            $bloqueos = $this->elegibilidad->bloqueos($matricula);
            if ($bloqueos !== []) {
                $omitidos++;
                $porDeuda += in_array('deuda', array_column($bloqueos, 'codigo'), true) ? 1 : 0;
                continue;
            }

            $this->emitirParaMatricula($matricula, $actor);
            $emitidos++;
        }

        return [
            'emitidos' => $emitidos,
            'ya_emitidos' => $yaEmitidos,
            'omitidos' => $omitidos,
            'omitidos_por_deuda' => $porDeuda,
        ];
    }

    public function anular(Certificado $certificado, User $actor, string $motivo): Certificado
    {
        $motivoLimpio = trim($motivo);
        if (mb_strlen($motivoLimpio) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Indica un motivo de anulación de al menos 5 caracteres.']);
        }

        return DB::transaction(function () use ($certificado, $actor, $motivoLimpio): Certificado {
            /** @var Certificado $bloqueado */
            $bloqueado = Certificado::query()->lockForUpdate()->findOrFail($certificado->id);

            abort_if($bloqueado->estado === EstadoCertificado::Anulado, 409, 'Este certificado ya fue anulado.');

            $bloqueado->estado = EstadoCertificado::Anulado;
            $bloqueado->anulado_en = HoraLocal::ahora();
            $bloqueado->anulado_por = $actor->id;
            $bloqueado->motivo_anulacion = $motivoLimpio;
            $bloqueado->save();

            AuditLog::record(
                'certificado_anulado',
                Certificado::class,
                $bloqueado->id,
                ['codigo' => $bloqueado->codigo, 'matricula_id' => $bloqueado->matricula_id],
                $actor->id,
            );

            return $bloqueado->load(['estudiante', 'curso', 'convocatoria', 'emisor', 'anulador']);
        });
    }

    /**
     * Consulta pública por código sin exponer datos personales (solo iniciales abreviadas).
     *
     * @return array{valido: bool, estado: string, codigo: string, curso: string, fecha_emision: string, titular_abreviado: string}|null
     */
    public function verificarPublico(string $codigo): ?array
    {
        $normalizado = strtoupper(trim($codigo));
        if (preg_match('/\A[A-Z0-9-]{4,32}\z/', $normalizado) !== 1) {
            return null;
        }

        $certificado = Certificado::query()
            ->with(['curso', 'estudiante'])
            ->where('codigo', $normalizado)
            ->first();

        if ($certificado === null) {
            return null;
        }

        return [
            'valido' => $certificado->estado === EstadoCertificado::Emitido,
            'estado' => $certificado->estado->value,
            'codigo' => $certificado->codigo,
            'curso' => $certificado->curso?->nombre ?? 'Curso institucional',
            'fecha_emision' => $certificado->emitido_en->format('Y-m-d'),
            'titular_abreviado' => $certificado->titularAbreviado(),
        ];
    }

    /**
     * Vista previa de la elegibilidad de una matrícula (sin emitir): bloqueos y si el actor puede emitir con excepción.
     *
     * @return array{elegible: bool, bloqueos: list<array{codigo: string, mensaje: string}>, puede_excepcion: bool, certificado_vigente: string|null}
     */
    public function vistaPrevia(Matricula $matricula, User $actor): array
    {
        $matricula->loadMissing(['convocatoria']);
        $bloqueos = $this->elegibilidad->bloqueos($matricula);
        $vigente = Certificado::query()->where('matricula_id', $matricula->id)->where('estado', EstadoCertificado::Emitido->value)->value('codigo');

        return ['elegible' => $bloqueos === [] && $vigente === null, 'bloqueos' => $bloqueos, 'puede_excepcion' => $actor->hasRole('admin'), 'certificado_vigente' => is_string($vigente) ? $vigente : null];
    }

}
