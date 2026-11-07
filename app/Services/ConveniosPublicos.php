<?php

declare(strict_types=1);

namespace App\Services;

/** La web y el asistente leen las mismas instituciones, descripciones y vigencias. */
class ConveniosPublicos
{
    /** @return list<array<string, mixed>> */
    public function tarjetas(): array
    {
        $datos = json_decode((string) file_get_contents(base_path('chatbot-python/data/knowledge.json')), true, 512, JSON_THROW_ON_ERROR);
        $tarjetas = [];
        foreach ($datos['records'] as $registro) {
            if (isset($registro['public_card']) && $registro['category'] === 'convenio') {
                $tarjetas[] = [...$registro['public_card'], 'nombre' => $registro['title'], 'descripcion' => $registro['description']];
            }
        }

        return $tarjetas;
    }

    public function listado(): string
    {
        return "Tenemos convenios con:\n".implode("\n", array_map(static fn (array $tarjeta): string => '- '.$tarjeta['nombre'], $this->tarjetas()));
    }
}
