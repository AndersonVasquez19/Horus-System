<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Reclamacion;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use App\Support\Entrada;
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
        $operacion = Entrada::texto($datos['operacion']);
        $uuid = (string) $reclamacion->identificador;

        match ($operacion) {
            'responsable' => $this->gestion->asignarResponsable(
                $actor, $uuid, $datos['responsable_id'] === null ? null : Entrada::entero($datos['responsable_id'])),
            'tipo' => $this->gestion->reclasificarTipo($actor, $uuid, Entrada::texto($datos['tipo'])),
            'nota' => $this->gestion->agregarNota($actor, $uuid, Entrada::texto($datos['nota'])),
            'propuesta' => $this->gestion->enviarPropuesta($actor, $uuid, Entrada::texto($datos['asunto']), Entrada::texto($datos['cuerpo'])),
            'rechazo' => $this->gestion->registrarRechazo(
                $actor, $uuid, Entrada::texto($datos['fecha_recepcion']), isset($datos['nota']) ? Entrada::texto($datos['nota']) : null),
            'aceptacion' => $this->gestion->registrarAceptacion(
                $actor, $uuid, Entrada::texto($datos['fecha_recepcion']), Entrada::texto($datos['medio']), Entrada::texto($datos['constancia'])),
            'respuesta' => $this->gestion->registrarRespuesta(
                $actor, $uuid, Entrada::texto($datos['respuesta']), Entrada::texto($datos['canal']),
                isset($datos['constancia']) ? Entrada::texto($datos['constancia']) : null),
            'reintento' => $this->gestion->reintentarEnvio($actor, $this->envioDeLaReclamacion($reclamacion, Entrada::entero($datos['envio_id']))),
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

        return array_values(User::permission('reclamaciones.ver')
            ->role(RolesAndPermissionsSeeder::STAFF_ROLES)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()
            ->all());
    }
}
