<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\EnviarReclamacionCorreo;
use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\ReclamacionHistorial;
use App\Models\User;
use App\Support\AuditoriaReclamaciones;
use App\Support\DiasHabiles;
use App\Support\HojaReclamacion;
use App\Support\Reclamaciones;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Gestión del Libro de Reclamaciones (Sprint 20, Fase 2). Toda operación es transaccional, bloquea la
 * reclamación (`lockForUpdate`) y relee el estado bajo bloqueo; la auditoría es la última escritura, de modo
 * que un fallo de auditoría revierte la operación. Los datos del consumidor no se editan y nada se elimina.
 * La recepción pública exige {@see Reclamaciones::habilitado()}; las operaciones del panel exigen permiso.
 */
class GestionReclamaciones
{
    /** Columnas del consumidor y del caso que acepta el alta (lista cerrada: el resto se ignora). */
    private const CAMPOS_ALTA = [
        'tipo', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'domicilio', 'telefono', 'email',
        'canal_respuesta', 'es_menor', 'representante_nombre', 'representante_domicilio', 'representante_telefono',
        'representante_email', 'bien_tipo', 'bien_descripcion', 'monto_bien', 'detalle', 'pedido',
    ];

    public const AVISO_POR_VENCER = 'por_vencer';

    public const AVISO_VENCE = 'vence';

    public const SIN_DESTINATARIOS = 'sin_destinatarios';

    public function __construct(private readonly NumeradorReclamaciones $numerador) {}

    /**
     * Alta pública. Idempotente por `token_envio`: reenviar el mismo formulario devuelve la misma reclamación.
     *
     * @param  array<string, mixed>  $datos
     * @return array{reclamacion: Reclamacion, repetida: bool}
     */
    public function registrarPublica(array $datos, string $tokenEnvio): array
    {
        abort_unless(Reclamaciones::habilitado(), 404);

        return $this->crear($datos, 'web', null, hash('sha256', $tokenEnvio), null);
    }

    /**
     * Ingreso de una hoja del libro físico de respaldo (art. 4-A). Exige `reclamaciones.gestionar`.
     * No genera copia por correo: el consumidor conserva la hoja en papel.
     *
     * @param  array<string, mixed>  $datos
     * @return array{reclamacion: Reclamacion, repetida: bool}
     */
    public function registrarRespaldo(User $actor, array $datos, string $numeroHoja): array
    {
        $this->autorizar($actor, 'reclamaciones.gestionar');
        if (trim($numeroHoja) === '' || mb_strlen($numeroHoja) > 20) {
            throw ValidationException::withMessages(['numero_hoja_respaldo' => 'Indica el número de la hoja física (máximo 20 caracteres).']);
        }

        return $this->crear($datos, 'respaldo', trim($numeroHoja), hash('sha256', 'respaldo:'.Str::uuid()), $actor);
    }

    /**
     * Lectura del detalle (E3): exige `ver` y deja constancia del acceso. Es de SOLO LECTURA sobre la
     * reclamación: no evalúa vencimientos, no bloquea ni guarda el caso (ni `updated_at`) y no escribe historial.
     * Los vencimientos los resuelve el proceso diario `horus:reclamaciones-vencimientos`
     * ({@see self::resolverVencimiento()}); para mostrarlos sin persistir, {@see self::propuestaVencidaSinProcesar()}.
     * Historial y envíos se cargan con dos consultas agrupadas (sin N+1).
     */
    public function consultar(User $actor, string $uuid): Reclamacion
    {
        return $this->leer($actor, $uuid, 'consultada');
    }

    /** Impresión de la hoja (E3): mismas reglas que {@see self::consultar()}, con su propia constancia de acceso. */
    public function imprimir(User $actor, string $uuid): Reclamacion
    {
        return $this->leer($actor, $uuid, 'impresa');
    }

    /**
     * Constancia de la consulta de la bandeja (E3). Solo guarda el actor, los NOMBRES de los filtros usados y la
     * página: nunca el término buscado, nombres, documentos ni otros datos personales.
     *
     * @param  list<string>  $filtros
     */
    public function registrarConsultaBandeja(User $actor, array $filtros, int $pagina): void
    {
        $actor = $this->autorizar($actor, 'reclamaciones.ver');
        AuditoriaReclamaciones::registrarBandeja($actor->id, $filtros, $pagina);
    }

