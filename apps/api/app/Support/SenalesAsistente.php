<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChatLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hasta dónde llegó cada conversación del asistente. Es la única definición de las etapas: la usan las etiquetas, el
 * filtro y los conteos del panel, para que las cifras coincidan.
 *
 * Se calculan al leer, con el tema guardado y con frases de las respuestas oficiales (así también se etiqueta el
 * historial anterior a las métricas, que no guardó tema; ahí es una estimación). La excepción es «abrió WhatsApp»,
 * que es un clic registrado. Si cambia una de estas frases en `knowledge.json`, `SenalesAsistenteTest` falla.
 */
final class SenalesAsistente
{
    public const INTERESADO = 'interesado';

    public const MATRICULA = 'matricula';

    public const CONTACTO = 'contacto';

    public const RECLAMOS = 'reclamos';

    public const WHATSAPP = 'whatsapp';

    /** De la etapa más avanzada a la menos: es el orden de las etiquetas. */
    public const ETAPAS = [self::WHATSAPP, self::MATRICULA, self::CONTACTO, self::RECLAMOS, self::INTERESADO];

    /** @var array<string, array{temas: list<string>, empieza: list<string>, contiene: list<string>}> */
    private const REGLAS = [
        self::INTERESADO => [
            'temas' => ['training_info', 'followup_attribute', 'course_recommendation', 'advisory_info', 'service_info',
                'service_recommendation', 'quote_request'],
            'empieza' => ['«', 'Cursos y capacitaciones vigentes', 'Cursos vigentes', 'En modalidad ', 'Los cursos se ofrecen',
                'Ofrecemos cursos', 'Ofrecemos capacitaciones', 'Ofrecemos asesoramiento', 'El asesoramiento',
                'Horus Group ofrece asesoramiento', 'Las capacitaciones empresariales', 'La página de cursos',
                'Nuestra sede física está en Cajamarca', 'No encontré un curso', 'Por ahora no tengo un curso',
                'No tengo un calendario vigente', 'No tengo información pública sobre cupos',
                'No tengo información confirmada sobre promociones', 'Estas opciones se ajustan', 'Te ayudo a elegir'],
            'contiene' => ['No tengo precios de cursos', 'Modalidad: '],
        ],
        self::MATRICULA => [
            'temas' => ['enrollment_request'],
            'empieza' => [],
            'contiene' => ['Para matricularte sigue estos pasos', 'Para consultar la matrícula', 'coordinar tu matrícula'],
        ],
        self::CONTACTO => [
            'temas' => ['human_handoff'],
            'empieza' => ['Puedes comunicarte con Horus Group'],
            'contiene' => [],
        ],
        self::RECLAMOS => [
            'temas' => [],
            'empieza' => [],
            'contiene' => ['Libro de Reclamaciones virtual'],
        ],
    ];

    /** @return list<string> Etapas que cumple un intercambio. */
    public static function delIntercambio(ChatLog $fila): array
    {
        $tema = Entrada::texto($fila->getAttribute('intent_detectado'));
        $respuesta = Entrada::texto($fila->getAttribute('respuesta_bot'));
        $etapas = [];
        foreach (self::REGLAS as $etapa => $regla) {
            if (in_array($tema, $regla['temas'], true) || self::coincide($respuesta, $regla['empieza'], $regla['contiene'])) {
                $etapas[] = $etapa;
            }
        }
        if ((bool) $fila->getAttribute('abrio_whatsapp')) {
            $etapas[] = self::WHATSAPP;
        }

        return $etapas;
    }

    /**
     * @param  list<string>  $empieza
     * @param  list<string>  $contiene
     */
    private static function coincide(string $respuesta, array $empieza, array $contiene): bool
    {
        foreach ($empieza as $frase) {
            if (str_starts_with($respuesta, $frase)) {
                return true;
            }
        }
        foreach ($contiene as $frase) {
            if (str_contains($respuesta, $frase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Etiquetas de una conversación, de la más avanzada a la menos. «Interesado» no se repite si ya quiso
     * matricularse o abrió WhatsApp.
     *
     * @param  iterable<ChatLog>  $filas
     * @return list<string>
     */
    public static function deConversacion(iterable $filas): array
    {
        $vistas = [];
        foreach ($filas as $fila) {
            foreach (self::delIntercambio($fila) as $etapa) {
                $vistas[$etapa] = true;
            }
        }
        if (isset($vistas[self::MATRICULA]) || isset($vistas[self::WHATSAPP])) {
            unset($vistas[self::INTERESADO]);
        }

        return array_values(array_filter(self::ETAPAS, static fn (string $etapa): bool => isset($vistas[$etapa])));
    }

    /**
     * La misma regla en la base. «Mostró interés» incluye a quien quiso matricularse.
     * `whatsapp` exige la columna de la migración de contacto: quien llama lo comprueba.
     *
     * @param  Builder<ChatLog>  $consulta
     * @return Builder<ChatLog>
     */
    public static function filtrar(Builder $consulta, string $etapa): Builder
    {
        if ($etapa === self::WHATSAPP) {
            return $consulta->where('abrio_whatsapp', true);
        }
        $reglas = array_map(static fn (string $nombre): array => self::REGLAS[$nombre],
            $etapa === self::INTERESADO ? [self::INTERESADO, self::MATRICULA] : array_values(array_intersect([$etapa], array_keys(self::REGLAS))));

        return $consulta->where(function (Builder $o) use ($reglas): void {
            $o->whereRaw('1 = 0');
            foreach ($reglas as $regla) {
                if ($regla['temas'] !== []) {
                    $o->orWhereIn('intent_detectado', $regla['temas']);
                }
                foreach ($regla['empieza'] as $frase) {
                    $o->orWhere('respuesta_bot', 'like', $frase.'%');
                }
                foreach ($regla['contiene'] as $frase) {
                    $o->orWhere('respuesta_bot', 'like', '%'.$frase.'%');
                }
            }
        });
    }
}
