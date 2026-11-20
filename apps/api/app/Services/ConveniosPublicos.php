<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Entrada;

/** La web y el asistente leen las mismas instituciones, descripciones y vigencias. */
class ConveniosPublicos
{
    /** @return list<array<string, mixed>> */
    public function tarjetas(): array
    {
        $datos = Entrada::lista(json_decode((string) file_get_contents(Entrada::texto(config('horus-knowledge.path'))), true, 512, JSON_THROW_ON_ERROR));
        $tarjetas = [];
        foreach (Entrada::lista($datos['records'] ?? []) as $registro) {
            if (! is_array($registro)) {
                continue;
            }
            if (isset($registro['public_card']) && $registro['category'] === 'convenio') {
                $tarjetas[] = [...Entrada::lista($registro['public_card']), 'nombre' => $registro['title'], 'descripcion' => $registro['description']];
            }
        }

        return $tarjetas;
    }

    public function listado(): string
    {
        return "Tenemos convenios con:\n".implode("\n", array_map(static fn (array $tarjeta): string => '- '.Entrada::texto($tarjeta['nombre']), $this->tarjetas()));
    }
}
