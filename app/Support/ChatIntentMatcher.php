<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChatIntent;

class ChatIntentMatcher
{
    public function normalizar(string $texto): string
    {
        $texto = strtr(mb_strtolower($texto, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $texto = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texto) ?? '');

        // Erratas explícitas: no aplicar distancia difusa a nombres o datos personales.
        return implode(' ', array_map(fn (string $word) => [
            'cursso' => 'curso', 'cursosss' => 'cursos', 'cursoss' => 'cursos',
            'matriclarme' => 'matricularme', 'inscrbirme' => 'inscribirme',
            'horairos' => 'horarios', 'horaros' => 'horarios', 'requisistos' => 'requisitos',
            'contcto' => 'contacto', 'servicos' => 'servicios', 'capacitacines' => 'capacitaciones',
        ][$word] ?? $word, explode(' ', $texto)));
    }

    /** @param array<int, ChatIntent> $intents */
    public function buscar(string $mensaje, array $intents): ?ChatIntent
    {
        $texto = ' '.$this->normalizar($mensaje).' ';
        if ($this->bloqueado($texto) || preg_match('/\b(no quiero|no busco|no necesito)\b/', $texto)) {
            return null;
        }
        // Una mención incidental no expresa una consulta. Las frases cortas sí sirven como tema.
        $consulta = count(explode(' ', trim($texto))) <= 3
            || preg_match('/\b(que|como|cuando|donde|cuanto|cuales|cual|necesito|quiero|busco|ofrecen|tienen|brindan|informacion|consulta|puedo|perdi|falte|recuperar|reprogramar|horarios|requisitos|servicios|cursos disponibles|compara|comparar|orientame|ayudame)\b/', $texto);
        $matches = [];
        foreach ($intents as $intent) {
            foreach ($intent->keywords ?? [] as $keyword) {
                $key = $this->normalizar((string) $keyword);
                if ($key === '' || ! str_contains($texto, ' '.$key.' ')) {
                    continue;
                }
                if (! $consulta && trim($texto) !== $key) {
                    continue;
                }
                if (in_array($intent->nombre, ['saludo', 'despedida'], true)
                    && ! preg_match('/^(hola|hey|buenos dias|buenas tardes|buenas noches|gracias|muchas gracias|adios|chao|hasta luego|nos vemos)( horus)?$/', trim($texto))) {
                    continue;
                }
                $matches[$intent->nombre] = $intent;
            }
        }

        // Las preguntas concretas prevalecen sobre el tema general de cursos.
        $specific = array_diff_key($matches, array_flip(['consulta_cursos', 'consulta_capacitaciones', 'saludo', 'despedida']));
        if (isset($specific['consulta_requisitos'])) {
            unset($specific['consulta_matricula']);
        }
        if (isset($specific['consulta_camaras']) || isset($specific['consulta_cableado']) || isset($specific['consulta_soporte'])) {
            unset($specific['consulta_servicios']);
        }
        if (isset($specific['consulta_horarios_cursos'])) {
            unset($specific['consulta_horarios']);
        }
        // Dos asuntos específicos requieren aclaración, no una elección por prioridad.
        if (count($specific) > 1) {
            return null;
        }
        $matches = $specific ?: $matches;
        if (count($matches) !== 1) {
            return null;
        }

        return reset($matches);
    }

    public function bloqueado(string $texto): bool
    {
        return (bool) preg_match('/\b(ignora|ignore|olvida|override|system|prompt|instrucciones|instruccion|secretos?|credenciales|password|contrasena|token|api key|administrador|administrativo|panel|dni|notas|matriculados|estudiantes|revela|finge|inventa|pretend|jailbreak|futbol|receta|clima|bitcoin|dolar|poema|historia|traduce|calcula)\b/', $texto);
    }
}
