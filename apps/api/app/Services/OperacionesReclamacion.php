<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;

/**
 * Ejecuta una operación de gestión de una reclamación (E4) a partir de los datos ya validados por
 * `GestionarReclamacionRequest`. La usan el Blade y el panel. Solo delega en {@see GestionReclamaciones}, que comprueba
 * permiso, estado y reglas bajo bloqueo; aquí no se decide ninguna regla del libro.
 */
final class OperacionesReclamacion
{
    /** Mensaje de éxito por operación de gestión. */
    public const MENSAJES = [
        'responsable' => 'Responsable actualizado.',
        'tipo' => 'Tipo de registro actualizado.',
        'nota' => 'Nota interna registrada.',
        'propuesta' => 'Propuesta de solución registrada.',
        'rechazo' => 'Rechazo de la propuesta registrado; el plazo se reanudó.',
        'aceptacion' => 'Aceptación de la propuesta registrada; el caso concluyó por acuerdo.',
        'respuesta' => 'Respuesta formal registrada.',
        'reintento' => 'Envío reprogramado.',
    ];

    public function __construct(private readonly GestionReclamaciones $gestion) {}

    /**
     * Ejecuta la operación y devuelve el mensaje de éxito. Un `HttpException` del servicio (409: el caso ya no admite
     * la operación; 403 y 404) se propaga: cada interfaz decide cómo mostrarlo.
     *
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(User $actor, Reclamacion $reclamacion, array $datos): string
    {
        $operacion = (string) $datos['operacion'];
        $uuid = (string) $reclamacion->identificador;

        match ($operacion) {
            'responsable' => $this->gestion->asignarResponsable(
                $actor, $uuid, $datos['responsable_id'] === null ? null : (int) $datos['responsable_id']),
            'tipo' => $this->gestion->reclasificarTipo($actor, $uuid, (string) $datos['tipo']),
            'nota' => $this->gestion->agregarNota($actor, $uuid, (string) $datos['nota']),
            'propuesta' => $this->gestion->enviarPropuesta($actor, $uuid, (string) $datos['asunto'], (string) $datos['cuerpo']),
            'rechazo' => $this->gestion->registrarRechazo(
                $actor, $uuid, (string) $datos['fecha_recepcion'], isset($datos['nota']) ? (string) $datos['nota'] : null),
            'aceptacion' => $this->gestion->registrarAceptacion(
                $actor, $uuid, (string) $datos['fecha_recepcion'], (string) $datos['medio'], (string) $datos['constancia']),
            'respuesta' => $this->gestion->registrarRespuesta(
                $actor, $uuid, (string) $datos['respuesta'], (string) $datos['canal'],
                isset($datos['constancia']) ? (string) $datos['constancia'] : null),
            'reintento' => $this->gestion->reintentarEnvio($actor, $this->envioDeLaReclamacion($reclamacion, (int) $datos['envio_id'])),
            // El request ya rechaza una operación desconocida (403); nunca se ejecuta otra cosa.
            default => abort(403),
        };

        return self::MENSAJES[$operacion];
    }

    /** Un envío solo se reintenta desde la reclamación a la que pertenece: si no, 404 sin tocar nada. */
    private function envioDeLaReclamacion(Reclamacion $reclamacion, int $envioId): int
    {
        abort_unless(
            ReclamacionEnvio::query()->whereKey($envioId)->where('reclamacion_id', $reclamacion->getKey())->exists(),
            404,
        );

        return $envioId;
    }

    /**
     * Personal del panel con `reclamaciones.ver`: el mismo criterio con el que el servicio valida la asignación.
     * Solo id y nombre.
     *
     * @return list<array{id: int, name: string}>
     */
    public function responsablesDisponibles(): array
    {
        if (! Permission::query()->where('name', 'reclamaciones.ver')->where('guard_name', 'web')->exists()) {
            return [];
        }

        return User::permission('reclamaciones.ver')
            ->role(RolesAndPermissionsSeeder::STAFF_ROLES)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()
            ->all();
    }
}
