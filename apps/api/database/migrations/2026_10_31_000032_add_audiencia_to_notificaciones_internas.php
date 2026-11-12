<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Privacidad de notificaciones internas: audiencia explícita, independiente de user_id.
 *
 * Hasta ahora user_id NULL significaba «general», pero una notificación personal también puede quedar
 * con user_id NULL: la FK nullOnDelete() lo hace al eliminar al usuario (por Eloquent o por Query
 * Builder), y nada impedía un UPDATE explícito a NULL. Esas filas pasaban a verse como generales.
 *
 * Clasificación histórica, sin adivinar:
 *  - user_id con valor  → personal.
 *  - user_id NULL       → indeterminada (general legítima o personal huérfana: no se puede saber). No se
 *                         reconstruyen destinatarios por título, mensaje ni coincidencias de texto.
 * No se modifica ningún otro dato (mensajes, fechas, lectura). La FK y su nullOnDelete() se conservan.
 *
 * Reglas (CHECK + función de trigger en PostgreSQL; triggers equivalentes en SQLite, como en 000027 y 000030):
 *  - audiencia obligatoria y en {personal, general, indeterminada};
 *  - general e indeterminada sin destinatario;
 *  - una personal se inserta con destinatario;
 *  - la audiencia no cambia nunca; user_id puede pasar a NULL (FK al eliminar al usuario o UPDATE explícito
 *    a NULL), pero nunca a otro usuario: una notificación no se reasigna.
 *
 * up() es idempotente solo sobre un esquema completo: si encuentra la columna sin todos sus objetos (o
 * con definiciones distintas), u objetos con estos nombres sin la columna, se detiene con una excepción y
 * no crea, borra ni reconstruye nada.
 *
 * Inventario PostgreSQL (tabla resuelta una vez por OID): columna NOT NULL y character varying(20); cada
 * CHECK por nombre, contype 'c', convalidated, conenforced (PostgreSQL 18) y pg_get_constraintdef() frente a
 * la definición que PostgreSQL normaliza para la misma expresión, añadida como CHECK NOT VALID de referencia
 * a la propia tabla dentro de un SAVEPOINT que se deshace (sin tablas temporales ni permiso TEMPORARY); la
 * función por esquema, nombre, cero argumentos, retorno trigger, plpgsql, prokind 'f', cuerpo literal y sin
 * sobrecargas; el trigger por tgfoid (OID de esa función), tgtype ROW|BEFORE|INSERT|UPDATE, tgenabled O/A,
 * sin WHEN, no interno ni de restricción, sin argumentos ni columnas. Un valor ausente o NULL nunca se da por
 * bueno. En PostgreSQL 18.6 (clúster aislado horus_pgt_55433) se ejecutó la primera aplicación; la versión anterior
 * del inventario (tabla temporal) falló sin TEMPORARY. Esta versión se validó allí el 31/10/2026
 * (NotificacionAudienciaPostgresTest: 29/29). La recuperación ante fallo doble de enPuntoDeRetorno() solo está
 * probada con un PDO SQLite simulado.
 */
