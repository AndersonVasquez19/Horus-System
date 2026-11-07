# Publicación web de cursos por modalidad

6 de noviembre de 2026, America/Lima. El propietario autoriza aplicar la propuesta: creación desde el panel, revisión y publicación explícita, catálogo web habilitado y acceso desde las tres modalidades actuales de Educación → Cursos. Se conservan los cambios locales existentes y el diseño de la página; no se modifica la portada.

## Recorrido

1. En Panel → Cursos → Nuevo curso se elige Presencial, Virtual o Semipresencial. La creación y edición ahora aceptan `semipresencial`.
2. Después de guardar, el detalle ofrece **Publicación web** a usuarios con `cursos.publicar`. El listado conserva su acceso editorial anterior.
3. El editor exige título, resumen, modalidad pública, vigencia y confirmación expresa. Los campos administrativos no se copian automáticamente.
4. Al autorizar la ficha, el visitante puede verla en Educación → Cursos y en el filtro de la modalidad elegida (`?modalidad=presencial`, `virtual` o `semipresencial`).
5. Despublicar, vencer la vigencia o desactivar/eliminar el curso retira su ficha de la lectura pública. Se conserva la revalidación de autor y permiso de publicación.

El valor anterior `hibrido` continúa siendo válido en administración y publicación. No se reescriben registros ni se presume que una ficha híbrida sea semipresencial: conserva su etiqueta y el acceso general o por `?modalidad=hibrido`. Los nuevos cursos semipresenciales se registran explícitamente con esa modalidad.

## Alcance técnico

- `app/Http/Requests/StoreCursoRequest.php` y `UpdateCursoRequest.php`: modalidad semipresencial admitida en ambas operaciones.
- `resources/views/admin/cursos/_form.blade.php`: opción Semipresencial.
- `resources/views/admin/cursos/show.blade.php`: acceso a la publicación con su permiso propio.
- `config/catalogo-publico.php`: catálogo web habilitado por autorización del propietario. Se conservan las respuestas controladas ante esquema incompleto.
- Se reutilizan sin sobrescribir los cambios previos de `CursosPublicosController`, la página pública, sus tres botones de modalidad y los filtros.
- `CursosPorModalidadTest` cubre creación, revisión, publicación, lectura anónima por modalidad, retirada, edición, compatibilidad, confirmación y permisos. Las pruebas históricas de catálogo apagado lo simulan expresamente; los contratos de servicios/cotizaciones comprueban el nuevo predeterminado web.
- Las cinco fixtures de navegador verifican el nuevo predeterminado web activo y mantienen los requisitos de SQLite en memoria, loopback, opt-in y proveedores desactivados. La fixture del catálogo sigue simulando estados apagado/encendido por petición.

No se cambian `chatbot.public_catalog_enabled`, `.env`, claves, transporte de correo ni sincronización de IA. No se crean cursos reales ni se ejecutan migraciones o seeders contra PostgreSQL. El esquema versionado ya define `modalidad` como texto; este cambio no necesita una migración nueva. No se ha inspeccionado el esquema de la base real.

## Validación

Las pruebas funcionales y la fixture de navegador usan exclusivamente SQLite `:memory:`, datos ficticios y HTTP externo bloqueado. SQLite no acredita bloqueos/concurrencia de PostgreSQL, disponibilidad del entorno real ni despliegue. Los resultados ejecutados se registran al cerrar la tarea.

| Comprobación | Resultado |
| --- | --- |
| Bloque acotado: cursos, modalidades, editor, publicación, catálogo web, servicios y cotizaciones | 206 aprobadas, 2031 aserciones |
| Suite completa `php artisan test --compact` | 3022 aprobadas, 5 fallos, 1 riesgosa, 1 omitida; 15203 aserciones; 347,68 s |
| `npm test` | 40 aprobadas |
| `npm run build` | Aprobado |
| PHPStan, nivel 6 | Sin errores |
| Pint sobre los 14 archivos PHP de la tarea | Aprobado |
| Smoke Edge `catalogo-web-smoke.mjs`, exclusivo de `127.0.0.1:8772` con SQLite en memoria | 15 estados/anchuras y 9 filtros de modalidad aprobados; cero errores JS y peticiones externas |
| `git diff --check` | Sin errores; avisos LF/CRLF conocidos |

Se utilizó PHP 8.4.27 instalado en Laragon, mediante PATH exclusivo de cada comando de validación. No se modificó la configuración del equipo ni archivos de entorno.

El smoke probó los tres botones en 390, 768 y 1440 píxeles, comprobó que cada filtro entrega una única ficha ficticia de su modalidad y verificó ausencia de desbordamientos. Se revisaron las capturas de móvil, tableta y escritorio. El servidor y el navegador propios se cerraron al finalizar. Evidencia local ignorada: `storage/app/private/testing/catalogo-web/report.json` y capturas `modalidad-*`.

La comparación SHA-256 del estado previo conserva idénticos 85 de los 88 archivos con cambios anteriores. Los otros tres son README, CLAUDE y la prueba compartida del catálogo, ampliados dentro de este alcance. El controlador público, la página de cursos, sus estilos, la portada y el trabajo Python previo permanecen intactos.

La suite completa no queda aprobada. Los cinco fallos corresponden a `FiltroPrivacidadCorpusTest`, `HorusPrivacidadEstabilizacionTest` y `HorusPrivacidadBloqueoCompletoTest`: faltan `tests/Fixtures/privacidad/desarrollo.txt`, `errores-compartibles.txt` y `forense-compartible.json`. La prueba riesgosa es el bucle de casos críticos de `FiltroPrivacidadCorpusTest`, que queda sin aserciones por el corpus vacío. Son los mismos fallos de archivos ausentes registrados en la tarea anterior de retirada de Procesos; no se cambian esas pruebas ni se inventan datos para ocultarlos. Se conserva la omisión de la comprobación exclusiva de PostgreSQL.
