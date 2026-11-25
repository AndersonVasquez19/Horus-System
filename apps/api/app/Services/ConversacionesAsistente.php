<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatLog;
use App\Support\BusquedaLike;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\IntencionesAsistente;
use App\Support\SenalesAsistente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * El registro del chat visto como conversaciones. La pregunta del visitante no se guarda: cada intercambio muestra su
 * tema (si se registró) y lo que respondió el asistente. El `session_id` nunca sale de aquí; el panel recibe un código
 * corto derivado de él solo para distinguir a un visitante de otro.
 */
final class ConversacionesAsistente
{
    public const POR_PAGINA = 10;

    /** De una conversación muy larga se muestran los últimos intercambios. */
    private const MAX_INTERCAMBIOS = 30;

    /** Filtro de tema que reúne las tres intenciones de «no tengo ese dato». */
    public const TEMA_SIN_DATO = 'sin_dato';

    /**
     * @param  array{busqueda: string, etapa: string, tema: string, fuente: string, voto: string, canal: string, dias: int|null}  $filtros
     * @return array{conversaciones: list<array<string, mixed>>, pagina: int, total: int, paginas: int}
     */
    public function listar(array $filtros, int $pagina): array
    {
        $tabla = (new ChatLog)->getTable();
        $conCanal = Schema::hasColumn($tabla, 'canal');
        $conVoto = Schema::hasColumn($tabla, 'voto');
        $etapa = $filtros['etapa'] === SenalesAsistente::WHATSAPP && ! Schema::hasColumn($tabla, 'abrio_whatsapp')
            ? '' : $filtros['etapa'];
        $delCanal = static fn (): Builder => ChatLog::query()->when($conCanal, fn (Builder $q) => $filtros['canal'] === 'admin'
            ? $q->where('canal', 'admin')
            : $q->where(fn (Builder $w) => $w->whereNull('canal')->orWhere('canal', 'web')));

        $coinciden = $delCanal()
            ->when($filtros['dias'] !== null, fn (Builder $q) => $q->where('created_at', '>=', now()->subDays((int) $filtros['dias'])))
            ->when($filtros['tema'] === self::TEMA_SIN_DATO, fn (Builder $q) => $q->whereIn('intent_detectado', IntencionesAsistente::SIN_DATO))
            ->when($filtros['tema'] !== '' && $filtros['tema'] !== self::TEMA_SIN_DATO, fn (Builder $q) => $q->where('intent_detectado', $filtros['tema']))
            ->when($filtros['fuente'] !== '', fn (Builder $q) => $q->where('fuente', $filtros['fuente']))
            ->when($conVoto && $filtros['voto'] !== '', fn (Builder $q) => $q->where('voto', $filtros['voto']));
        if ($etapa !== '') {
            SenalesAsistente::filtrar($coinciden, $etapa);
        }
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($coinciden, ['respuesta_bot'], $filtros['busqueda']);
        }

        // Una conversación entra si alguno de sus intercambios coincide; después se muestra completa.
        $paginado = $coinciden->toBase()
            ->selectRaw('session_id, max(created_at) as ultima, max(id) as ultimo_id')
            ->groupBy('session_id')
            ->orderByDesc('ultima')->orderByDesc('ultimo_id')
            ->paginate(self::POR_PAGINA, ['*'], 'page', $pagina);

        $sesiones = array_map(static fn (mixed $fila): string => Entrada::texto(((array) $fila)['session_id'] ?? ''), $paginado->items());
        $filas = $sesiones === [] ? new Collection : $delCanal()->whereIn('session_id', $sesiones)
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('session_id');

        $conversaciones = [];
        foreach ($sesiones as $sesion) {
            /** @var Collection<int, ChatLog> $propias */
            $propias = $filas->get($sesion) ?? new Collection;
            $ultima = $propias->last();
            if (! $ultima instanceof ChatLog) {
                continue;
            }
            $temas = [];
            foreach ($propias as $fila) {
                $tema = Entrada::texto($fila->getAttribute('intent_detectado'));
                if ($tema !== '' && ! in_array($tema, $temas, true)) {
                    $temas[] = $tema;
                }
            }
            $conversaciones[] = [
                'clave' => Entrada::entero($ultima->getKey()),
                'visitante' => strtoupper(substr(hash('sha256', $sesion), 0, 4)),
                'probador' => $conCanal && $ultima->getAttribute('canal') === 'admin',
                'ultima_local' => HoraLocal::formato($ultima->created_at),
                'total' => $propias->count(),
                'temas' => $temas,
                'etapas' => SenalesAsistente::deConversacion($propias),
                'contingencia' => $propias->contains(static fn (ChatLog $fila): bool => $fila->getAttribute('fuente') === 'respaldo'),
                'vista_previa' => Str::limit(Str::squish(Entrada::texto($ultima->getAttribute('respuesta_bot'))), 160, '…'),
                'utiles' => $conVoto ? $propias->where('voto', 'up')->count() : 0,
                'no_utiles' => $conVoto ? $propias->where('voto', 'down')->count() : 0,
                'intercambios' => $propias->slice(-self::MAX_INTERCAMBIOS)->map(fn (ChatLog $fila): array => $this->intercambio($fila))->values()->all(),
            ];
        }

        return [
            'conversaciones' => $conversaciones,
            'pagina' => $paginado->currentPage(),
            'total' => $paginado->total(),
            'paginas' => $paginado->lastPage(),
        ];
    }

    /** @return list<string> Temas registrados, para el filtro. */
    public function temas(): array
    {
        $temas = [];
        foreach (ChatLog::query()->whereNotNull('intent_detectado')->where('intent_detectado', '!=', '')
            ->distinct()->orderBy('intent_detectado')->pluck('intent_detectado') as $tema) {
            $temas[] = Entrada::texto($tema);
        }

        return $temas;
    }

    /** @return array<string, mixed> */
    private function intercambio(ChatLog $fila): array
    {
        $respuesta = Entrada::texto($fila->getAttribute('respuesta_bot'));
        $tema = Entrada::texto($fila->getAttribute('intent_detectado'));
        $voto = $fila->getAttribute('voto');

        return [
            'id' => Entrada::entero($fila->getKey()),
            'hora_local' => HoraLocal::formato($fila->created_at, 'd/m H:i'),
            'tema' => $tema === '' ? null : $tema,
            'respuesta' => $respuesta,
            'privacidad' => $respuesta === AsistenteVirtualService::RESPUESTA_PRIVACIDAD,
            'contingencia' => $fila->getAttribute('fuente') === 'respaldo',
            'voto' => in_array($voto, ['up', 'down'], true) ? $voto : null,
        ];
    }
}
