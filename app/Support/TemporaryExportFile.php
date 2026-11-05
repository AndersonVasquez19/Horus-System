<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\File\File;

/** Temporal privado: se elimina incluso si la respuesta nunca llega a enviarse. */
final class TemporaryExportFile extends File
{
    public function __construct(string $path, bool $checkPath = true)
    {
        parent::__construct($path, $checkPath);
        // Respaldo para errores fatales y finalización de la petición antes del envío.
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });
    }

    public function __destruct()
    {
        if ($this->isFile()) {
            @unlink($this->getPathname());
        }
    }
}
