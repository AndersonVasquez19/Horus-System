<?php

declare(strict_types=1);

namespace App\Services;

/** Contrato compartido con catalog_documents.split_content: máximo 600 caracteres Unicode. */
class FragmentosCatalogoAdvisor
{
    /** @return list<string> */
    public static function textos(string $contenido): array
    {
        $normalizado = trim(preg_replace('/(*UCP)[\s\x{001C}-\x{001F}]+/u', ' ', $contenido) ?? '');
        if (mb_strlen($normalizado) <= 600) {
            return $normalizado === '' ? [] : [$normalizado];
        }
        $fragmentos = [];
        foreach (preg_split('/(*UCP)\n[\s\x{001C}-\x{001F}]*\n/u', $contenido) ?: [] as $parrafo) {
            $texto = trim(preg_replace('/(*UCP)[\s\x{001C}-\x{001F}]+/u', ' ', $parrafo) ?? '');
            while (mb_strlen($texto) > 600) {
                $fin = mb_strrpos(mb_substr($texto, 0, 601), ' ');
                $fin = $fin !== false && $fin > 0 ? $fin : 600;
                $fragmentos[] = mb_substr($texto, 0, $fin);
                $texto = trim(mb_substr($texto, $fin));
            }
            if ($texto !== '') {
                $fragmentos[] = $texto;
            }
        }

        $agrupados = [];
        foreach ($fragmentos as $fragmento) {
            $ultimo = count($agrupados) - 1;
            if ($ultimo >= 0 && mb_strlen($agrupados[$ultimo]) + 1 + mb_strlen($fragmento) <= 600) {
                $agrupados[$ultimo] .= ' '.$fragmento;
            } else {
                $agrupados[] = $fragmento;
            }
        }

        return $agrupados;
    }
}
