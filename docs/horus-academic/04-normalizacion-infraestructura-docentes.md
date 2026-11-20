> **RETIRADO el 19/11/2026.** Los módulos Docentes e Infraestructura se eliminaron del panel y de la base de datos (migraciones `000084` y `000085`). Este documento queda como historial; ver [`../retiro-docentes-infraestructura.md`](../retiro-docentes-infraestructura.md).

# 04 · Plan de normalización de docentes, sedes, aulas y plataformas virtuales

> **Fase 1 · diseño.** Parte de lo que el **código** del repositorio demuestra. **No se consultó ninguna base de datos**
> (ni `horus_group` ni la de pruebas): los volúmenes y valores reales están por medir con las consultas de solo lectura de
> §3, que requieren autorización explícita antes de ejecutarse.

## 1. Situación actual (verificada en el código)

| Dato | Dónde vive hoy | Forma | Problema |
| --- | --- | --- | --- |
| Docente | `convocatoria_horarios.docente` | `varchar(120)` libre, por **bloque de horario** | Sin identidad, sin contacto, duplicados por escritura («J. Pérez» ≠ «Juan Pérez»); un docente por bloque, no por edición. |
| Lugar | `convocatoria_horarios.lugar` | `varchar(120)` libre, por bloque | Sin sede ni aula, sin capacidad; imposible detectar cruces de aula. |
| Horario del curso | `cursos.horario` (`varchar(100)`) y `curso_publicaciones.horario` (`varchar(200)`) | Texto libre | Es información comercial/editorial del curso, **no** de la edición. |
| Modalidad | `cursos.modalidad`, `convocatorias.modalidad`, `curso_publicaciones.modalidad` | `presencial\|semipresencial\|virtual\|hibrido` | Tres lugares; `hibrido`/`semipresencial` no existen en el alcance de Horus Academic. |
| Plataforma/enlace virtual | — | No existe | No hay dónde guardar Meet/Zoom ni su enlace privado. |

Consumidores verificados con búsqueda de referencias (16/11/2026) de los literales `docente`/`lugar` en `apps/api/app`,
`apps/web/src` y `apps/ai/app`: `Models\ConvocatoriaHorario`, `Services\GestionConvocatorias` (copia ambos campos al guardar
horarios), `Api\Panel\Academico\ConvocatoriaController` y `apps/web/src/components/academico/acciones-convocatoria.tsx`.
(`Support\FiltroPrivacidadChat` y `Privacidad/lexico-publico.php` solo contienen la palabra como léxico del filtro de
privacidad del chat.) **No** lo leen el catálogo público, `apps/ai`, reportes ni el acta. La búsqueda debe repetirse al
iniciar la Fase 3 por si el código cambió.

## 2. Estrategia: expandir → poblar → migrar lecturas → contraer

Nunca se borra ni se renombra una columna heredada durante Horus Academic.

1. **Expandir (Fase 3, migraciones aditivas):** crear `docentes`, `sedes`, `aulas`, `plataformas_virtuales`,
   `convocatoria_docentes` y las columnas nulas `convocatorias.sede_id/aula_id/plataforma_virtual_id`. Los textos heredados
   siguen intactos y legibles.
2. **Poblar (Fase 3, tarea de datos separada, con autorización):** comando artisan idempotente
   `horus:normalizar-infraestructura` con `--simular` (por defecto, no escribe) y `--aplicar`:
   - *Docentes:* extrae los `docente` distintos, los normaliza (recorte, espacios, mayúsculas/acentos solo para comparar),
     propone coincidencias difusas y genera un **informe CSV de revisión humana**. No inventa DNI ni email: crea el docente
     con `documento` provisional marcado `pendiente_documento` solo si se aprueba (**decisión pendiente**: ¿se permite
     `documento` NULL temporal o se exige completarlo antes de aplicar?).
   - *Sedes/aulas:* clasifica cada `lugar` distinto en «presencial → sede/aula» o «virtual → plataforma» mediante una
     **tabla de mapeo revisada por una persona** (`lugar` → `sede`/`aula`/`plataforma`). Los `lugar` sin mapeo quedan
     sin tocar y se reportan.
   - Nunca modifica `convocatoria_horarios`; solo rellena las FK nuevas de `convocatorias` y `convocatoria_docentes`.
