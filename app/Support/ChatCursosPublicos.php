<?php

declare(strict_types=1);

namespace App\Support;

class ChatCursosPublicos
{
    public const INTENTS = ['consulta_cursos', 'consulta_modalidades', 'consulta_horarios_cursos', 'consulta_matricula', 'consulta_requisitos'];

    public function __construct(private ChatIntentMatcher $matcher) {}

    /** @param list<array<string, string|null>> $fichas */
    public function mencionaTitulo(string $text, array $fichas): bool
    {
        return $this->coincidencias($text, $fichas) !== [];
    }

    /**
     * @param  list<array<string, string|null>>  $fichas
     * @return array{respuesta: string, intent: string|null, fuente: string}
     */
    public function responder(string $text, string $intent, array $fichas): array
    {
        $matches = $this->coincidencias($text, $fichas);
        $link = ' Consulta /educacion/cursos o /contactos.';
        if (count($matches) > 1) {
            $titles = implode(', ', array_column(array_slice($matches, 0, 3), 'titulo'));

            return ['respuesta' => 'Hay varias fichas que coinciden: '.$titles.'. Indica el título completo del curso.', 'intent' => null, 'fuente' => 'aclaracion'];
        }
        if ($matches === [] && preg_match('/\bcurso (de |del )?(?!publicados?\b|disponibles?\b|ofrecen\b)([a-z0-9]+)\b/', $text)) {
            return ['respuesta' => 'No encuentro una ficha pública vigente para ese curso. No puedo confirmar su información.'.$link, 'intent' => $intent, 'fuente' => 'local'];
        }
        $selected = $matches ?: $fichas;
        if ($selected === []) {
            $answer = match ($intent) {
                'consulta_horarios_cursos' => 'No hay horarios de cursos publicados; el sitio no publica un calendario vigente.',
                'consulta_matricula', 'consulta_requisitos' => 'No hay información de inscripción por curso publicada. No hay una lista general de documentos publicada en el sitio. Un asesor puede orientarte al +51 927 582 305.',
                default => 'No hay cursos con una ficha pública vigente. No puedo confirmar modalidades ni información de inscripción por curso.',
            };

            return ['respuesta' => $answer.$link, 'intent' => $intent, 'fuente' => 'local'];
        }
        if ($intent === 'consulta_modalidades' && $matches === []) {
            $answer = 'Modalidades de los cursos publicados: '.implode(', ', array_unique(array_column($selected, 'modalidad'))).'.';
        } else {
            $lines = [];
            foreach (array_slice($selected, 0, $intent === 'consulta_cursos' && $matches === [] ? 5 : 3) as $ficha) {
                $detail = match ($intent) {
                    'consulta_horarios_cursos' => $ficha['horario'] ?: 'Horario no publicado.',
                    'consulta_matricula', 'consulta_requisitos' => $ficha['inscripcion'] ?: 'Información de inscripción no publicada.',
                    'consulta_modalidades' => 'Modalidad: '.$ficha['modalidad'].'.',
                    default => $matches === [] ? 'Modalidad: '.$ficha['modalidad'].'.' : $ficha['resumen'].' Modalidad: '.$ficha['modalidad'].'.',
                };
                $lines[] = $ficha['titulo'].': '.$detail;
            }
            $answer = implode("\n", $lines);
            if (count($selected) > count($lines)) {
                $answer .= "\nHay más fichas en el catálogo público.";
            }
        }

        return ['respuesta' => $answer.$link, 'intent' => $intent, 'fuente' => 'local'];
    }

    /**
     * @param  list<array<string, string|null>>  $fichas
     * @return list<array<string, string|null>>
     */
    private function coincidencias(string $text, array $fichas): array
    {
        $matches = [];
        $exact = array_values(array_filter($fichas, fn (array $ficha): bool => str_contains(' '.$text.' ', ' '.$this->matcher->normalizar((string) $ficha['titulo']).' ')));
        if (count($exact) === 1) {
            return $exact;
        }
        $ignored = ['curso', 'cursos', 'taller', 'introduccion', 'nivel', 'para', 'con', 'del', 'los', 'las'];
        foreach ($fichas as $ficha) {
            $tokens = explode(' ', $this->matcher->normalizar((string) $ficha['titulo']));
            foreach ($tokens as $token) {
                if (mb_strlen($token) >= 3 && ! in_array($token, $ignored, true) && str_contains(' '.$text.' ', ' '.$token.' ')) {
                    $matches[] = $ficha;
                    break;
                }
            }
        }

        return $matches;
    }
}
