<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoCertificado: string
{
    case Emitido = 'emitido';
    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Emitido => 'Emitido',
            self::Anulado => 'Anulado',
        };
    }
}
