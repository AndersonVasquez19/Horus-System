# Migraciones suspendidas

Laravel solo ejecuta las migraciones de `database/migrations/`. Estas tres se **apartaron el 10/11/2026 por decisión del propietario** y no están aplicadas en `horus_group`:

| Archivo | Qué haría | Función que depende de ella |
| --- | --- | --- |
| `2026_10_28_000022_create_registros_pendientes_table.php` | Crea `registros_pendientes` | Verificación de correo del registro (apagada: `config/registro-verificacion.php`) |
| `2026_11_09_000035_create_solicitudes_matricula_table.php` | Crea `solicitudes_matricula` | Formulario público `/solicitar-matricula` y panel `/admin/solicitudes-matricula` |
| `2026_11_09_000036_add_solicitudes_matricula_permissions.php` | Crea y concede a `admin` los permisos `solicitudes-matricula.ver\|gestionar` (su `down()` no hace nada) | Panel de solicitudes |

Mientras estén aquí, el código de esas funciones permanece **inactivo**: la verificación está apagada, el formulario público responde 503, el panel responde 403 y el enlace del menú no aparece.

**Pruebas.** Usan `Tests\Support\InstalaMigracionesSuspendidas`, que ejecuta estos archivos solo en SQLite `:memory:`.

**Reactivar una.** Requiere autorización expresa del propietario y, para 000036, una copia reciente:
1. `git mv database/migraciones-suspendidas/<archivo> database/migrations/<archivo>`.
2. Aplicarla con `php artisan migrate --path=database/migrations/<archivo>` y, tras la 000036, `php artisan permission:cache-reset`.
3. Quitar el uso del trait en la prueba correspondiente y actualizar la documentación.

**Entornos donde ya se aplicó alguna.** Su fila quedaría en `migrations` sin archivo; `migrate` lo tolera, pero un `migrate:rollback` de ese lote fallaría.
