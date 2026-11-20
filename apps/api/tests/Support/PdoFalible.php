<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOException;

/**
 * PDO SQLite de prueba: su rollback puede fallar a voluntad y cuenta los commit recibidos.
 * Sirve para ejercitar los caminos de recuperación de la migración 000032; no es un doble de PostgreSQL.
 */
class PdoFalible extends PDO
{
    public bool $fallarRollback = false;

    public int $commits = 0;

    public function rollBack(): bool
    {
        if ($this->fallarRollback) {
            throw new PDOException('rollback simulado fallido');
        }

        return parent::rollBack();
    }

    public function commit(): bool
    {
        $this->commits++;

        return parent::commit();
    }
}