    /**
     * Solo lectura: la propuesta de solución está pendiente y su ventana ya terminó (hoy en Lima es posterior a
     * `suspension_hasta`), pero el proceso diario todavía no reanudó el plazo. Misma regla que aplica
     * {@see self::evaluarVencimiento()}; no modifica nada.
     */
    public function propuestaVencidaSinProcesar(Reclamacion $r): bool
    {
        return $r->estado === 'abierto' && $r->suspension_desde !== null && $r->suspension_hasta !== null
            && $this->hoy()->toDateString() > $r->suspension_hasta->toDateString();
    }

    /** Lectura sin bloqueo ni escrituras sobre la reclamación; la auditoría es la única escritura. */
    private function leer(User $actor, string $uuid, string $accion): Reclamacion
    {
        $actor = $this->autorizar($actor, 'reclamaciones.ver');
        $r = $this->porIdentificador($uuid)->firstOrFail()->load(['historial', 'envios']);
        AuditoriaReclamaciones::registrar($accion, $r, ['numero' => $r->numero], $actor->id);

        return $r;
    }

    public function asignarResponsable(User $actor, string $uuid, ?int $responsableId): Reclamacion
    {
        return DB::transaction(function () use ($actor, $uuid, $responsableId): Reclamacion {
            $actor = $this->autorizar($actor, 'reclamaciones.gestionar');
            $r = $this->bloquear($uuid);
            $this->evaluarVencimiento($r);
            if ($responsableId !== null) {
                $responsable = User::query()->find($responsableId);
                if ($responsable === null || ! $responsable->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES) || ! $responsable->can('reclamaciones.ver')) {
                    throw ValidationException::withMessages(['responsable_id' => 'Selecciona un usuario del panel con permiso para ver reclamaciones.']);
                }
            }
            $anterior = $r->responsable_id;
            $r->forceFill(['responsable_id' => $responsableId])->save();
            $this->historial($r, 'responsable_asignado', $actor, ['anterior' => $anterior, 'responsable_id' => $responsableId]);
            AuditoriaReclamaciones::registrar('responsable', $r, ['anterior' => $anterior, 'responsable_id' => $responsableId], $actor->id);

            return $r;
        });
    }

    public function reclasificarTipo(User $actor, string $uuid, string $tipo): Reclamacion
    {
        return DB::transaction(function () use ($actor, $uuid, $tipo): Reclamacion {
            $actor = $this->autorizar($actor, 'reclamaciones.gestionar');
            if (! in_array($tipo, ['reclamo', 'queja'], true)) {
                throw ValidationException::withMessages(['tipo' => 'Tipo no permitido.']);
            }
            $r = $this->bloquear($uuid);
            $this->evaluarVencimiento($r);
            $this->exigirAbierta($r);
            if ($r->tipo === $tipo) {
                return $r;
            }
            if ($tipo === 'reclamo' && $r->bien_tipo === null) {
                throw ValidationException::withMessages(['tipo' => 'Un reclamo requiere bien o servicio identificado; la queja no lo tiene.']);
            }
            $anterior = $r->tipo;
            $r->forceFill(['tipo' => $tipo])->save();
            $this->historial($r, 'tipo_reclasificado', $actor, ['anterior' => $anterior, 'tipo' => $tipo]);
            AuditoriaReclamaciones::registrar('tipo', $r, ['anterior' => $anterior, 'tipo' => $tipo], $actor->id);

            return $r;
        });
    }

    public function agregarNota(User $actor, string $uuid, string $nota): Reclamacion
    {
        return DB::transaction(function () use ($actor, $uuid, $nota): Reclamacion {
            $actor = $this->autorizar($actor, 'reclamaciones.gestionar');
            $nota = trim($nota);
            if ($nota === '' || mb_strlen($nota) > 2000) {
                throw ValidationException::withMessages(['nota' => 'La nota es obligatoria y admite hasta 2000 caracteres.']);
            }
            $r = $this->bloquear($uuid);
            $this->evaluarVencimiento($r);
            $this->historial($r, 'nota_interna', $actor, [], $nota);
            AuditoriaReclamaciones::registrar('nota', $r, ['numero' => $r->numero], $actor->id);

            return $r;
        });
    }

    /** Propuesta de solución (solo reclamos): suspende el plazo hasta 5 días hábiles. */
    public function enviarPropuesta(User $actor, string $uuid, string $asunto, string $cuerpo): Reclamacion
    {
        $envioId = null;
        $r = DB::transaction(function () use ($actor, $uuid, $asunto, $cuerpo, &$envioId): Reclamacion {
            $actor = $this->autorizar($actor, 'reclamaciones.gestionar');
            $this->textoEnvio($asunto, $cuerpo);
            $r = $this->bloquear($uuid);
            $this->evaluarVencimiento($r);
            $this->exigirAbierta($r);
            abort_unless($r->tipo === 'reclamo', 409, 'Las propuestas de solución solo aplican a reclamos.');
            abort_if($r->suspension_desde !== null, 409, 'Ya existe una propuesta pendiente.');
            $hoy = $this->hoy();
            $hasta = DiasHabiles::sumar($hoy, (int) config('reclamaciones.suspension_dias_habiles', 5));
            $r->forceFill(['suspension_desde' => $hoy->toDateString(), 'suspension_hasta' => $hasta->toDateString()])->save();
            $envio = $this->crearEnvio($r, 'propuesta', 'correo', $asunto, $cuerpo);
            $envioId = $envio->id;
            $this->historial($r, 'propuesta_enviada', $actor, ['envio_id' => $envio->id, 'suspension_desde' => $hoy->toDateString(), 'suspension_hasta' => $hasta->toDateString()]);
            AuditoriaReclamaciones::registrar('propuesta', $r, ['envio_id' => $envio->id, 'envio_tipo' => 'propuesta'], $actor->id);

            return $r;
        });
        $this->despachar($envioId);

        return $r;
    }

    /** Rechazo de la propuesta: reanuda el plazo desplazando `fecha_limite` por los días hábiles suspendidos (máx. 5). */
    public function registrarRechazo(User $actor, string $uuid, string $fechaRecepcion, ?string $nota = null): Reclamacion
    {
        return DB::transaction(function () use ($actor, $uuid, $fechaRecepcion, $nota): Reclamacion {
            $actor = $this->autorizar($actor, 'reclamaciones.gestionar');
            $recepcion = $this->fecha($fechaRecepcion, 'fecha_recepcion');
            $r = $this->bloquear($uuid);
            $this->exigirPropuestaPendiente($r, $recepcion);
            [$dias, $nueva] = $this->reanudar($r, $recepcion);
            $this->historial($r, 'propuesta_rechazada', $actor, ['fecha_recepcion' => $recepcion->toDateString(), 'dias_suspendidos' => $dias, 'fecha_limite_nueva' => $nueva->toDateString()], $this->nota($nota));
            AuditoriaReclamaciones::registrar('propuesta_rechazada', $r, ['dias_suspendidos' => $dias, 'fecha_limite' => $nueva->toDateString()], $actor->id);

            return $r;
        });
    }

    /** Aceptación expresa: concluye el caso por acuerdo (no es una respuesta formal). Exige `responder`. */
    public function registrarAceptacion(User $actor, string $uuid, string $fechaRecepcion, string $medio, string $constancia): Reclamacion
    {
        return DB::transaction(function () use ($actor, $uuid, $fechaRecepcion, $medio, $constancia): Reclamacion {
            $actor = $this->autorizar($actor, 'reclamaciones.responder');
            $recepcion = $this->fecha($fechaRecepcion, 'fecha_recepcion');
            if (! in_array($medio, ['correo', 'otro'], true)) {
                throw ValidationException::withMessages(['medio' => 'Medio no permitido.']);
            }
            $constancia = trim($constancia);
            if ($constancia === '' || mb_strlen($constancia) > 2000) {
                throw ValidationException::withMessages(['constancia' => 'Pega la constancia de aceptación (hasta 2000 caracteres).']);
            }
            $r = $this->bloquear($uuid);
            $this->exigirPropuestaPendiente($r, $recepcion);
            $r->forceFill(['estado' => 'concluido_por_acuerdo', 'suspension_desde' => null, 'suspension_hasta' => null])->save();
            $this->historial($r, 'propuesta_aceptada', $actor, ['fecha_recepcion' => $recepcion->toDateString(), 'medio' => $medio], $constancia);
            AuditoriaReclamaciones::registrar('propuesta_aceptada', $r, ['estado' => 'concluido_por_acuerdo'], $actor->id);

            return $r;
        });
    }

    /**
     * Evaluación perezosa del vencimiento de una propuesta pendiente (sin actor: «Sistema»).
     * Devuelve true si venció y reanudó el plazo.
     */
    public function resolverVencimiento(string $uuid): bool
    {
        return DB::transaction(fn (): bool => $this->evaluarVencimiento($this->bloquear($uuid)));
    }

    /**
     * Umbral de aviso del plazo legal que corresponde hoy a la reclamación, sin importar si ya se avisó:
     * `por_vencer` (a {@see config('reclamaciones.aviso_dias_habiles')} días hábiles o menos de `fecha_limite`)
     * o `vence` (hoy es `fecha_limite` o ya pasó). Sin aviso si el caso no está abierto o el plazo está suspendido.
     */
    public function umbralAviso(Reclamacion $r): ?string
    {
        if ($r->estado !== 'abierto' || $r->suspension_desde !== null) {
            return null;
        }
        $hoy = $this->hoy();
        $limite = $r->fecha_limite->toImmutable();
        if ($hoy->toDateString() >= $limite->toDateString()) {
            return self::AVISO_VENCE;
        }

        return DiasHabiles::entre($hoy, $limite) <= (int) config('reclamaciones.aviso_dias_habiles', 3) ? self::AVISO_POR_VENCER : null;
    }

    /** Solo lectura: umbral que falta por avisar para el plazo vigente (`null` si no corresponde o ya se avisó). */
    public function avisoPendiente(Reclamacion $r): ?string
    {
        $umbral = $this->umbralAviso($r);

        return $umbral !== null && ! $this->yaAvisado($r, $umbral) ? $umbral : null;
    }

    /**
     * Aviso interno del plazo legal, una sola vez por umbral y por plazo (`fecha_limite`). Sin actor: «Sistema».
     * Bloquea la reclamación, relee el estado y deja las notificaciones y la marca de deduplicación (la auditoría,
     * última escritura) en la misma transacción: si algo falla, no queda aviso a medias ni marca.
     * Sin datos personales: solo número y fecha. Devuelve el umbral avisado, `sin_destinatarios` (no escribe nada
     * y se reintenta en la siguiente ejecución) o `null` si no correspondía.
     */
    public function avisarPlazo(string $uuid): ?string
    {
        return DB::transaction(function () use ($uuid): ?string {
            $r = $this->bloquear($uuid);
            $this->evaluarVencimiento($r);
            $umbral = $this->avisoPendiente($r);
            if ($umbral === null) {
                return null;
            }
            $destinatarios = $this->destinatariosAviso($r);
            if ($destinatarios === []) {
                return self::SIN_DESTINATARIOS;
            }
            $fecha = $r->fecha_limite->format('d/m/Y');
            // El umbral `vence` cubre dos situaciones que el texto distingue por la fecha evaluada (Lima).
            if ($umbral === self::AVISO_POR_VENCER) {
                $titulo = 'Plazo por vencer: reclamación ';
                $mensaje = 'El plazo de respuesta vence el '.$fecha.'.';
            } elseif ($this->hoy()->toDateString() > $r->fecha_limite->toDateString()) {
                $titulo = 'Plazo vencido: reclamación ';
                $mensaje = 'El plazo de respuesta venció el '.$fecha.'.';
            } else {
                $titulo = 'Plazo vence hoy: reclamación ';
                $mensaje = 'El plazo de respuesta vence hoy, '.$fecha.'.';
            }
            foreach ($destinatarios as $usuarioId) {
                NotificacionInterna::crearPersonal($usuarioId, [
                    'titulo' => $titulo.$r->numero,
                    'mensaje' => $mensaje,
                    'tipo' => 'reclamacion_plazo',
                ]);
            }
            AuditoriaReclamaciones::registrar('aviso_plazo', $r, ['numero' => $r->numero, 'resultado' => $umbral, 'fecha_limite' => $r->fecha_limite->toDateString()], null);

            return $umbral;
        });
    }

    /** Consulta de existencia (sin cargar filas): ¿hay marca de este umbral para el plazo vigente? */
    private function yaAvisado(Reclamacion $r, string $umbral): bool
    {
        return AuditLog::query()
            ->where('action', 'reclamacion_aviso_plazo')
            ->where('model_type', Reclamacion::class)
            ->where('model_id', $r->id)
            ->where('changes->resultado', $umbral)
            ->where('changes->fecha_limite', $r->fecha_limite->toDateString())
            ->exists();
    }

    /**
     * El responsable si sigue siendo personal del panel con `reclamaciones.ver`; si no hay (o ya no cumple),
     * todos los usuarios con ese permiso.
     *
     * @return list<int>
     */
    private function destinatariosAviso(Reclamacion $r): array
    {
        if ($r->responsable_id !== null) {
            $responsable = User::query()->find($r->responsable_id);
            if ($responsable !== null && $responsable->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES) && $responsable->can('reclamaciones.ver')) {
                return [$responsable->id];
            }
        }
        if (! Permission::query()->where('name', 'reclamaciones.ver')->where('guard_name', 'web')->exists()) {
            return [];
        }

        return User::permission('reclamaciones.ver')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Respuesta formal. Por correo queda un envío pendiente y el caso pasa a «respondido» solo cuando el correo
     * se envía; por carta se registra la constancia y el caso pasa a «respondido» de inmediato. Exige `responder`.
     */
    public function registrarRespuesta(User $actor, string $uuid, string $texto, string $canal, ?string $constancia = null): Reclamacion
    {
        $envioId = null;
        $r = DB::transaction(function () use ($actor, $uuid, $texto, $canal, $constancia, &$envioId): Reclamacion {
            $actor = $this->autorizar($actor, 'reclamaciones.responder');
            $texto = trim($texto);
            if ($texto === '' || mb_strlen($texto) > 10000) {
                throw ValidationException::withMessages(['respuesta' => 'La respuesta es obligatoria y admite hasta 10000 caracteres.']);
            }
            if (! in_array($canal, ['correo', 'carta'], true)) {
                throw ValidationException::withMessages(['canal' => 'Canal no permitido.']);
            }
            $constancia = $constancia !== null ? trim($constancia) : null;
            if ($canal === 'carta' && ($constancia === null || $constancia === '' || mb_strlen($constancia) > 255)) {
                throw ValidationException::withMessages(['constancia' => 'Indica fecha, medio y cargo o guía de la carta (hasta 255 caracteres).']);
            }
            $r = $this->bloquear($uuid);
            $this->evaluarVencimiento($r);
            $this->exigirAbierta($r);
            abort_if($r->suspension_desde !== null, 409, 'Resuelve primero la propuesta pendiente.');
            try {
                $envio = $this->crearEnvio($r, 'respuesta', $canal, 'Respuesta a su reclamación N° '.$r->numero, $texto, $canal === 'carta' ? $constancia : null);
            } catch (UniqueConstraintViolationException) {
                abort(409, 'Ya existe una respuesta registrada por este canal.');
            }
            $envioId = $envio->id;
            $this->historial($r, 'respuesta_formal_registrada', $actor, ['envio_id' => $envio->id, 'canal' => $canal]);
            if ($canal === 'carta') {
                $envio->forceFill(['estado' => 'enviado', 'enviado_at' => now()])->save();
                $this->marcarRespondida($r, $envio);
                $this->historial($r, 'carta_registrada', $actor, ['envio_id' => $envio->id, 'tipo' => 'respuesta'], $constancia);
                $envioId = null;
            }
            AuditoriaReclamaciones::registrar('respondida', $r, ['envio_id' => $envio->id, 'envio_canal' => $canal, 'estado' => $r->estado], $actor->id);

            return $r;
        });
        $this->despachar($envioId);

        return $r;
    }

    /** Reintento manual de un envío fallido: reutiliza la misma fila con el contador reiniciado. */
    public function reintentarEnvio(User $actor, int $envioId): ReclamacionEnvio
    {
        $envio = DB::transaction(function () use ($actor, $envioId): ReclamacionEnvio {
            $actor = $this->autorizar($actor, 'reclamaciones.gestionar');
            $envio = ReclamacionEnvio::query()->whereKey($envioId)->lockForUpdate()->firstOrFail();
            $r = $this->bloquear((string) Reclamacion::query()->whereKey($envio->reclamacion_id)->value('identificador'));
            abort_unless($envio->estado === 'fallido' && $envio->canal === 'correo', 409, 'Solo se reintenta un envío por correo fallido.');
            $envio->forceFill(['estado' => 'pendiente', 'intentos' => 0, 'error_codigo' => null])->save();
            AuditoriaReclamaciones::registrar('reintento', $r, ['envio_id' => $envio->id, 'envio_tipo' => $envio->tipo], $actor->id);

            return $envio;
        });
        $this->despachar($envio->id);

        return $envio;
    }

    /**
     * Registro en el historial de solo inserción. `nota` y `metadatos` son del caso (los datos personales
     * viven solo en la reclamación); el actor se copia por nombre para sobrevivir a la baja de su cuenta.
     *
     * @param  array<string, mixed>  $metadatos
     */
    public function historial(Reclamacion $r, string $evento, ?User $actor, array $metadatos = [], ?string $nota = null): ReclamacionHistorial
    {
        return ReclamacionHistorial::query()->create([
            'reclamacion_id' => $r->id,
            'evento' => $evento,
            'actor_id' => $actor?->id,
            'actor_nombre' => Str::limit($actor !== null ? $actor->name : 'Sistema', 120, ''),
            'metadatos' => $metadatos,
            'nota' => $nota,
            'created_at' => now(),
        ]);
    }

    /** Cierra el caso como «respondido» con el texto exacto del envío. Requiere transacción y estado abierto. */
    public function marcarRespondida(Reclamacion $r, ReclamacionEnvio $envio): void
    {
        if ($r->estado !== 'abierto') {
            return;
        }
        $r->forceFill(['estado' => 'respondido', 'respuesta' => $envio->cuerpo, 'respondida_at' => $envio->enviado_at ?? now(),
            'suspension_desde' => null, 'suspension_hasta' => null])->save();
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{reclamacion: Reclamacion, repetida: bool}
     */
    private function crear(array $datos, string $canal, ?string $hoja, string $tokenHash, ?User $actor): array
    {
        $envioId = null;
        try {
            $resultado = DB::transaction(function () use ($datos, $canal, $hoja, $tokenHash, $actor, &$envioId): array {
                $previa = Reclamacion::query()->where('token_envio', $tokenHash)->lockForUpdate()->first();
                if ($previa !== null) {
                    return ['reclamacion' => $previa, 'repetida' => true];
                }
                $ahora = CarbonImmutable::now((string) config('reclamaciones.zona_horaria', 'America/Lima'));
                $fila = $this->normalizar(Arr::only($datos, self::CAMPOS_ALTA));
                $r = new Reclamacion;
                $r->forceFill($fila + [
                    'identificador' => (string) Str::uuid(),
                    'numero' => $this->numerador->siguiente($ahora->year),
                    'token_envio' => $tokenHash,
                    'canal' => $canal,
                    'numero_hoja_respaldo' => $hoja,
                    'estado' => 'abierto',
                    'recibida_at' => now(),
                    'fecha_limite' => DiasHabiles::sumar($ahora, (int) config('reclamaciones.plazo_dias_habiles', 15))->toDateString(),
                    'privacidad_version' => (string) config('reclamaciones.privacidad_version'),
                    'informado_at' => now(),
                ])->save();
                $this->historial($r, 'registrada', $actor, ['canal' => $canal, 'fecha_limite' => $r->fecha_limite->toDateString()]);
                if ($canal === 'respaldo') {
                    $this->historial($r, 'hoja_respaldo_registrada', $actor, ['canal' => $canal]);
                } else {
                    $envio = $this->crearEnvio($r, 'copia_hoja', 'correo', HojaReclamacion::asunto($r), HojaReclamacion::cuerpo($r));
                    $envioId = $envio->id;
                }
                $this->avisarInternamente($r);
                AuditoriaReclamaciones::registrar('registrada', $r, ['numero' => $r->numero, 'canal' => $canal, 'tipo' => $r->tipo], $actor?->id);

                return ['reclamacion' => $r, 'repetida' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Carrera por el mismo token: tras el rollback la reclamación ganadora ya existe.
            $previa = Reclamacion::query()->where('token_envio', $tokenHash)->first();
            if ($previa === null) {
                throw $e;
            }

            return ['reclamacion' => $previa, 'repetida' => true];
        }
        $this->despachar($envioId);

        return $resultado;
    }

    /**
     * Reglas de coherencia del alta que la base también exige con CHECK.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function normalizar(array $fila): array
    {
        $fila += ['canal_respuesta' => 'correo', 'es_menor' => false, 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null];
        if (empty($fila['bien_tipo']) || empty($fila['bien_descripcion'])) {
            $fila['bien_tipo'] = $fila['bien_descripcion'] = $fila['monto_bien'] = null;
        }
        // «es_menor» puede llegar como cadena ("false", "0", "true"…): se interpreta con la semántica booleana
        // de PHP/Laravel, nunca con empty() (que tomaría "false" como verdadero). Un valor no booleano se rechaza.
        $menor = is_bool($fila['es_menor']) ? $fila['es_menor'] : filter_var($fila['es_menor'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($menor === null) {
            throw ValidationException::withMessages(['es_menor' => 'Indica si el reclamante es menor de edad (sí o no).']);
        }
        $fila['es_menor'] = $menor;
        if (! $menor) {
            $fila['es_menor'] = false;
            $fila['representante_nombre'] = $fila['representante_domicilio'] = $fila['representante_telefono'] = $fila['representante_email'] = null;
        }

        return $fila;
    }

    private function crearEnvio(Reclamacion $r, string $tipo, string $canal, string $asunto, string $cuerpo, ?string $constancia = null): ReclamacionEnvio
    {
        return ReclamacionEnvio::query()->create([
            'reclamacion_id' => $r->id, 'tipo' => $tipo, 'canal' => $canal, 'clave' => (string) Str::uuid(),
            'estado' => 'pendiente', 'intentos' => 0, 'asunto' => $asunto, 'cuerpo' => $cuerpo, 'constancia' => $constancia,
        ]);
    }

    /** Aviso interno sin datos personales: solo número y tipo, a quienes pueden ver reclamaciones. */
    private function avisarInternamente(Reclamacion $r): void
    {
        if (! Permission::query()->where('name', 'reclamaciones.ver')->where('guard_name', 'web')->exists()) {
            return;
        }
        foreach (User::permission('reclamaciones.ver')->get() as $usuario) {
            NotificacionInterna::crearPersonal((int) $usuario->id, [
                'titulo' => 'Nueva reclamación '.$r->numero,
                'mensaje' => 'Se registró '.($r->tipo === 'reclamo' ? 'un reclamo' : 'una queja').'. Plazo de respuesta hasta el '.$r->fecha_limite->format('d/m/Y').'.',
                'tipo' => 'reclamacion',
            ]);
        }
    }

    private function despachar(?int $envioId): void
    {
        if ($envioId !== null && Reclamaciones::envioHabilitado()) {
            EnviarReclamacionCorreo::dispatch($envioId)->onQueue((string) config('reclamaciones.correo.cola', 'default'));
        }
    }

    private function autorizar(User $actor, string $permiso): User
    {
        $fresco = User::query()->find($actor->id);
        abort_unless($fresco instanceof User && $fresco->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES) && $fresco->can($permiso), 403);

        return $fresco;
    }

    /**
     * Una cadena que no es UUID nunca llega a la consulta: en PostgreSQL la columna `uuid` la rechazaría con
     * 22P02 (y abortaría la transacción) en vez de responder «no encontrada» como SQLite. Se normaliza a
     * minúsculas porque así se generan (`Str::uuid()`) y SQLite compara texto exacto.
     */
    private function bloquear(string $uuid): Reclamacion
    {
        return $this->porIdentificador($uuid)->lockForUpdate()->firstOrFail();
    }

    /** @return Builder<Reclamacion> */
    private function porIdentificador(string $uuid): Builder
    {
        if (! Str::isUuid($uuid)) {
            throw (new ModelNotFoundException)->setModel(Reclamacion::class);
        }

        return Reclamacion::query()->where('identificador', strtolower($uuid));
    }

    private function exigirAbierta(Reclamacion $r): void
    {
        abort_unless($r->estado === 'abierto', 409, 'La reclamación ya no está abierta.');
    }

    /** La propuesta debe estar pendiente y recibirse dentro de su ventana. */
    private function exigirPropuestaPendiente(Reclamacion $r, CarbonImmutable $recepcion): void
    {
        $this->exigirAbierta($r);
        abort_if($r->suspension_desde === null || $r->suspension_hasta === null, 409, 'No hay una propuesta pendiente.');
        abort_if($recepcion->toDateString() < $r->suspension_desde->toDateString() || $recepcion->toDateString() > $r->suspension_hasta->toDateString(), 409, 'La fecha de recepción está fuera de la ventana de la propuesta.');
    }

    private function evaluarVencimiento(Reclamacion $r): bool
    {
        if (! $this->propuestaVencidaSinProcesar($r)) {
            return false;
        }
        [$dias, $nueva] = $this->reanudar($r, $r->suspension_hasta->toImmutable());
        $this->historial($r, 'propuesta_vencida', null, ['dias_suspendidos' => $dias, 'fecha_limite_nueva' => $nueva->toDateString()]);
        AuditoriaReclamaciones::registrar('propuesta_vencida', $r, ['dias_suspendidos' => $dias, 'fecha_limite' => $nueva->toDateString()], null);

        return true;
    }

    /**
     * Reanuda el plazo y limpia la suspensión. Los días suspendidos son los hábiles transcurridos (máx. los de la ley).
     *
     * @return array{0: int, 1: CarbonImmutable}
     */
    private function reanudar(Reclamacion $r, CarbonImmutable $hasta): array
    {
        $dias = min((int) config('reclamaciones.suspension_dias_habiles', 5), DiasHabiles::entre($r->suspension_desde->toImmutable(), $hasta));
        $nueva = DiasHabiles::sumar($r->fecha_limite->toImmutable(), $dias);
        $r->forceFill(['fecha_limite' => $nueva->toDateString(), 'suspension_desde' => null, 'suspension_hasta' => null])->save();

        return [$dias, $nueva];
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('reclamaciones.zona_horaria', 'America/Lima'))->startOfDay();
    }

    private function fecha(string $valor, string $campo): CarbonImmutable
    {
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $valor, (string) config('reclamaciones.zona_horaria', 'America/Lima'));
        if ($fecha === null || $fecha->format('Y-m-d') !== $valor) {
            throw ValidationException::withMessages([$campo => 'Indica una fecha válida (AAAA-MM-DD).']);
        }

        return $fecha;
    }

    private function nota(?string $nota): ?string
    {
        $nota = $nota !== null ? trim($nota) : null;
        if ($nota !== null && mb_strlen($nota) > 2000) {
            throw ValidationException::withMessages(['nota' => 'La nota admite hasta 2000 caracteres.']);
        }

        return $nota === '' ? null : $nota;
    }

    private function textoEnvio(string $asunto, string $cuerpo): void
    {
        if (trim($asunto) === '' || mb_strlen($asunto) > 200 || trim($cuerpo) === '' || mb_strlen($cuerpo) > 10000) {
            throw ValidationException::withMessages(['cuerpo' => 'Asunto (hasta 200) y cuerpo (hasta 10000) son obligatorios.']);
        }
    }
}
