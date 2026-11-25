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

        $delPeriodo = static fn (): Builder => $delCanal()
            ->when($filtros['dias'] !== null, fn (Builder $q) => $q->where('created_at', '>=', now()->subDays((int) $filtros['dias'])));

        $coinciden = $delPeriodo()
            ->when($filtros['tema'] === self::TEMA_SIN_DATO, fn (Builder $q) => $q->whereIn('intent_detectado', IntencionesAsistente::SIN_DATO))
            ->when($filtros['tema'] !== '' && $filtros['tema'] !== self::TEMA_SIN_DATO, fn (Builder $q) => $q->where('intent_detectado', $filtros['tema']))
            ->when($filtros['fuente'] !== '', fn (Builder $q) => $q->where('fuente', $filtros['fuente']))
            ->when($conVoto && $filtros['voto'] !== '', fn (Builder $q) => $q->where('voto', $filtros['voto']));
        // La etapa es de la conversación, no de una respuesta: la respuesta que la dio y la que cumple los demás filtros
        // pueden ser distintas.
        if ($etapa !== '') {
            $coinciden->whereIn('session_id', SenalesAsistente::filtrar($delPeriodo(), $etapa)->select('session_id'));
        }
        if ($filtros['busqueda'] !== '') {
            BusquedaLike::contiene($coinciden, ['respuesta_bot'], $filtros['busqueda']);
        }

        // Con los demás filtros, una conversación entra si alguno de sus intercambios coincide; después se muestra completa.
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
                // Un bloqueo por privacidad es una protección, no una falla: se anota aparte de la contingencia.
                'privacidad' => $propias->contains(fn (ChatLog $fila): bool => $this->esBloqueo($fila)),
                'contingencia' => $propias->contains(fn (ChatLog $fila): bool => $fila->getAttribute('fuente') === 'respaldo' && ! $this->esBloqueo($fila)),
                'vista_previa' => $this->vistaPrevia(Entrada::texto($ultima->getAttribute('respuesta_bot'))),
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

    private function esBloqueo(ChatLog $fila): bool
    {
        return $fila->getAttribute('respuesta_bot') === AsistenteVirtualService::RESPUESTA_PRIVACIDAD;
    }

    /** Comienzo de una respuesta en una línea; el enlace de WhatsApp, largo e ilegible, se resume antes de recortar. */
    private function vistaPrevia(string $respuesta): string
    {
        $texto = preg_replace('~https://wa\.me/\S*[^\s.,;:)]~u', 'enlace de WhatsApp', $respuesta) ?? $respuesta;

        return Str::limit(Str::squish($texto), 160, '…');
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
            'privacidad' => $this->esBloqueo($fila),
            'contingencia' => $fila->getAttribute('fuente') === 'respaldo',
            'voto' => in_array($voto, ['up', 'down'], true) ? $voto : null,
        ];
    }
}
