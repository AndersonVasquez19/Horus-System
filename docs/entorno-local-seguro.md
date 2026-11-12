# Entorno local seguro: sin llamadas externas involuntarias

Fecha: 11/11/2026. Rama `monorepo/base`. **Sin servicios iniciados, sin cambios en `.env` ni en `apps/ai/.env`.**

## 1. Problema

El `.env` local del propietario tiene Horus AI habilitado desde la activación autorizada del 05/11/2026 y varias credenciales externas definidas (Gemini en `apps/ai/.env`; Resend y OpenAI en `.env`). Con el entorno normal:

- El selector de Gemini queda activo en cuanto arranca el servicio Python (`apps/ai/app/services/gemini.py`, `from_settings`), porque la clave sale del archivo `.env` de Python.
- `scripts/horus-local.php run` solo anulaba `DB_`, `PG`, `RESEND_`, `OPENAI_` y `HORUS_ADVISOR_` en el entorno de Python: `GEMINI_API_KEY` pasaba tal cual.
- `phpunit.xml` forzaba apagadas las banderas de Horus y de reclamaciones, pero no `MATRICULA_MAIL_ENABLED`, Resend ni Gemini.

## 2. Solución

Una variable de proceso presente, aunque vacía, prevalece sobre los archivos de entorno de Laravel y de Python. Se define un perfil defensivo por proceso, sin leer ni escribir esos archivos:

| Familia | Variables neutralizadas | ¿Se puede permitir? |
| --- | --- | --- |
| Siempre | `OPENAI_API_KEY` vacía | No (OpenAI está retirado) |
| `gemini` | `GEMINI_API_KEY` vacía | Sí, con `--permitir=gemini` |
| `correo` | `MAIL_MAILER=array`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` y `MAIL_PASSWORD` neutros, `RESEND_API_KEY` vacía, `MATRICULA_MAIL_ENABLED`, `RECLAMACIONES_CORREO_ENABLED` y `REGISTRO_VERIFICACION_ENABLED` en `false` | Sí, con `--permitir=correo` |

Permitir una familia solo retira sus variables del perfil: el valor efectivo pasa a ser el del entorno heredado. Una familia desconocida se rechaza.

La lista vive en un solo sitio: `scripts/Support/EntornoSeguro.php`.

## 3. Uso

```powershell
# Ver el perfil, sin valores heredados
php scripts/entorno-seguro.php mostrar

# Ejecutar cualquier comando con el perfil (pruebas, análisis estático, pytest)
php scripts/entorno-seguro.php ejecutar -- php artisan test --compact
php scripts/entorno-seguro.php ejecutar -- php vendor/bin/phpstan analyse --no-progress

# Python: usar la ruta absoluta del intérprete (en Windows una ruta relativa con «/» no se resuelve)
cd apps/ai
php ..\..\scripts\entorno-seguro.php ejecutar -- "$PWD\.venv\Scripts\python.exe" -m pytest tests\test_gemini.py -q

# Permitir una familia de forma expresa
php scripts/entorno-seguro.php ejecutar --permitir=gemini -- <comando>
```

`ejecutar` añade además un bloqueo de red: todos los proxies apuntan a un puerto sin servicio (`127.0.0.1:9`), de modo que cualquier cliente HTTP que respete proxies falla de inmediato. `--sin-bloqueo-de-red` lo desactiva.

## 4. Gestor local (`scripts/horus-local.php`)

Cambio de comportamiento: **por defecto, FastAPI, el worker, `status` y el servidor web lanzados por el gestor ya no pueden llamar a Google ni enviar correo.** Se habilitan con `--permitir=gemini,correo`. No se toca `updateLocalEnv`, la lógica de arranque ni el control del `.env`.

El bloqueo de red por proxy **no** se aplica al gestor, porque Laravel habla con FastAPI por loopback (`127.0.0.1:18081`).

## 5. Validación

Solo SQLite en memoria, procesos con valores ficticios y sin red:

| Comprobación | Resultado |
| --- | --- |
| `tests/Unit/EntornoSeguroTest.php` | 13 pruebas, 45 aserciones |
| Variable vacía en Windows | Llega vacía al proceso hijo (`GEMINI_API_KEY => ''` aunque el padre tuviera un valor) |
| Selector de Gemini, entorno normal | `selector_gemini_activo = True` (la clave sale de `apps/ai/.env`; solo se imprime el booleano) |
| Selector de Gemini, bajo `ejecutar` | `selector_gemini_activo = False` |
| `pytest tests/test_gemini.py` bajo `ejecutar` | 26 aprobadas |
| `horus-local.php` con `--permitir=openai` o un argumento desconocido | Sale con error antes de cualquier acción |
| PHPStan completo | Sin errores |
| Pint `--test` sobre los archivos propios | Aprobado |

## 6. Límites

- No impide por sí solo que alguien arranque el servicio Python directamente con el entorno normal: la barrera existe en el ejecutor y en el gestor, no en el servicio.
- El bloqueo de red depende de que el cliente respete proxies. En Python, el selector de Gemini usa `trust_env=False` y se protege solo por la clave vacía.
- `phpunit.xml` no cambia: sigue forzando apagadas las banderas de Horus y de reclamaciones. Ejecutar la suite con `entorno-seguro.php` añade la cobertura de correo, Resend y Gemini.
- No hay pruebas de los procesos del gestor completo, porque no se inician servicios en esta etapa.
