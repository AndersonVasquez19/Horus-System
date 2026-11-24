<?php

/*
 * Respaldo LOCAL de la base de datos (`horus:respaldo`, plan de mejora del panel, Fase 1).
 *
 * Los volcados (`pg_dump`, formato personalizado) se guardan en un disco PRIVADO del servidor, nunca en `public/`. Solo
 * quien tenga el permiso `respaldo.exportar` (por defecto, solo el rol admin) puede listarlos y descargarlos desde el panel.
 * Todo viene apagado por defecto: sin RESPALDO_PROGRAMADO_ENABLED el scheduler no ejecuta nada (el comando manual sigue
 * disponible). Runbook y restauración: docs/runbook-respaldo-local.md.
 */
return [
    // Programa el respaldo diario en el scheduler. Apagado por defecto.
    'programado' => (bool) env('RESPALDO_PROGRAMADO_ENABLED', false),

    // Hora local de Lima (HH:MM) del respaldo programado.
    'hora' => (string) env('RESPALDO_HORA', '02:00'),

    // Disco de Laravel (config/filesystems.php) y carpeta dentro de él. Debe ser un disco privado.
    'disco' => (string) env('RESPALDO_DISCO', 'local'),
    'carpeta' => 'respaldos',

    // Retención: se borran las copias más antiguas que estos días, pero siempre se conservan las `minimo` más recientes.
    'retencion_dias' => max(1, (int) env('RESPALDO_RETENCION_DIAS', 14)),
    'minimo' => max(1, (int) env('RESPALDO_MINIMO', 3)),

    // Conexión de config/database.php que se vuelca. Vacío = la conexión por defecto. Debe ser PostgreSQL.
    'conexion' => env('RESPALDO_CONEXION'),

    // Ejecutable de pg_dump (ruta completa si no está en el PATH) y tiempo máximo en segundos.
    'pg_dump' => (string) env('RESPALDO_PG_DUMP', 'pg_dump'),
    'timeout' => max(30, (int) env('RESPALDO_TIMEOUT', 900)),
];
