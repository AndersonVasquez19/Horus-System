<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificado;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotaEstudiante;
use App\Models\SeguimientoCobranza;
use App\Models\TransaccionPago;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vista 360° de un estudiante (Fase 3.3): línea de tiempo con matrículas, pagos, certificados, contactos de cobranza históricos y
 * notas internas. Cada tipo de evento solo aparece si quien consulta tiene el permiso de su módulo (pagos y cobranza: `pagos.ver`;
 * certificados: `certificados.ver`).
 *
 * @phpstan-type Evento array{tipo: string, fecha: string, titulo: string, detalle: string|null, enlace: string|null, autor: string|null}
 */
class Estudiante360
{
    public const MAX_EVENTOS = 200;

    /**
     * @return array{eventos: list<Evento>, truncado: bool}
     */
    public function lineaDeTiempo(Estudiante $estudiante, User $actor): array
    {
        $eventos = [];
        $matriculas = Matricula::query()->where('estudiante_id', $estudiante->id)->with(['curso:id,nombre', 'convocatoria:id,codigo'])->orderBy('id')->get();
        $ids = array_values(array_map(static fn (Matricula $m): int => $m->id, $matriculas->all()));

        foreach ($matriculas as $m) {
            $curso = $m->curso->nombre ?? 'Curso';
            $eventos[] = $this->evento('matricula', substr((string) $m->fecha_matricula, 0, 10), "Matrícula en {$curso}", 'Estado: '.$m->estado.($m->convocatoria ? ' · Edición '.$m->convocatoria->codigo : ''), "/admin/academico/matriculas/{$m->id}");
        }

        if ($ids !== [] && $actor->can('pagos.ver')) {
            $this->pagos($ids, $eventos);
            $this->contactosCobranza($ids, $eventos);
        }
        if ($actor->can('certificados.ver')) {
            foreach (Certificado::query()->where('estudiante_id', $estudiante->id)->with('curso:id,nombre')->get() as $c) {
                $eventos[] = $this->evento('certificado', $this->dia($c->emitido_en), "Certificado {$c->codigo} ".($c->estado->value === 'emitido' ? 'emitido' : 'anulado'), $c->curso->nombre ?? null, '/admin/academico/certificados?buscar='.$c->codigo);
            }
        }
        foreach (NotaEstudiante::query()->where('estudiante_id', $estudiante->id)->with('autor:id,name')->get() as $n) {
            $eventos[] = $this->evento('nota_interna', $this->dia($n->created_at), 'Nota interna', $n->texto, null, $n->autor->name ?? null);
        }

        // Más reciente primero; a igual fecha, el orden de armado (matrícula antes que lo posterior) se conserva de forma estable.
        usort($eventos, static fn (array $a, array $b): int => strcmp($b['fecha'], $a['fecha']));
        $truncado = count($eventos) > self::MAX_EVENTOS;

        return ['eventos' => array_slice($eventos, 0, self::MAX_EVENTOS), 'truncado' => $truncado];
    }

    /**
     * Agrega una nota interna de seguimiento. Audita sin el texto.
     *
     * @return array{identificador: string, texto: string, creado: string|null, autor: string|null}
     */
    public function agregarNota(User $actor, Estudiante $estudiante, string $texto): array
    {
        return DB::transaction(function () use ($actor, $estudiante, $texto): array {
            $n = new NotaEstudiante;
            $n->identificador = (string) Str::uuid();
            $n->estudiante_id = $estudiante->id;
            $n->texto = trim($texto);
            $n->registrado_por = $actor->id;
            $n->save();
            AuditLog::record('nota_estudiante_agregada', NotaEstudiante::class, $n->id, ['estudiante' => $estudiante->id], $actor->id);

            return ['identificador' => $n->identificador, 'texto' => $n->texto, 'creado' => $n->created_at?->toIso8601String(), 'autor' => $actor->name];
        });
    }

    /**
     * @param  list<int>  $ids
     * @param  list<Evento>  $eventos
     */
    private function pagos(array $ids, array &$eventos): void
    {
        $txs = TransaccionPago::query()->whereIn('tipo', ['pago', 'reverso'])->where('estado', 'confirmada')
            ->whereHas('obligacion', fn ($o) => $o->whereIn('matricula_id', $ids))
            ->with(['metodo:id,nombre', 'obligacion:id,matricula_id'])->get();
        foreach ($txs as $t) {
            $reverso = $t->tipo === 'reverso';
            $matricula = $t->obligacion->matricula_id ?? 0;
            $eventos[] = $this->evento('pago', substr((string) $t->fecha_pago, 0, 10), ($reverso ? 'Reverso de S/ ' : 'Pago de S/ ').GestionPagos::formato(GestionPagos::leer($t->monto)), $t->metodo->nombre ?? null, "/admin/academico/matriculas/{$matricula}");
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  list<Evento>  $eventos
     */
    private function contactosCobranza(array $ids, array &$eventos): void
    {
        $segs = SeguimientoCobranza::query()->whereHas('obligacion', fn ($o) => $o->whereIn('matricula_id', $ids))->with('autor:id,name')->get();
        foreach ($segs as $s) {
            $promesa = $s->promesa_fecha !== null ? ' · promete pagar el '.substr((string) $s->promesa_fecha, 0, 10) : '';
            $eventos[] = $this->evento('cobranza', $this->dia($s->created_at), "Contacto de cobranza ({$s->canal}): {$s->resultado}{$promesa}", $s->nota, null, $s->autor->name ?? null);
        }
    }

    /** @param  \DateTimeInterface|string|null  $valor */
    private function dia(mixed $valor): string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        return $valor === null ? '' : substr((string) $valor, 0, 10);
    }

    /** @return Evento */
    private function evento(string $tipo, string $fecha, string $titulo, ?string $detalle = null, ?string $enlace = null, ?string $autor = null): array
    {
        return ['tipo' => $tipo, 'fecha' => $fecha, 'titulo' => $titulo, 'detalle' => $detalle, 'enlace' => $enlace, 'autor' => $autor];
    }
}
