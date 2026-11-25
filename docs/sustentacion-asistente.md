# Sustentación del asistente virtual

Guion de demostración, preguntas probables del jurado y lista de comprobación. Las respuestas de ejemplo dependen de las fichas publicadas ese día: ensaya con las definitivas.

## 1. En una frase

«Horus es un asistente informativo que responde solo con información publicada y verificada por la empresa; si no tiene un dato, lo dice y deriva a un asesor».

## 2. Cómo viaja una consulta

1. El visitante escribe en el chat de la web (Next.js).
2. El servidor de la web la envía a Laravel con una credencial que nunca llega al navegador.
3. Laravel aplica primero el **filtro de privacidad**. Si detecta datos personales, responde ahí mismo y la consulta no llega al motor.
4. **Horus AI** (Python, FastAPI) clasifica la consulta con reglas y busca en el contenido publicado.
5. Laravel **valida la respuesta**: los datos deben coincidir con textos oficiales o con fichas autorizadas. Si no, la descarta y muestra un mensaje de contingencia con los canales de contacto.
6. La web la presenta con tarjetas, botones y WhatsApp.

Los cursos llegan al motor como un catálogo **firmado** que Laravel genera con las fichas autorizadas y vigentes. Se renueva cada 2 minutos.

## 3. Demostración (unos 7 minutos)

Sustituye `[curso A]` y `[curso B]` por dos cursos publicados. Empieza con una conversación nueva y deja unos segundos entre preguntas (el límite es de 20 consultas por minuto y por visitante).

| Paso | Escribe o haz | Qué señalar |
|---|---|---|
| 1 | «Hola» | Se presenta como asistente informativo |
| 2 | «¿Qué cursos tienen?» | Solo cursos con ficha autorizada |
| 3 | «Compárame los cursos disponibles» | Lo común una vez y las diferencias en una tarjeta por curso |
| 4 | «¿Cuál es el horario del curso [curso A]?» y luego «¿y cuánto dura?» | Recuerda de qué curso se habla sin guardar lo escrito |
| 5 | «¿Cuánto cuesta?» | No inventa precios: deriva a un asesor |
| 6 | «¿Cómo puedo matricularme?» y el botón de WhatsApp | Pasos verificados y mensaje ya escrito, que la persona puede cambiar |
| 7 | «¿Tienen el curso de cocina?» (o un tema que no ofrezcan) | Reconoce que no lo tiene y ofrece ver los disponibles |
| 8 | Una consulta con un dato personal **inventado**, por ejemplo «Mi DNI es 00000000» | El filtro la detiene antes de llegar al motor |
| 9 | «¿Eres una IA?» | Dice que es un programa automático, no una persona |
| 10 | Vota 👍, escribe «Gracias, hasta luego» | La opinión llegará al panel |
| 11 | Panel → Asistente virtual → **Resumen** | Conversaciones, etapas, votos y temas |
| 12 | Panel → **Conversaciones**, filtra por una etapa | Se ve el tema y la respuesta, **no** la pregunta |

No uses datos personales reales en ningún paso. Comprueba el paso 8 en el ensayo.

## 4. Preguntas probables

**¿Es inteligencia artificial? ¿Usa ChatGPT?**
No usa ChatGPT ni redacta las respuestas con un modelo generativo. Combina reglas de clasificación y búsqueda sobre el contenido publicado, con un modelo local opcional de similitud para buscar. Antes de decir si ese modelo está activo, compruébalo (sección 6).

**¿Por qué no un modelo generativo?**
Porque aquí un dato falso (precio, fecha, vacante) perjudica a una persona y a la empresa. Se priorizó que la respuesta sea repetible y verificable. El coste es que entiende menos formas de preguntar y suena menos natural.

**¿Cómo evitan que invente?**
Hay dos controles: el motor solo compone respuestas con contenido publicado y frases fijas, y Laravel comprueba la respuesta antes de mostrarla.

**¿Qué pasa si el motor falla?**
El chat muestra un mensaje de contingencia con teléfono y correo. No hay un segundo motor que adivine.

**¿Qué datos guardan?**
No se guarda lo que escribe el visitante. Por cada respuesta se registra: un identificador de sesión transformado, el texto de la respuesta, el tema, el origen (web o probador), si fue contingencia, el voto y si se pulsó WhatsApp. El contexto de la conversación son claves sin texto del visitante, firmadas, que se conservan 10 minutos.