return new class extends Migration
{
    private const TABLA = 'notificaciones_internas';

    /** @var array<string, string> */
    private const CHECKS = [
        'notificaciones_internas_audiencia_valida' => "audiencia IS NOT NULL AND audiencia IN ('personal', 'general', 'indeterminada')",
        'notificaciones_internas_general_sin_destinatario' => "audiencia <> 'general' OR user_id IS NULL",
        'notificaciones_internas_indeterminada_sin_destinatario' => "audiencia <> 'indeterminada' OR user_id IS NULL",
    ];

    private const FUNCION_PG = 'notificaciones_internas_audiencia_coherente';

    /** Punto de retorno propio del inventario (no coincide con los trans<N> de Laravel). */
    private const PUNTO_RETORNO = 'notif_audiencia_inventario';

    /** pg_trigger.tgtype esperado: ROW (1) | BEFORE (2) | INSERT (4) | UPDATE (16). */
    private const TGTYPE_PG = 1 | 2 | 4 | 16;

    /** Cuerpo de la función PostgreSQL; se compara literalmente con pg_proc.prosrc. */
    private const CUERPO_FUNCION_PG = <<<'SQL'

        BEGIN
            IF TG_OP = 'INSERT' THEN
                IF NEW.audiencia = 'personal' AND NEW.user_id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = 'check_violation',
                        MESSAGE = 'notificaciones_internas_personal_con_destinatario';
                END IF;
            ELSE
                IF NEW.audiencia IS DISTINCT FROM OLD.audiencia
                    OR (NEW.user_id IS DISTINCT FROM OLD.user_id AND NEW.user_id IS NOT NULL) THEN
                    RAISE EXCEPTION USING ERRCODE = 'check_violation',
                        MESSAGE = 'notificaciones_internas_audiencia_inmutable';
                END IF;
            END IF;
            RETURN NEW;
        END;

        SQL;

    public function up(): void
    {
        ['columna' => $columna, 'faltan' => $faltan, 'presentes' => $presentes] = $this->inventario();

        if ($columna) {
            if ($faltan === []) {
                return; // Esquema completo: no hay nada que hacer.
            }
            throw new RuntimeException('Esquema incompleto en '.self::TABLA.': la columna audiencia existe pero faltan o difieren '
                .implode(', ', $faltan).'. No se ha modificado nada; revise la instalación manualmente.');
        }

        if ($presentes !== []) {
            throw new RuntimeException('Esquema incompleto en '.self::TABLA.': la columna audiencia no existe pero ya hay objetos '
                .'con los nombres de esta migración ('.implode(', ', $presentes).'). No se ha modificado nada.');
        }

        Schema::table(self::TABLA, function (Blueprint $table): void {
            $table->string('audiencia', 20)->nullable();
        });

        DB::table(self::TABLA)->whereNotNull('user_id')->update(['audiencia' => 'personal']);
        DB::table(self::TABLA)->whereNull('user_id')->update(['audiencia' => 'indeterminada']);

        if (DB::getDriverName() === 'pgsql') {
            $this->restriccionesPostgres();
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach ($this->triggersSqlite() as $sql) {
                DB::unprepared($sql);
            }
        }
    }

    /**
     * Reversión solo si es exacta. Sin la columna, el código anterior trata user_id NULL como general:
     * si existe alguna fila sin destinatario que no sea general (personal huérfana o indeterminada), quitar
     * la columna la publicaría a todo el personal, así que se rechaza explícitamente y no se toca nada.
     * Sin esas filas, personal ⇔ user_id con valor y general ⇔ user_id NULL, y revertir no expone nada.
     */
    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLA, 'audiencia')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            // Evita que se cree una huérfana entre la comprobación y el borrado (la migración va en transacción).
            DB::statement('LOCK TABLE '.self::TABLA.' IN ACCESS EXCLUSIVE MODE');
        }

        $expuestas = DB::table(self::TABLA)
            ->whereNull('user_id')
            ->where(fn ($q) => $q->whereNull('audiencia')->orWhere('audiencia', '<>', 'general'))
            ->count();
        if ($expuestas > 0) {
            throw new RuntimeException(
                "Reversión rechazada: {$expuestas} notificación(es) de ".self::TABLA.' sin destinatario no son generales '
                .'(personales huérfanas o indeterminadas). Eliminar la columna audiencia las publicaría como generales. '
                .'Decida explícitamente qué hacer con esos registros en una migración nueva antes de revertir.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::FUNCION_PG.' ON '.self::TABLA);
            DB::statement('DROP FUNCTION IF EXISTS '.self::FUNCION_PG.'()');
            foreach (array_keys(self::CHECKS) as $nombre) {
                DB::statement('ALTER TABLE '.self::TABLA." DROP CONSTRAINT IF EXISTS $nombre");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (array_keys($this->triggersSqlite()) as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS $trigger");
            }
        }

        Schema::table(self::TABLA, function (Blueprint $table): void {
            $table->dropColumn('audiencia');
        });
    }

    /**
     * Si existe la columna, qué objetos de la migración faltan o difieren y cuáles ya existen con sus nombres.
     *
     * @return array{columna: bool, faltan: list<string>, presentes: list<string>}
     */
    private function inventario(): array
    {
        return match (DB::getDriverName()) {
            'pgsql' => $this->evaluarCatalogoPostgres($this->catalogoPostgres()),
            'sqlite' => $this->inventarioSqlite(),
            default => ['columna' => Schema::hasColumn(self::TABLA, 'audiencia'), 'faltan' => [], 'presentes' => []],
        };
    }

    /** @return array{columna: bool, faltan: list<string>, presentes: list<string>} */
    private function inventarioSqlite(): array
    {
        $columna = Schema::hasColumn(self::TABLA, 'audiencia');
        $existentes = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', self::TABLA)->pluck('sql', 'name')->all();
        $faltan = $presentes = [];
        foreach ($this->triggersSqlite() as $nombre => $sql) {
            if (! array_key_exists($nombre, $existentes)) {
                $faltan[] = $nombre;

                continue;
            }
            $presentes[] = $nombre;
            if ($this->normalizar((string) $existentes[$nombre]) !== $this->normalizar($sql)) {
                $faltan[] = "$nombre (definición distinta)";
            }
        }

        return ['columna' => $columna, 'faltan' => $faltan, 'presentes' => $presentes];
    }

    /** Cuerpo esperado de la función PostgreSQL (lo usan las pruebas de la lógica del inventario). */
    public function cuerpoFuncionPostgres(): string
    {
        return self::CUERPO_FUNCION_PG;
    }

    /**
     * Instantánea del catálogo PostgreSQL. La tabla se resuelve UNA vez (to_regclass con el mismo
     * search_path que usan los DDL de esta migración) y su OID se reutiliza en todas las consultas.
     * Consultas al catálogo y definiciones de referencia por SAVEPOINT validadas en PostgreSQL 18.6 (31/10/2026).
     *
     * @return array<string, mixed>
     */
    private function catalogoPostgres(): array
    {
        $tabla = DB::selectOne(
            "SELECT c.oid, quote_ident(n.nspname) || '.' || quote_ident(c.relname) AS nombre "
            .'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = to_regclass(?)',
            [self::TABLA]
        );
        if ($tabla === null) {
            return ['tabla_oid' => null];
        }
        $oid = (int) $tabla->oid;

        $columna = DB::selectOne(
            'SELECT attnotnull, format_type(atttypid, atttypmod) AS tipo FROM pg_attribute '
            ."WHERE attrelid = ? AND attname = 'audiencia' AND attnum > 0 AND NOT attisdropped",
            [$oid]
        );

        // conenforced existe desde PostgreSQL 18; antes toda restricción CHECK se aplica siempre.
        $version = (int) DB::selectOne("SELECT current_setting('server_version_num')::int AS v")->v;
        $aplicada = $version >= 180000 ? 'conenforced' : 'true';
        $nombres = array_keys(self::CHECKS);
        $checks = DB::select(
            "SELECT conname, contype, convalidated, {$aplicada} AS conenforced, pg_get_constraintdef(oid) AS definicion "
            .'FROM pg_constraint WHERE conrelid = ? AND conname IN (?, ?, ?)',
            [$oid, ...$nombres]
        );

        // Todas las funciones con ese nombre en el esquema de creación: así se detectan sobrecargas.
        $funciones = DB::select(
            'SELECT p.oid, p.pronargs, p.prokind, p.prorettype::regtype::text AS retorno, l.lanname AS lenguaje, p.prosrc '
            .'FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace JOIN pg_language l ON l.oid = p.prolang '
            .'WHERE n.nspname = current_schema() AND p.proname = ?',
            [self::FUNCION_PG]
        );

        $triggers = DB::select(
            'SELECT tgfoid, tgtype, tgenabled, tgisinternal, tgconstraint <> 0 AS de_restriccion, '
            .'tgqual IS NOT NULL AS con_when, tgnargs, coalesce(array_length(tgattr::int2[], 1), 0) AS columnas '
            .'FROM pg_trigger WHERE tgrelid = ? AND tgname = ?',
            [$oid, self::FUNCION_PG]
        );

        return [
            'tabla_oid' => $oid,
            'columna' => $columna === null ? null : (array) $columna,
            'checks' => array_map(fn ($f): array => (array) $f, $checks),
            'referencias' => $columna === null ? [] : $this->definicionesDeReferencia($oid, (string) $tabla->nombre),
            'funciones' => array_map(fn ($f): array => (array) $f, $funciones),
            'triggers' => array_map(fn ($f): array => (array) $f, $triggers),
        ];
    }

    /**
     * Definición que PostgreSQL normaliza para cada CHECK esperado (casts, paréntesis, ANY/ARRAY), para comparar
     * con pg_get_constraintdef() de la tabla real sin comparar texto SQL ingenuamente.
     *
     * Sin tablas temporales (el rol puede no tener TEMPORARY): cada expresión se añade a la PROPIA tabla como
     * CHECK NOT VALID (no recorre ni valida filas) con un nombre de referencia propio, se lee su definición y
     * todo se deshace con enPuntoDeRetorno(). ROLLBACK TO SAVEPOINT libera el bloqueo tomado por el ALTER.
     * Requiere ser propietario de la tabla, igual que los ALTER TABLE de up(). Tras deshacer se comprueba que no
     * quede ninguna referencia. pg_get_constraintdef() añade « NOT VALID» a estas referencias; el evaluador lo
     * quita solo de ellas.
     *
     * @return array<string, string>
     */
    private function definicionesDeReferencia(int $tablaOid, string $tablaCualificada): array
    {
        $nombres = [];
        foreach (array_keys(self::CHECKS) as $i => $nombre) {
            $nombres[$nombre] = "notif_audiencia_ref_$i";
        }

        /** @var array<string, string> $definiciones */
        $definiciones = $this->enPuntoDeRetorno(function () use ($tablaOid, $tablaCualificada, $nombres): array {
            foreach (self::CHECKS as $nombre => $condicion) {
                DB::statement("ALTER TABLE $tablaCualificada ADD CONSTRAINT {$nombres[$nombre]} CHECK ($condicion) NOT VALID");
            }
            $leidas = [];
            foreach ($nombres as $nombre => $referencia) {
                $fila = DB::selectOne('SELECT pg_get_constraintdef(oid) AS definicion FROM pg_constraint WHERE conrelid = ? AND conname = ?', [$tablaOid, $referencia]);
                if ($fila !== null) {
                    $leidas[$nombre] = (string) $fila->definicion;
                }
            }

            return $leidas;
        });

        $restos = DB::select('SELECT conname FROM pg_constraint WHERE conrelid = ? AND conname IN (?, ?, ?)', [$tablaOid, ...array_values($nombres)]);
        if ($restos !== []) {
            throw new RuntimeException('Inventario abortado: las restricciones de referencia no se revirtieron en '.self::TABLA.'.');
        }

        return $definiciones;
    }

    /**
     * Ejecuta $trabajo una sola vez y deshace SIEMPRE sus efectos, sin tocar lo exterior.
     *
     * No usa DB::transaction(): anidada, ante un error de concurrencia (40001, «deadlock detected»…) Laravel
     * decrementa su contador SIN ejecutar ROLLBACK TO SAVEPOINT y lanza una DeadlockException nueva, lo que deja
     * la subtransacción abortada y oculta la clase original. Aquí el punto de retorno se gestiona con SQL
     * explícito y el contador de Laravel no cambia:
     *  - dentro de una transacción: SAVEPOINT propio → trabajo → ROLLBACK TO SAVEPOINT + RELEASE;
     *  - en el nivel 0: transacción propia que siempre se revierte.
     * Un error del trabajo se relanza como el MISMO objeto (clase, SQLSTATE y diagnóstico intactos) tras deshacer
     * el punto. Nunca se reintenta el trabajo.
     *
     * Si el punto no puede deshacerse la recuperación es fail-closed y escalonada (ver deshacerPuntoDeRetorno()):
     * rollback completo comprobado → si falla o no se comprueba, la conexión se descarta (Connection::disconnect()
     * pone el contador de transacciones de Laravel a 0 y suelta el PDO, así que nada puede confirmarse desde ella;
     * el servidor revierte la transacción abierta al cerrar la sesión). Se lanza una RuntimeException que nombra
     * todos los errores (original, al deshacer y de recuperación) y solo afirma una reversión si la comprobó.
     *
     * @template T
     *
     * @param  callable(): T  $trabajo
     * @return T
     */
    public function enPuntoDeRetorno(callable $trabajo): mixed
    {
        $propia = DB::transactionLevel() === 0;
        if ($propia) {
            DB::beginTransaction();
        } else {
            DB::statement('SAVEPOINT '.self::PUNTO_RETORNO);
        }

        try {
            $resultado = $trabajo();
        } catch (Throwable $original) {
            $this->deshacerPuntoDeRetorno($propia, $original);

            throw $original;
        }
        $this->deshacerPuntoDeRetorno($propia, null);

        return $resultado;
    }

    /**
     * Deshace el punto de retorno o, si no puede, deja la conexión sin posibilidad de confirmar. Siempre lanza en
     * el segundo caso; nunca reintenta ni afirma una reversión que no haya comprobado.
     *
     * Escalado (cada paso solo si falla el anterior):
     *  1. nivel 0: DB::rollBack(); anidado: ROLLBACK TO SAVEPOINT + RELEASE.
     *  2. (solo anidado) rollback completo DB::rollBack(0), válido solo si después el contador es 0 y el PDO no
     *     está en transacción.
     *  3. descartar la conexión: Laravel 12 (Connection::disconnect → setPdo(null)) pone el contador a 0 y suelta
     *     el PDO; además se olvidan las transacciones pendientes del gestor de afterCommit.
     *  4. si tampoco eso se logra, el mensaje declara el estado como INCIERTO.
     */
    private function deshacerPuntoDeRetorno(bool $propia, ?Throwable $original): void
    {
        try {
            if ($propia) {
                DB::rollBack();
            } else {
                DB::statement('ROLLBACK TO SAVEPOINT '.self::PUNTO_RETORNO);
                DB::statement('RELEASE SAVEPOINT '.self::PUNTO_RETORNO);
            }

            return;
        } catch (Throwable $alDeshacer) {
            // En el nivel 0 el rollback ya era el completo: no se repite y el fallo se etiqueta como tal.
            $fallos = [$propia ? 'Fallo del rollback completo' : 'Fallo al deshacer el punto de retorno' => $alDeshacer];
        }

        $estado = 'incierto';
        $revertida = false;
        if (! $propia) {
            try {
                DB::rollBack(0);
                if (DB::transactionLevel() === 0 && ! DB::getPdo()->inTransaction()) {
                    $revertida = true;
                    $estado = 'revertida';
                } else {
                    $fallos['Fallo del rollback completo'] = new RuntimeException('tras el rollback la conexión conserva una transacción activa');
                }
            } catch (Throwable $fallo) {
                $fallos['Fallo del rollback completo'] = $fallo;
            }
        }

        if (! $revertida) {
            try {
                DB::disconnect();
                if (DB::connection()->getRawPdo() === null && DB::transactionLevel() === 0) {
                    $estado = 'descartada';
                    $this->olvidarTransaccionesPendientes($fallos);
                } else {
                    $fallos['Fallo al descartar la conexión'] = new RuntimeException('la conexión conserva su PDO o su contador de transacciones');
                }
            } catch (Throwable $fallo) {
                $fallos['Fallo al descartar la conexión'] = $fallo;
            }
        }

        $consecuencia = match ($estado) {
            'revertida' => 'se abortó la transacción exterior: se revirtió por completo (comprobado: contador de transacciones 0 y PDO sin transacción activa).',
            'descartada' => 'la reversión no pudo comprobarse; conexión descartada (contador de transacciones 0 y sin PDO): no puede confirmarse nada desde ella y el servidor revierte la transacción abierta al cerrarse la sesión, algo que no se verifica desde aquí.',
            default => 'ESTADO TRANSACCIONAL INCIERTO: no se pudo revertir ni descartar la conexión. No la reutilice ni confirme nada.',
        };
        $detalle = '';
        if ($original !== null) {
            $detalle .= ' Error original: '.$this->describir($original).'.';
        }
        foreach ($fallos as $etiqueta => $fallo) {
            $detalle .= " $etiqueta: ".$this->describir($fallo).'.';
        }

        throw new RuntimeException('Inventario de '.self::TABLA.': no se pudo deshacer el punto de retorno; '.$consecuencia.$detalle, 0, $original ?? $alDeshacer);
    }

    /**
     * Tras descartar la conexión, el gestor de transacciones de Laravel no debe conservar callbacks afterCommit de
     * la transacción abortada (no se ejecutarían nunca, pero tampoco deben acumularse). Un fallo se registra.
     *
     * @param  array<string, Throwable>  $fallos
     */
    private function olvidarTransaccionesPendientes(array &$fallos): void
    {
        try {
            if (app()->bound('db.transactions')) {
                app('db.transactions')->rollback(DB::connection()->getName(), 0);
            }
        } catch (Throwable $fallo) {
            $fallos['Fallo al olvidar las transacciones pendientes'] = $fallo;
        }
    }

    private function describir(Throwable $e): string
    {
        return $e::class.' SQLSTATE '.$this->sqlstate($e).': '.$e->getMessage();
    }

    private function sqlstate(Throwable $e): string
    {
        $estado = $e instanceof PDOException ? ($e->errorInfo[0] ?? null) : null;

        return (string) ($estado ?? $e->getCode());
    }

    /**
     * Decide, a partir de una instantánea del catálogo, si el esquema PostgreSQL está completo. Función pura
     * (sin consultas) para poder probar la lógica sin PostgreSQL. Un valor ausente o NULL nunca cuenta como
     * comprobación satisfecha.
     *
     * @param  array<string, mixed>  $c
     * @return array{columna: bool, faltan: list<string>, presentes: list<string>}
     */
    public function evaluarCatalogoPostgres(array $c): array
    {
        if (($c['tabla_oid'] ?? null) === null) {
            throw new RuntimeException('Esquema incompleto: la tabla '.self::TABLA.' no existe en el search_path; no se ha modificado nada.');
        }

        $faltan = $presentes = [];

        $col = $c['columna'] ?? null;
        $columna = is_array($col);
        if (! $columna) {
            $faltan[] = 'columna audiencia';
        } else {
            if (! $this->verdadero($col['attnotnull'] ?? null)) {
                $faltan[] = 'audiencia NOT NULL';
            }
            if (($col['tipo'] ?? null) !== 'character varying(20)') {
                $faltan[] = 'audiencia character varying(20) (tipo '.(is_string($col['tipo'] ?? null) ? $col['tipo'] : 'desconocido').')';
            }
        }

        $checks = [];
        foreach (is_array($c['checks'] ?? null) ? $c['checks'] : [] as $fila) {
            if (is_array($fila) && is_string($fila['conname'] ?? null)) {
                $checks[$fila['conname']] = $fila;
            }
        }
        foreach (array_keys(self::CHECKS) as $nombre) {
            $check = $checks[$nombre] ?? null;
            if ($check === null) {
                $faltan[] = $nombre;

                continue;
            }
            $presentes[] = $nombre;
            if (($check['contype'] ?? null) !== 'c') {
                $faltan[] = "$nombre (no es CHECK)";
            }
            if (! $this->verdadero($check['convalidated'] ?? null)) {
                $faltan[] = "$nombre (no validada)";
            }
            if (! $this->verdadero($check['conenforced'] ?? null)) {
                $faltan[] = "$nombre (no aplicada)";
            }
            // La referencia se obtuvo como NOT VALID: se quita ese sufijo exacto, y solo de ella.
            $referencia = $c['referencias'][$nombre] ?? null;
            $referencia = is_string($referencia) ? (string) preg_replace('/ NOT VALID$/', '', $this->normalizar($referencia)) : null;
            if ($columna && ($referencia === null || $this->normalizar((string) ($check['definicion'] ?? '')) !== $referencia)) {
                $faltan[] = "$nombre (definición distinta)";
            }
        }

        $funcion = self::FUNCION_PG.'()';
        $funciones = array_values($c['funciones'] ?? []);
        $oidFuncion = null;
        if ($funciones === []) {
            $faltan[] = $funcion;
        } else {
            $presentes[] = $funcion;
            if (count($funciones) > 1) {
                $faltan[] = "$funcion (sobrecargas ambiguas)";
            } else {
                $f = $funciones[0];
                $firma = (int) ($f['pronargs'] ?? -1) === 0 && ($f['prokind'] ?? null) === 'f'
                    && ($f['retorno'] ?? null) === 'trigger' && ($f['lenguaje'] ?? null) === 'plpgsql';
                $cuerpo = trim((string) ($f['prosrc'] ?? '')) === trim(self::CUERPO_FUNCION_PG);
                if (! $firma) {
                    $faltan[] = "$funcion (firma distinta)";
                }
                if (! $cuerpo) {
                    $faltan[] = "$funcion (definición distinta)";
                }
                if ($firma && $cuerpo && isset($f['oid'])) {
                    $oidFuncion = (int) $f['oid'];
                }
            }
        }

        $trigger = 'trigger '.self::FUNCION_PG;
        $t = array_values($c['triggers'] ?? [])[0] ?? null;
        if ($t === null) {
            $faltan[] = $trigger;
        } else {
            $presentes[] = $trigger;
            if ($oidFuncion === null || (int) ($t['tgfoid'] ?? 0) !== $oidFuncion) {
                $faltan[] = "$trigger (función distinta)";
            }
            // ROW (1) | BEFORE (2) | INSERT (4) | UPDATE (16); sin DELETE, TRUNCATE ni INSTEAD OF.
            if ((int) ($t['tgtype'] ?? -1) !== self::TGTYPE_PG) {
                $faltan[] = "$trigger (eventos o modalidad distintos)";
            }
            // O: se dispara en sesiones normales; A: siempre. D (deshabilitado) y R (solo réplica) no valen.
            if (! in_array($t['tgenabled'] ?? null, ['O', 'A'], true)) {
                $faltan[] = "$trigger (deshabilitado o solo réplica)";
            }
            if (! $this->falso($t['con_when'] ?? null)) {
                $faltan[] = "$trigger (condición WHEN inesperada)";
            }
            if (! $this->falso($t['tgisinternal'] ?? null) || ! $this->falso($t['de_restriccion'] ?? null)) {
                $faltan[] = "$trigger (interno o de restricción)";
            }
            if ((int) ($t['tgnargs'] ?? -1) !== 0 || (int) ($t['columnas'] ?? -1) !== 0) {
                $faltan[] = "$trigger (argumentos o columnas inesperados)";
            }
        }

        return ['columna' => $columna, 'faltan' => $faltan, 'presentes' => $presentes];
    }

    /** PDO puede devolver booleanos de PostgreSQL como bool o como 't'/'f'; NULL o ausente no es verdadero. */
    private function verdadero(mixed $valor): bool
    {
        return $valor === true || $valor === 't' || $valor === 1 || $valor === '1' || $valor === 'true';
    }

    private function falso(mixed $valor): bool
    {
        return $valor === false || $valor === 'f' || $valor === 0 || $valor === '0' || $valor === 'false';
    }

    private function restriccionesPostgres(): void
    {
        DB::statement('ALTER TABLE '.self::TABLA.' ALTER COLUMN audiencia SET NOT NULL');
        foreach (self::CHECKS as $nombre => $condicion) {
            DB::statement('ALTER TABLE '.self::TABLA." ADD CONSTRAINT $nombre CHECK ($condicion)");
        }

        // CREATE (no OR REPLACE): nunca sustituye una función ajena con el mismo nombre.
        DB::unprepared('CREATE FUNCTION '.self::FUNCION_PG.'() RETURNS trigger AS $fn$'.self::CUERPO_FUNCION_PG.'$fn$ LANGUAGE plpgsql');
        DB::unprepared('CREATE TRIGGER '.self::FUNCION_PG.' BEFORE INSERT OR UPDATE ON '
            .self::TABLA.' FOR EACH ROW EXECUTE FUNCTION '.self::FUNCION_PG.'()');
    }

    /**
     * Definición exacta de cada trigger SQLite (se usa para crearlos y para verificar un esquema existente).
     *
     * @return array<string, string>
     */
    private function triggersSqlite(): array
    {
        $tabla = self::TABLA;
        $triggers = [];
        foreach (self::CHECKS as $nombre => $condicion) {
            $nueva = str_replace(['audiencia', 'user_id'], ['NEW.audiencia', 'NEW.user_id'], $condicion);
            foreach (['INSERT', 'UPDATE'] as $operacion) {
                $triggers["{$nombre}_{$operacion}"] = "CREATE TRIGGER {$nombre}_{$operacion} BEFORE $operacion ON $tabla WHEN NOT ($nueva) BEGIN SELECT RAISE(ABORT, '$nombre'); END";
            }
        }

        $triggers['notificaciones_internas_personal_con_destinatario'] = "CREATE TRIGGER notificaciones_internas_personal_con_destinatario BEFORE INSERT ON $tabla "
            ."WHEN NEW.audiencia = 'personal' AND NEW.user_id IS NULL "
            ."BEGIN SELECT RAISE(ABORT, 'notificaciones_internas_personal_con_destinatario'); END";
        $triggers['notificaciones_internas_audiencia_inmutable'] = "CREATE TRIGGER notificaciones_internas_audiencia_inmutable BEFORE UPDATE ON $tabla "
            .'WHEN NEW.audiencia IS NOT OLD.audiencia OR (NEW.user_id IS NOT OLD.user_id AND NEW.user_id IS NOT NULL) '
            ."BEGIN SELECT RAISE(ABORT, 'notificaciones_internas_audiencia_inmutable'); END";

        return $triggers;
    }

    private function normalizar(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }
};
