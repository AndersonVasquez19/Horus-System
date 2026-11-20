# L2.2-B2 — acta adicional de reconciliación y cierre S3

Fecha local: 27/10/2026, America/Lima. Revalidación principal terminada a las 19:28:10 (2026-10-28T00:28:10.0528825Z).

**Resultado: S3 completada documentalmente.** Clasificación A: `initdb` había finalizado correctamente; el guion produjo un falso positivo posterior. Esta acta se añade bajo la autorización expresa de reconciliación y cierre S3 del propietario. No autoriza S4–S7, SQL, arranque, migraciones, restauraciones ni rollback. La validación funcional SQL de locale permanece pendiente.

## 1. Recursos y verificaciones repetidas

Todas las comprobaciones de infraestructura y datos de esta reconciliación fueron de solo lectura. Las únicas escrituras autorizadas son esta acta, la corrección del procedimiento y dos evidencias adicionales fuera del repositorio. No se alteraron ACL, configuración, servicios, clúster ni DPAPI.

| Comprobación | Resultado observado |
| --- | --- |
| Operador / sesión | `LAPTOP-ONK47RS6\PCT40T`, mismo operador acreditado por DPAPI; token no elevado |
| Binarios | `postgres`, `initdb` (solo `--version`), `pg_controldata` y `pg_checksums`: 18.6, rutas absolutas bajo `C:\Program Files\PostgreSQL\18\bin` |
| Raíz NTFS | `C:\pgdata`, identidad `0x000000000000000000080000000d0a50`; solo contiene el hijo previsto |
| Hijo NTFS | `C:\pgdata\horus_l2_test`, identidad `0x000000000000000000430000000ada1d`; coincide con DPAPI |
| ACL | 997 elementos comprobados, sin reanálisis ni principales ajenos al conjunto permitido; servicio con control total |
| Herencia / propietario | Raíz protegida con tres principales; hijo hereda y añade la cuenta virtual (cuatro principales); propietario permitido |
| Principales permitidos | SYSTEM, Administradores, operador; cuenta virtual adicional en el hijo y su árbol |
| `PG_VERSION` / estructura | `18`; archivos de configuración, `global/pg_control` y directorios esperados presentes; sin `postmaster.pid` |
| Identificador del clúster | `7701505916098505688`, coincide con DPAPI y `Get-ClusterId` |
| Control / catálogo / estado | `1800` / `202506291` / `shut down` |
| Checksums | Versión 1; `pg_checksums --check`: código 0, 950 archivos, 3010 bloques, 0 incorrectos |
| Autenticación generada | Seis reglas activas de `pg_hba.conf`, todas `scram-sha-256`; no se probó autenticación |
| Servicio | `postgresql-horus-l2-test`, comando registrado previsto, cuenta `NT SERVICE\postgresql-horus-l2-test`, Manual, Stopped, PID 0 |
| Puerto 55432 | Sin conexiones observadas, fuera de exclusiones TCP IPv4/IPv6; no constituye reserva del puerto |
| Instancia cotidiana | Comparación exacta con `%TEMP%\horus_l2_baseline_before.txt`: servicio, SDDL y escucha 5432 sin diferencias; Running |
| DPAPI | Descifrado CurrentUser y validación de operador/equipo/recursos correctos; etapa `initialized`; identificador coincidente; sin `identity.pending` |
| Procesos | Ningún `initdb.exe` activo; no se inició ningún servicio en esta reconciliación |

## 2. Evidencias originales preservadas

Ubicación: `%LOCALAPPDATA%\HorusL2Provisioning`. Se conservaron los archivos originales en su ubicación, sin sobrescribirlos ni reconstruirlos. Sus ACL se verificaron. Los hashes coinciden con el diagnóstico anterior y se comprobaron nuevamente tras la revalidación.

| Artefacto | Bytes | SHA-256 |
| --- | ---: | --- |
| `s3-interactive-20261027.ps1` | 16435 | `C94F8D92E8E106B05E6F59B6AAC23D4F185792785F4B83AD900415EC46782DA6` |
| `s3-result-20261027.json` | 119 | `D1F5228AAC6763471D399DC235F6690EE5A11FBBD11CB7037081D3A3486C7117` |
| `identity.dpapi` | 742 | `9967576ADD90B5CC05B61B803A8611B0A1F1B5909969DE9FD6FE53B4335D6599` |