**¿El filtro de privacidad es infalible?**
No. Funciona con reglas: puede dejar pasar un dato escrito de forma inesperada o detener una consulta legítima (por ejemplo, una que mencione contraseñas). Está probado con casos sintéticos y falta una evaluación independiente.

**¿Cómo midieron la calidad?**
Con una evaluación automática en modo de búsqueda por palabras. Al 25/11/2026:

| Medida | Resultado | Qué significa |
|---|---|---|
| Consultas sueltas | 154 | Casos preparados con su resultado esperado |
| Comportamiento correcto | 97,4 % | Respondió, aclaró, se abstuvo o derivó como se esperaba |
| Intención correcta | 98,1 % | Clasificó bien la consulta |
| Primer resultado de búsqueda esperado | 67,5 % | En las consultas con una fuente concreta señalada |
| Primera recomendación pertinente | 100 % | En las consultas de recomendación |
| Respuestas sin sustento | 0 | Dentro de este conjunto |
| Conversaciones de varios turnos | 12 de 12 | 28 turnos, todos correctos |

Di siempre el límite: el conjunto lo preparó el propio proyecto junto con las reglas, así que es una **estimación optimista** y no equivale a una prueba con usuarios reales. «Cero» vale para esos casos, no para cualquier consulta.

**¿Cómo lo probaron además?**
Con pruebas automáticas de Laravel, del motor en Python y de la web, incluidas pruebas de navegador en computadora y celular, y con pruebas manuales en producción. La concurrencia no se mide con la base de pruebas habitual.

**¿Qué mide «¿Hasta dónde llegan?»?**
Cuántas conversaciones mostraron cada señal. No es un embudo estricto: alguien puede abrir WhatsApp desde un reclamo sin haber pedido matrícula. «Abrió WhatsApp» es un clic; no confirma que enviara el mensaje. Las demás etapas se estiman a partir del tema y de frases de las respuestas. El historial incluye pruebas hechas durante el desarrollo.

**¿Qué no hace?**
No matricula ni cobra, no da precios ni confirma vacantes, no consulta datos de estudiantes y no atiende en otros idiomas.

**¿Qué mejorarían?**
Una evaluación con preguntas de usuarios reales y una revisión independiente del filtro de privacidad. También ampliar las formas de preguntar que entiende: por ejemplo, «¿tienen contabilidad?», sin la palabra «curso», hoy pide una aclaración.

## 5. Días antes: cursos definitivos

1. Crea cada curso, déjalo **activo** y **autoriza su ficha pública**: crear el curso no lo publica.
2. En cada ficha revisa:
   - título, resumen con punto final y ortografía;
   - modalidad, horario, duración y certificación escritos con el mismo formato en todos;
   - fecha de inicio **posterior a la sustentación**, o vacía. Desde el día de inicio deja de mostrarse. Usa solo fechas confirmadas;
   - vigencia que incluya el día de la sustentación;
   - imagen;
   - sin teléfonos, correos ni datos personales en los textos.
3. Retira las fichas de prueba con **Retirar**; no hace falta borrar cursos.
4. Unos minutos después, pregunta en el chat por cada curso nuevo y compara los datos con su ficha.
5. Ensaya la demostración completa en computadora y celular.

## 6. El mismo día

- [ ] En Railway, `horus-ai` sin **Serverless** (Settings → Deploy), para que no se duerma.
- [ ] Gemini apagado: en las variables de `horus-ai` no debe existir `GEMINI_API_KEY`. No muestres esa pantalla.
- [ ] Estado del motor: `railway ssh --service horus-api "php artisan horus:advisor-operacion status --probe"`. Revisa `catalog`, `version_matches` y `semantic` (este último indica si el modelo de similitud está cargado).
- [ ] Abre la web y pregunta «¿Qué cursos tienen?» una media hora antes y otra vez justo antes.
- [ ] Inicia sesión en el panel y deja abierta la pestaña del asistente.
- [ ] Empieza el chat con una conversación nueva.
- [ ] No despliegues cambios ese día.
- [ ] Ten capturas de la demostración por si falla internet.
- [ ] No proyectes variables, credenciales ni datos de personas.