3. **Migrar lecturas (Fase 3–4):** API y Next leen las relaciones; los textos heredados se muestran solo como «dato
   original» cuando la relación está vacía.
4. **Contraer (fuera de Horus Academic):** retirar los textos heredados solo con una decisión explícita posterior y tras
   comprobar cero lecturas. No forma parte de este plan.

## 3. Inventario previo (consultas de **solo lectura**; requieren autorización)

```sql
-- Volumen y calidad de docentes
SELECT docente, COUNT(*) AS bloques, COUNT(DISTINCT convocatoria_id) AS ediciones
FROM convocatoria_horarios WHERE docente IS NOT NULL AND docente <> '' GROUP BY docente ORDER BY bloques DESC;

-- Lugares distintos y su uso por modalidad
SELECT h.lugar, c.modalidad, COUNT(*) AS bloques
FROM convocatoria_horarios h JOIN convocatorias c ON c.id = h.convocatoria_id
GROUP BY h.lugar, c.modalidad ORDER BY bloques DESC;

-- Modalidades realmente en uso
SELECT modalidad, estado, COUNT(*) FROM convocatorias WHERE deleted_at IS NULL GROUP BY 1, 2;
SELECT modalidad, COUNT(*) FROM cursos WHERE deleted_at IS NULL GROUP BY 1;

-- Matrículas sin edición (heredadas)
SELECT COUNT(*) FILTER (WHERE convocatoria_id IS NULL) AS sin_edicion, COUNT(*) AS total FROM matriculas WHERE deleted_at IS NULL;
```

Se ejecutan **solo** contra una copia restaurada o contra la base aislada (`127.0.0.1:55433`) con autorización
específica; el resultado se guarda fuera del repositorio (contiene nombres de personas).

## 4. Reglas de modalidad (a imponer desde la Fase 3)

| Modalidad de la edición | Obligatorio | Prohibido |
| --- | --- | --- |
| `presencial` | `sede_id` **y** `aula_id` (el aula pertenece a la sede); `cupos_maximos ≤ aula.capacidad` | `plataforma_virtual_id` |
| `virtual` | `plataforma_virtual_id`; cada sesión con su `enlace_virtual` cifrado antes de pasar a `realizada` | `sede_id`, `aula_id` |
| `semipresencial`, `hibrido` (heredadas) | Nada nuevo hasta decidir (ver README §Pendientes) | Crear ediciones nuevas con estos valores |

Aplicación: servicio (mensajes en español) + `CHECK … NOT VALID` en PostgreSQL + disparador en SQLite (ver
[02](02-diccionario-de-datos.md) §A). `cursos.modalidad` y `curso_publicaciones.modalidad` **no cambian**: describen la
oferta comercial; la modalidad operativa es la de cada edición.

## 5. Verificación de cierre de la normalización

- 0 ediciones activas (`abierta`/`en_curso`) incumpliendo el CHECK de modalidad (consulta de control).
- Todo docente referenciado tiene `documento` válido o está en la lista de pendientes aprobada.
- Ninguna sesión programada cruza aula ni docente (restricción de exclusión o consulta de control).
- Informe de «`lugar` sin mapear» vacío o aceptado por el propietario.
- Pruebas de regresión: reportes, acta Excel y catálogo público devuelven los mismos datos que antes.

## 6. Riesgos específicos

| Riesgo | Mitigación |
| --- | --- |
| Fusionar mal a dos docentes distintos | Nunca automático: informe CSV de revisión humana y mapeo explícito. |
| Perder el dato original | Columnas heredadas intactas; el comando solo añade. |
| `btree_gist` no disponible en el entorno | Verificar `CREATE EXTENSION` (de confianza desde PG 13) en el clúster aislado antes de depender de ella; alternativa: validación en servicio bajo bloqueo + consulta de control. |
| Enlaces virtuales filtrados | Cifrado en reposo, permiso aparte, exclusión de API pública, catálogo del asistente, auditoría y exportaciones; prueba que recorre las respuestas públicas buscando el patrón del enlace. |
