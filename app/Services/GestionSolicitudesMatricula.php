<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SolicitudMatricula;
use App\Models\User;
use App\Support\FormularioSolicitudMatricula;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GestionSolicitudesMatricula
{
    public function __construct(private readonly CatalogoPublicoCursos $catalogo) {}

    /** @param array<string, mixed> $ficha */
    public function referencia(array $ficha): string
    {
        return hash_hmac('sha256', json_encode($ficha, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @return list<array{valor: string, titulo: string, modalidad: string}> */
    public function cursos(): array
    {
        return array_map(fn (array $ficha): array => [
            'valor' => $this->referencia($ficha), 'titulo' => $ficha['titulo'], 'modalidad' => $ficha['modalidad'],
        ], $this->catalogo->paraWeb()['cursos']);
    }

    /** @param array<string, mixed> $datos */
    public function registrar(array $datos): SolicitudMatricula
    {
        $marca = FormularioSolicitudMatricula::leer($datos['marca']);
        if ($marca === null) {
            throw ValidationException::withMessages(['marca' => 'Vuelve a cargar el formulario antes de enviarlo.']);
        }
        $contacto = array_intersect_key($datos, array_flip(['nombres', 'apellidos', 'email', 'telefono', 'mensaje']));
        $contacto['mensaje'] = $contacto['mensaje'] ?? null;
        // Orden fijo: una repetición del mismo formulario no depende del orden de las claves HTTP.
        $contenido = [$datos['curso'], $contacto['nombres'], $contacto['apellidos'], $contacto['email'], $contacto['telefono'], $contacto['mensaje']];
        $huella = hash_hmac('sha256', json_encode($contenido, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $anterior = SolicitudMatricula::where('identificador', $marca['identificador'])->first();
        if ($anterior !== null) {
            return $this->comprobarRepeticion($anterior, $huella);
        }
        $ficha = collect($this->cursos())->first(fn (array $curso): bool => hash_equals($curso['valor'], $datos['curso']));
        if ($ficha === null) {
            throw ValidationException::withMessages(['curso' => 'Este curso ya no está disponible en el catálogo. Elige uno de los cursos publicados.']);
        }

        return DB::transaction(function () use ($marca, $huella, $ficha, $contacto): SolicitudMatricula {
            $solicitud = SolicitudMatricula::firstOrCreate(['identificador' => $marca['identificador']], [
                ...$contacto, 'huella' => $huella, 'curso_titulo' => $ficha['titulo'], 'modalidad' => $ficha['modalidad'], 'consentimiento_at' => now(),
            ]);

            return $this->comprobarRepeticion($solicitud, $huella);
        });
    }

    private function comprobarRepeticion(SolicitudMatricula $solicitud, string $huella): SolicitudMatricula
    {
        if (! hash_equals($solicitud->huella, $huella)) {
            throw ValidationException::withMessages(['marca' => 'Este formulario ya fue recibido con otros datos. Abre uno nuevo para otra solicitud.']);
        }

        return $solicitud;
    }

    /** @param array{estado: string, nota_revision?: ?string, revision: int|string} $datos */
    public function revisar(string $identificador, array $datos, User $actor): void
    {
        DB::transaction(function () use ($identificador, $datos, $actor): void {
            $solicitud = SolicitudMatricula::where('identificador', $identificador)->lockForUpdate()->firstOrFail();
            if ($solicitud->revision !== (int) $datos['revision']) {
                throw ValidationException::withMessages(['revision' => 'Otra persona revisó esta solicitud. Recarga el listado antes de continuar.']);
            }
            $destinos = match ($solicitud->estado) {
                'pendiente' => ['en_revision', 'rechazada'],
                'en_revision' => ['atendida', 'rechazada'],
                default => [],
            };
            if (! in_array($datos['estado'], $destinos, true)) {
                throw ValidationException::withMessages(['estado' => 'La solicitud no admite ese cambio de estado.']);
            }
            $anterior = $solicitud->estado;
            $solicitud->estado = $datos['estado'];
            $solicitud->nota_revision = $datos['nota_revision'] ?? null;
            $solicitud->revision++;
            $solicitud->revisado_por = $actor->id;
            $solicitud->revisado_at = now();
            $solicitud->save();
            AuditLog::record('solicitud_matricula_revisada', SolicitudMatricula::class, $solicitud->id,
                ['anterior' => $anterior, 'estado' => $solicitud->estado, 'revision' => $solicitud->revision], $actor->id);
        });
    }
}