El resultado original conserva `Success: false`, `Error: STOP: locale inesperado`, `Utc: 2026-10-28T00:16:42.9137020Z`. Su falsedad como diagnóstico del locale no se oculta cambiando el resultado original.

Marcas temporales conocidas, fecha local 27/10/2026 (UTC entre paréntesis):

- Guion original, última escritura: 19:14:06.1164329 (`2026-10-28T00:14:06.1164329Z`).
- Supervisor PID 16820, evento 400: 19:14:07.3060992 (`2026-10-28T00:14:07.3060992Z`). Evento reconsultado en el registro Windows PowerShell.
- Último checkpoint observado en el diagnóstico previo: 19:16:25. El hash actual de `pg_control` se registra abajo; no hay indicios de recuperación pendiente.
- DPAPI original, última escritura: 19:16:36.6037175 (`2026-10-28T00:16:36.6037175Z`).
- Resultado fallido, última escritura: 19:16:42.9417693 (`2026-10-28T00:16:42.9417693Z`).
- Supervisor PID 16820, evento 403: 19:16:54.4976215 (`2026-10-28T00:16:54.4976215Z`). Evento reconsultado; no demuestra quién o qué cerró la ventana.

**Artefactos no disponibles:** no existe en el almacén una captura original completa de stdout/stderr de `initdb`, transcripción de su consola, ni un archivo separado con su código de salida. No se reconstruyen. La contraseña no se conserva ni se solicita de nuevo. La evidencia de salida satisfactoria procede del flujo del guion original: línea 182 detiene si `$LASTEXITCODE` es distinto de cero; líneas 184–186 guardan y contrastan el identificador y la etapa; el error registrado procede de la línea 211, posterior a esas operaciones.

## 3. Causa y corrección del criterio de aceptación

El guion original buscaba líneas `lc_collate = 'Spanish_Spain.1252'` y `lc_ctype = 'Spanish_Spain.1252'` en `postgresql.conf`. Su ausencia activó `STOP: locale inesperado`. Esa comprobación era incorrecta: las propiedades por base se almacenan en `pg_database`.

El bloque S3 original del procedimiento no incluía dicha condición; se introdujo en el guion externo de supervisión. Por preservación, ese guion no se edita. Se corrige el procedimiento con una sección explícita que invalida ese criterio, establece la verificación física por catálogo y separa la validación SQL posterior. Tampoco se utiliza `postgres -C server_encoding` para acreditar la codificación real de una base.

El guion captura el error y finalmente espera Enter. Los eventos y el resultado explican el falso fallo de verificación, pero no prueban por sí solos el mecanismo exacto del cierre de la ventana. La conclusión de inicialización satisfactoria no depende de resolver esa incertidumbre.

## 4. Validación física del catálogo, sin SQL

Se leyó exclusivamente `C:\pgdata\horus_l2_test\global\1262` en memoria, mediante `[IO.File]::ReadAllBytes`, con el clúster apagado. El esquema se contrastó en el diagnóstico con `C:\Program Files\PostgreSQL\18\share\postgres.bki`: relación compartida `pg_database`, 18 atributos. No se leyó `pg_authid`.

El decodificador de revalidación preservado junto a esta acta aplica estas condiciones concretas:

1. Archivo de una página de 8192 bytes; límites de cabecera válidos, punteros de cuatro bytes y espacio especial esperado.
2. Interpretación little endian de los punteros; se aceptan únicamente entradas sin uso, tuplas normales y redirecciones HOT válidas a tuplas normales. Se comprobaron dos redirecciones.
3. Tuplas de 18 atributos, cabecera alineada a ocho bytes, `xmax=0` y campos obligatorios no nulos. El código no es un motor general de visibilidad MVCC.
4. Desde el inicio de datos de cada tupla: OID en offset 0, nombre en 4 (64 bytes), encoding en 72, proveedor en 76 y límite de conexiones en 80; textos desde offset 96. Textos varlena cortos o normales sin compresión, dentro de los límites; valores externos/comprimidos inesperados detienen la revisión.
5. Tres registros iniciales esperados; encoding 6 (UTF8), proveedor `c`, locale literal y campos complementarios nulos. Se contrasta además el SHA-256 completo del catálogo con el diagnóstico anterior.

