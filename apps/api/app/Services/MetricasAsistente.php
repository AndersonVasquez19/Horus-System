<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatLog;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\IntencionesAsistente;
use App\Support\SenalesAsistente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Cifras del asistente para el panel, calculadas sobre el registro del chat (nunca sobre texto del visitante).
 * Las pruebas del probador (`canal = admin`) no cuentan. Funciona antes de aplicar la migración de métricas: en ese
 * caso no separa canales y los votos salen como no disponibles.
 */
final class MetricasAsistente
{
    public const PERIODOS = [7, 30];

    private const DIAS_DEL_GRAFICO = 7;

    private const TEMAS_VISIBLES = 8;

    /** Saludos, gracias y despedidas: se agrupan para que no dominen la lista de temas. */
    private const CORTESIA = ['greeting', 'thanks', 'farewell', 'acknowledgement'];

    /**
     * @return array{dias: int, consultas: int, conversaciones: int, respondidas: int, contingencia: int,
     *     sin_origen: int, con_tema: int, sin_dato: int, temas: list<array{nombre: string, total: int}>,
     *     etapas: array{interesado: int, matricula: int, contacto: int, reclamos: int, whatsapp: int|null},
     *     por_dia: list<array{etiqueta: string, total: int}>,
     *     votos: array{utiles: int, no_utiles: int}|null, separa_canal: bool}
     */
    public function resumen(int $dias = 30): array
    {
        $dias = in_array($dias, self::PERIODOS, true) ? $dias : 30;
        $tabla = (new ChatLog)->getTable();
        $conCanal = Schema::hasColumn($tabla, 'canal');
        $conVoto = Schema::hasColumn($tabla, 'voto');
        $publicas = static fn (): Builder => ChatLog::query()->when($conCanal, fn (Builder $q) => $q->where(
            fn (Builder $w) => $w->whereNull('canal')->orWhere('canal', 'web')));
        $base = static fn (): Builder => $publicas()->where('created_at', '>=', now()->subDays($dias));
        // Conversaciones distintas que alcanzaron cada etapa (misma regla que el filtro del listado).
        $conversacionesEn = static fn (string $etapa): int => SenalesAsistente::filtrar($base(), $etapa)
            ->distinct()->count('session_id');
        $conWhatsapp = Schema::hasColumn($tabla, 'abrio_whatsapp');
        $conTema = static fn (): Builder => $base()->whereNotNull('intent_detectado')->where('intent_detectado', '!=', '');

        $temas = [];
        foreach ($conTema()->toBase()->selectRaw('intent_detectado as nombre, count(*) as total')->groupBy('intent_detectado')->get() as $fila) {
            $datos = (array) $fila;
            $nombre = Entrada::texto($datos['nombre'] ?? '');
            $nombre = in_array($nombre, self::CORTESIA, true) ? 'cortesia' : $nombre;
            $temas[$nombre] = ($temas[$nombre] ?? 0) + Entrada::entero($datos['total'] ?? 0);
        }
        ksort($temas);
        arsort($temas);

        $porDia = [];
        $hoy = HoraLocal::ahora()->startOfDay();
        for ($atras = self::DIAS_DEL_GRAFICO - 1; $atras >= 0; $atras--) {
            $inicio = $hoy->subDays($atras);
            $porDia[] = [
                'etiqueta' => $inicio->format('d/m'),
                'total' => $publicas()->where('created_at', '>=', $inicio->utc())
                    ->where('created_at', '<', $inicio->addDay()->utc())->count(),
            ];
        }

        $consultas = $base()->count();
        $respondidas = $base()->where('fuente', 'advisor')->count();
        $contingencia = $base()->where('fuente', 'respaldo')->count();

        return [
            'dias' => $dias,
            'consultas' => $consultas,
            'conversaciones' => $base()->distinct()->count('session_id'),
            'respondidas' => $respondidas,
            'contingencia' => $contingencia,
            // Registros antiguos sin motor anotado: no entran en el porcentaje de respondidas.
            'sin_origen' => max(0, $consultas - $respondidas - $contingencia),
            'etapas' => [
                'interesado' => $conversacionesEn(SenalesAsistente::INTERESADO),
                'matricula' => $conversacionesEn(SenalesAsistente::MATRICULA),
                'contacto' => $conversacionesEn(SenalesAsistente::CONTACTO),
                'reclamos' => $conversacionesEn(SenalesAsistente::RECLAMOS),
                'whatsapp' => $conWhatsapp ? $conversacionesEn(SenalesAsistente::WHATSAPP) : null,
            ],
            // El «sin dato» solo puede medirse sobre las consultas que guardaron su tema.
            'con_tema' => $conTema()->count(),
            'sin_dato' => $base()->whereIn('intent_detectado', IntencionesAsistente::SIN_DATO)->count(),
            'temas' => array_map(static fn (string $nombre, int $total): array => ['nombre' => $nombre, 'total' => $total],
                array_keys(array_slice($temas, 0, self::TEMAS_VISIBLES, true)),
                array_values(array_slice($temas, 0, self::TEMAS_VISIBLES, true))),
            'por_dia' => $porDia,
            'votos' => $conVoto ? [
                'utiles' => $base()->where('voto', 'up')->count(),
                'no_utiles' => $base()->where('voto', 'down')->count(),
            ] : null,
            'separa_canal' => $conCanal,
        ];
    }
}
