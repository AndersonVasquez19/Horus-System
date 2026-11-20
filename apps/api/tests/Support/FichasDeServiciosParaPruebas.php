<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ServicioPublicacion;
use App\Models\ServicioPublicacionElemento;
use App\Models\ServicioTecnologico;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Prepara fichas del catálogo público de servicios tecnológicos directamente en la base. El panel de servicios (y su API de
 * edición) se retiró: hoy las fichas se cargan con el seeder, así que estas pruebas del catálogo público, de las cotizaciones y del
 * sincronizador de Horus AI las crean como lo haría el seeder, sin pasar por ninguna ruta del panel.
 *
 * Las operaciones guardan con Eloquent (igual que antes el servicio de edición), de modo que los observadores del catálogo de
 * Horus AI se disparan como en producción. «Autorizar» exige un usuario con `servicios.publicar` (el que esté autenticado, o un admin).
 */
trait FichasDeServiciosParaPruebas
{
    /** @param array<string, mixed> $datos `codigo`, `nombre` y opcionales `categoria`, `estado` */
    protected function crearServicioPanel(array $datos): int
    {
        $servicio = new ServicioTecnologico(['nombre' => $datos['nombre'], 'categoria' => $datos['categoria'] ?? null, 'estado' => $datos['estado'] ?? 'inactivo']);
        $servicio->codigo = strtoupper((string) $datos['codigo']);
        $servicio->identificador_publico = (string) Str::uuid();
        $servicio->revision = 1;
        $servicio->save();

        return (int) $servicio->id;
    }

    /** Revisión vigente (`ETag`) del servicio. */
    protected function revisionServicioPanel(int $id): string
    {
        return ServicioTecnologico::query()->findOrFail($id)->etag();
    }

    /** @param array<string, mixed> $ficha */
    protected function guardarFichaPanel(int $id, array $ficha): void
    {
        $servicio = ServicioTecnologico::query()->findOrFail($id);
        $publicacion = $servicio->publicacion()->first() ?? new ServicioPublicacion;
        $publicacion->servicio_tecnologico_id = $servicio->id;
        $publicacion->fill($ficha);
        $publicacion->estado = 'borrador';
        $publicacion->autorizado_por = null;
        $publicacion->autorizado_at = null;
        $publicacion->save();
        $this->avanzarRevisionServicio($servicio);
    }

    protected function cambiarEstadoServicioPanel(int $id, string $estado): void
    {
        $servicio = ServicioTecnologico::query()->findOrFail($id);
        $servicio->estado = $estado;
        $publicacion = $servicio->publicacion()->first();
        if ($estado !== 'activo' && $publicacion?->estado === 'publicada') {
            $publicacion->estado = 'retirada';
            $publicacion->save();
        }
        $this->avanzarRevisionServicio($servicio);
    }

    protected function autorizarFichaPanel(int $id): void
    {
        $servicio = ServicioTecnologico::query()->findOrFail($id);
        $publicacion = $servicio->publicacion()->firstOrFail();
        $autor = auth()->user() instanceof User ? auth()->user() : User::permission('servicios.publicar')->firstOrFail();
        $publicacion->estado = 'publicada';
        $publicacion->autorizado_por = $autor->id;
        $publicacion->autorizado_at = now();
        $publicacion->save();
        $this->avanzarRevisionServicio($servicio);
    }

    protected function retirarFichaPanel(int $id): void
    {
        $servicio = ServicioTecnologico::query()->findOrFail($id);
        $publicacion = $servicio->publicacion()->first();
        if ($publicacion?->estado === 'publicada') {
            $publicacion->estado = 'retirada';
            $publicacion->save();
        }
        $this->avanzarRevisionServicio($servicio);
    }

    /**
     * Tarjeta de la ficha (como la cargaría el seeder). `imagen_ruta`, si se indica, debe ser una ruta válida de `ImagenesServicios`.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function crearTarjetaPanel(int $id, array $datos = []): ServicioPublicacionElemento
    {
        $publicacion = ServicioPublicacion::query()->where('servicio_tecnologico_id', $id)->firstOrFail();
        $elemento = new ServicioPublicacionElemento(array_merge(['titulo' => 'Tarjeta uno', 'descripcion' => 'Descripción uno', 'orden' => 1, 'activo' => true], array_diff_key($datos, ['imagen_ruta' => 1])));
        $elemento->servicio_publicacion_id = $publicacion->id;
        if (isset($datos['imagen_ruta'])) {
            $elemento->imagen_ruta = $datos['imagen_ruta'];
        }
        $elemento->save();
        $this->avanzarRevisionServicio(ServicioTecnologico::query()->findOrFail($id));

        return $elemento;
    }

    private function avanzarRevisionServicio(ServicioTecnologico $servicio): void
    {
        $servicio->revision++;
        $servicio->save();
    }
}
