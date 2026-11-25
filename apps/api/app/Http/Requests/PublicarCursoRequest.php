<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ExportadorCatalogoAdvisor;
use App\Support\Entrada;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class PublicarCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cursos.publicar') ?? false;
    }

    /** @return array<string, list<string|In>> */
    public function rules(): array
    {
        // `after_or_equal:vigente_desde` no tolera que ese campo llegue como array (TypeError y 500): solo se compara
        // contra un texto; si no lo es, ya lo rechaza la regla propia de `vigente_desde` con un 422.
        $comparaConDesde = is_string($this->input('vigente_desde')) ? ['after_or_equal:vigente_desde'] : [];

        return [
            'titulo' => ['required', 'string', 'min:3', 'max:120', 'not_regex:/[<>]/u'],
            'resumen' => ['required', 'string', 'min:10', 'max:500'],
            'modalidad' => ['required', Rule::in(['presencial', 'semipresencial', 'virtual', 'hibrido'])],
            'horario' => ['nullable', 'string', 'max:200', 'not_regex:/[<>]/u'],
            // Opcional, pero nunca de hoy ni pasada (día de Lima). También al volver a guardar una ficha ya publicada.
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d', 'after:'.now('America/Lima')->toDateString(), 'before_or_equal:2035-12-31'],
            'duracion' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/u'],
            'certificacion' => ['nullable', 'string', 'max:200', 'not_regex:/[<>]/u'],
            'temario' => ['nullable', 'string', 'max:1500', 'not_regex:/[<>]/u'],
            'inscripcion' => ['nullable', 'string', 'max:400', 'not_regex:/[<>]/u'],
            'vigente_desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:2035-12-31'],
            'vigente_hasta' => ['required', 'date_format:Y-m-d', ...$comparaConDesde, 'after_or_equal:'.now('America/Lima')->toDateString(), 'before_or_equal:2035-12-31'],
            'confirmacion_publica' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['fecha_inicio.after' => 'La fecha de inicio debe ser posterior a hoy. Si el curso ya empezó, deja el campo vacío o indica la próxima fecha.'];
    }

    /**
     * La ficha viaja luego al catálogo del asistente (`ExportadorCatalogoAdvisor`), que descarta TODO el catálogo si
     * una sola ficha contiene correos, teléfonos, documentos o vocabulario administrativo. Se rechaza aquí, al
     * guardar, con la misma regla y señalando el campo, para que una ficha no deje sin cursos al asistente.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $mensaje = 'No puede incluir correos, teléfonos, documentos ni palabras como «estudiantes», «clientes» o «DNI»: la ficha es pública y la lee el asistente.';
            $campos = ['titulo', 'resumen', 'inscripcion', 'horario', 'duracion', 'certificacion', 'temario'];
            foreach ($campos as $campo) {
                $valor = $this->input($campo);
                if (is_string($valor) && preg_match(ExportadorCatalogoAdvisor::PRIVACIDAD, $valor) === 1) {
                    $validator->errors()->add($campo, $mensaje);
                }
            }
            // El exportador evalúa los campos unidos: un número partido entre dos campos también cuenta.
            if ($validator->errors()->isEmpty()) {
                $unido = implode(' ', array_map(fn (string $campo): string => trim(Entrada::texto($this->input($campo))), ['titulo', 'resumen', 'inscripcion', 'horario', 'duracion', 'certificacion']));
                if (preg_match(ExportadorCatalogoAdvisor::PRIVACIDAD, $unido) === 1) {
                    $validator->errors()->add('resumen', 'Revisa título, resumen, horario, duración y certificación: juntos parecen contener un número de teléfono o documento.');
                }
            }
        }];
    }
}
