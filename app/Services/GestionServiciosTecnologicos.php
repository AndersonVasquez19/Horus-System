<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\PrepararServicioPublicacionRequest;
use App\Models\AuditLog;
use App\Models\ServicioPublicacion;
use App\Models\ServicioPublicacionElemento;
use App\Models\ServicioTecnologico;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GestionServiciosTecnologicos
{
    /** @param array<string, mixed> $datos */
    public function crear(Request $request, array $datos): ServicioTecnologico
    {
        try {
            return DB::transaction(function () use ($request, $datos): ServicioTecnologico {
                $autor = $this->autorizar($request, 'servicios.crear');
                $servicio = new ServicioTecnologico($datos);
                $servicio->codigo = $datos['codigo'];
                $servicio->identificador_publico = (string) Str::uuid();
                $servicio->estado = $datos['estado'] ?? 'inactivo';
                $servicio->revision = 1;
                $servicio->save();
                AuditLog::record('servicio_creado', ServicioTecnologico::class, $servicio->id, ['revision' => 1], $autor->id);

                return $servicio;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['codigo' => 'El código ya está registrado.']);
        }
    }

    /** @param array<string, mixed> $datos */
    public function cambiar(Request $request, int $id, string $operacion, array $datos = []): ServicioTecnologico
    {
        return DB::transaction(function () use ($request, $id, $operacion, $datos): ServicioTecnologico {
            $servicio = ServicioTecnologico::query()->lockForUpdate()->findOrFail($id);
            $autor = $this->autorizar($request, in_array($operacion, ['publicar', 'retirar'], true) ? 'servicios.publicar' : 'servicios.editar');
            $this->comprobarRevision($request, $servicio);
            $publicacion = $servicio->publicacion()->first();
            $evento = match ($operacion) {
                'editar' => 'servicio_actualizado',
                'estado' => 'servicio_estado_actualizado',
                'preparar' => 'servicio_borrador_guardado',
                'publicar' => 'servicio_publicado',
                'retirar' => 'servicio_retirado',
                default => throw new \InvalidArgumentException('Operación desconocida.'),
            };

            if ($operacion === 'editar' || $operacion === 'estado') {
                $servicio->fill($datos);
                if ($servicio->estado !== 'activo' && $publicacion?->estado === 'publicada') {
                    $publicacion->estado = 'retirada';
                }
            } elseif ($operacion === 'preparar') {
                abort_if($publicacion?->estado === 'publicada', 409, 'Retira la publicación antes de editar su contenido.');
                $publicacion ??= new ServicioPublicacion;
                $publicacion->servicio_tecnologico_id = $servicio->id;
                $publicacion->fill($datos);
                $publicacion->estado = 'borrador';
                $publicacion->autorizado_por = null;
                $publicacion->autorizado_at = null;
            } elseif ($operacion === 'publicar') {
                abort_if($servicio->estado !== 'activo' || $publicacion === null, 409, 'Se requiere un servicio activo con ficha editorial.');
                $this->validarPublicacion($publicacion);
                if ($publicacion->estado !== 'publicada' || $publicacion->autorizado_por !== $autor->id) {
                    $publicacion->estado = 'publicada';
                    $publicacion->autorizado_por = $autor->id;
                    $publicacion->autorizado_at = now();
                }
            } elseif ($publicacion?->estado === 'publicada') {
                $publicacion->estado = 'retirada';
            }

            if (! $servicio->isDirty() && ($publicacion === null || ! $publicacion->isDirty())) {
                return $servicio;
            }
            $publicacion?->save();
            $servicio->revision++;
            $servicio->save();
            AuditLog::record($evento, ServicioTecnologico::class, $servicio->id,
                ['revision' => $servicio->revision, 'estado' => $servicio->estado, 'estado_editorial' => $publicacion?->estado], $autor->id);

            return $servicio;
        });
    }

    public const MAX_ELEMENTOS = 30;

    /**
     * Contenido de la ficha: tarjetas e imagen principal. Reutiliza el mismo bloqueo, permiso, If-Match y auditoría.
     * Con una ficha publicada se exige retirarla antes (igual que el resto del contenido editorial).
     *
     * @param  'elemento_crear'|'elemento_editar'|'elemento_eliminar'|'imagen_principal'  $operacion
     * @param  array<string, mixed>  $datos
     */
    public function contenido(Request $request, int $id, string $operacion, array $datos = [], ?UploadedFile $imagen = null, ?int $elementoId = null): ServicioTecnologico
    {
        $imagenes = app(ImagenesServicios::class);
        $nueva = null;
        /** @var list<string> $liberar */
        $liberar = [];
        try {
            $servicio = DB::transaction(function () use ($request, $id, $operacion, $datos, $imagen, $elementoId, $imagenes, &$nueva, &$liberar): ServicioTecnologico {
                $servicio = ServicioTecnologico::query()->lockForUpdate()->findOrFail($id);
                $autor = $this->autorizar($request, 'servicios.editar');
                $this->comprobarRevision($request, $servicio);
                $publicacion = $servicio->publicacion()->lockForUpdate()->first();
                abort_if($publicacion === null, 409, 'Guarda primero el borrador editorial de la ficha.');
                abort_if($publicacion->estado === 'publicada', 409, 'Retira la publicación antes de editar su contenido.');
                if ($imagen !== null) {
                    $nueva = $imagenes->guardar($imagen);
                }
                $meta = ['revision' => $servicio->revision + 1];
                if ($operacion === 'imagen_principal') {
                    $liberar[] = (string) $publicacion->imagen_principal_ruta;
                    $publicacion->imagen_principal_ruta = $nueva;
                    $publicacion->fill(array_intersect_key($datos, ['imagen_principal_alt' => true]));
                    $evento = 'servicio_imagen_principal_actualizada';
                } elseif ($operacion === 'elemento_crear') {
                    if ($publicacion->elementos()->reorder()->count() >= self::MAX_ELEMENTOS) {
                        throw ValidationException::withMessages(['titulo' => 'La ficha admite como máximo '.self::MAX_ELEMENTOS.' tarjetas.']);
                    }
                    $elemento = new ServicioPublicacionElemento($this->camposElemento($datos));
                    $elemento->servicio_publicacion_id = $publicacion->id;
                    $elemento->orden = (int) ($datos['orden'] ?? ((int) $publicacion->elementos()->reorder()->max('orden') + 1));
                    $elemento->imagen_ruta = $nueva;
                    $elemento->save();
                    $meta['elemento_id'] = $elemento->id;
                    $evento = 'servicio_elemento_creado';
                } else {
                    $elemento = $publicacion->elementos()->whereKey((int) $elementoId)->first();
                    abort_if($elemento === null, 404);
                    $meta['elemento_id'] = $elemento->id;
                    if ($operacion === 'elemento_eliminar') {
                        $liberar[] = (string) $elemento->imagen_ruta;
                        $elemento->delete();
                        $evento = 'servicio_elemento_eliminado';
                    } else {
                        $elemento->fill($this->camposElemento($datos));
                        if (array_key_exists('orden', $datos)) {
                            $elemento->orden = (int) $datos['orden'];
                        }
                        if ($nueva !== null || ! empty($datos['quitar_imagen'])) {
                            $liberar[] = (string) $elemento->imagen_ruta;
                            $elemento->imagen_ruta = $nueva;
                        }
                        $elemento->save();
                        $evento = 'servicio_elemento_actualizado';
                    }
                }
                if ($publicacion->estado === 'retirada') {
                    // Un contenido modificado tras un retiro vuelve a borrador: exige autorizar de nuevo.
                    $publicacion->estado = 'borrador';
                    $publicacion->autorizado_por = null;
                    $publicacion->autorizado_at = null;
                }
                $publicacion->save();
                $publicacion->touch();
                $servicio->revision++;
                $servicio->save();
                AuditLog::record($evento, ServicioTecnologico::class, $servicio->id, $meta, $autor->id);

                return $servicio;
            });
        } catch (\Throwable $error) {
            // Nada quedó referenciado: la subida recién creada no debe quedar huérfana.
            $imagenes->liberarSiHuerfana($nueva);
            throw $error;
        }
        // Solo tras confirmar la transacción se borra lo reemplazado, y únicamente si nadie más lo usa.
        foreach (array_unique(array_filter($liberar)) as $ruta) {
            $imagenes->liberarSiHuerfana($ruta);
        }

        return $servicio;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function camposElemento(array $datos): array
    {
        return array_intersect_key($datos, array_flip(['titulo', 'descripcion', 'caracteristicas', 'icono', 'variante_imagen', 'imagen_alt', 'activo']));
    }

    private function autorizar(Request $request, string $permiso): User
    {
        $actor = $request->user()?->fresh();
        abort_unless($actor instanceof User && $actor->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES) && $actor->can($permiso), 403);

        return $actor;
    }

    private function comprobarRevision(Request $request, ServicioTecnologico $servicio): void
    {
        $revision = $request->header('If-Match');
        abort_if($revision === null, 428, 'Envía If-Match obtenido del detalle administrativo.');
        if (! preg_match('/\A"servicio-[1-9][0-9]*-v[1-9][0-9]*"\z/D', $revision)) {
            throw ValidationException::withMessages(['revision' => 'Envía una única revisión fuerte.']);
        }
        abort_unless(hash_equals($servicio->etag(), $revision), 412, 'El servicio cambió. Relee y revisa antes de continuar.');
    }

    private function validarPublicacion(ServicioPublicacion $publicacion): void
    {
        // Revalida el borrador guardado: no depende del contenido o de la fecha del antiguo request.
        validator($publicacion->attributesToArray(),
            (new PrepararServicioPublicacionRequest)->rules())->validate();
        abort_if($publicacion->vigente_hasta->toDateString() < now('America/Lima')->toDateString(), 409, 'La ficha ha caducado.');
    }
}