| Base | OID | Encoding | Proveedor | `datcollate` | `datctype` | `datlocale` / reglas ICU / versión |
| --- | ---: | --- | --- | --- | --- | --- |
| `template1` | 1 | 6 / UTF8 | c / libc | Spanish_Spain.1252 | Spanish_Spain.1252 | NULL / NULL / NULL |
| `template0` | 4 | 6 / UTF8 | c / libc | Spanish_Spain.1252 | Spanish_Spain.1252 | NULL / NULL / NULL |
| `postgres` | 5 | 6 / UTF8 | c / libc | Spanish_Spain.1252 | Spanish_Spain.1252 | NULL / NULL / NULL |

SHA-256 de `global/1262`: `A72F45AF6C2333B4C365DE79B4C77C76316A32CA08539EFB0E4B6A198361974D`.

Este método es una comprobación física específica de este clúster recién inicializado, no una herramienta general de recuperación ni una certificación de integridad lógica completa. No se afirma validación SQL, conectividad, credenciales, funcionamiento del servicio, comportamiento de colaciones, LIKE/ILIKE ni aceptación V16. Todo ello permanece pendiente de fases posteriores autorizadas.

Referencias de formato y significado: [pg_database](https://www.postgresql.org/docs/18/catalog-pg-database.html), [cabecera de tuplas PostgreSQL 18](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/include/access/htup_details.h), [punteros de página](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/include/storage/itemid.h), [textos varlena](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/include/varatt.h), [identificadores de encoding](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/include/mb/pg_wchar.h), [pg_checksums](https://www.postgresql.org/docs/18/app-pgchecksums.html).

## 5. Evidencias adicionales y cierre

Se crearon con `FileMode.CreateNew`, fuera del repositorio y bajo la ACL existente del almacén, sin sobrescribir originales:

| Artefacto adicional | SHA-256 |
| --- | --- |
| `s3-reconciliation-readonly-20261027.ps1` | `BEB7CD021B0A90E365CF6F56EF25CB95A383C4F75922D51E3A612DC1B50C4BFE` |
| `s3-reconciliation-readonly-20261027.json` | `EF4D6693574C16D40A9D8A53E3F8EC61C0ED88FE5C70890FC7BEF23D3F3C834E` |

El `.ps1` conserva el comando de revalidación de solo lectura; no es el guion original de inicialización ni lo sustituye. Carga las funciones del procedimiento, pero no llama a `Save-Identity`. El JSON conserva los resultados nuevos y su momento de observación; no se presenta como salida original de `initdb`.

Huellas de configuración/control tomadas durante la reconciliación, para contraste al terminar las escrituras documentales:

| Archivo bajo el directorio de pruebas | SHA-256 |
| --- | --- |
| `postgresql.conf` | `8A3597219A639C415F04DB41B750183A2F8AB5070D917BDBDD6734CDF83FAAF6` |
| `postgresql.auto.conf` | `EF0843FCE52217A6815D36E76A88F618494C851EFC3EB9E1A794FE0D8DCCDD81` |
| `pg_hba.conf` | `0C8DC6E6E57399790417A6E13B3A8E1B5E27AA19708A2122148FBFE3BDCECD42` |
| `pg_ident.conf` | `2151E28F0BF5A8BB69109F91DBE61EBFFE48C6F9B71620F6A7B0B02B410E7DE5` |
| `global/pg_control` | `980341D2F923265CD91266499F2194739FF5A6D706AD152383C51F29193FDB66` |

La reconciliación de DPAPI es una confirmación de consistencia: ya estaba en `initialized` y conserva ese estado y su hash original. No se ejecutó `Save-Identity`, no se creó `identity.pending` y no se añadió una etapa nueva.

Con las revalidaciones satisfactorias queda formalmente cerrado el alcance documental de S3. Puede solicitarse autorización separada para S4. El servicio continúa detenido; no se configura ni se arranca como parte de este cierre. No se ejecutaron SQL, migraciones, restauraciones, rollback, commits ni push.
