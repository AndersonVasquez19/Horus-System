"""Integración real Laravel -> FastAPI para la barrera de privacidad. NO SE EJECUTA AUTOMÁTICAMENTE.

Condiciones (todas verificadas antes de iniciar nada):
  * Opt-in explícito: HORUS_PRIVACIDAD_INTEGRACION=1 en el entorno del operador.
  * Solo loopback 127.0.0.1; el puerto 8001 debe estar LIBRE (si está ocupado, aborta sin tocar nada).
  * Token de servicio efímero generado en memoria; nunca se escribe en disco ni se imprime.
  * Laravel corre como script CLI de PHP (sin servidor web) con SQLite :memory: y .env.example; no se toca PostgreSQL ni .env.
  * Uvicorn en modo léxico (sin cargar el modelo semántico) con log de acceso en un archivo temporal que solo contiene
    método y ruta (no cuerpos, no mensajes). No hay llamadas externas ni datos reales.
  * Cierre controlado: terminate -> wait -> kill, y comprobación final de que 8001 queda libre.

Pasos y criterios de éxito:
  1. Pasada «bloqueados» (todo mensaje etiquetado B): el log de acceso de FastAPI debe quedar VACÍO (0 peticiones, ni
     siquiera /ready) y ChatLog no debe contener texto del visitante.
  2. Pasada «permitidos» (etiquetados P): las peticiones POST /api/v1/chat no pueden superar los mensajes que el filtro deja
     pasar (la deduplicación puede reducirlas); las respuestas con la aclaración de privacidad son falsos positivos y se informan.

Uso (PowerShell, desde la raíz del repositorio, con la autorización del propietario):
  $env:HORUS_PRIVACIDAD_INTEGRACION = "1"
  chatbot-python\\.venv\\Scripts\\python.exe tests\\Support\\run-privacidad-integracion.py <informe.json> <corpus.txt> [<corpus2.txt> ...]
El informe se escribe en la ruta indicada (fuera del repositorio). Los corpus son los del auditor (formato CATEGORIA|B/P|MENSAJE).
"""

from __future__ import annotations

import json
import os
import secrets
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parents[2]
PORT = 8001


def puerto_libre() -> bool:
    with socket.socket() as sonda:
        return sonda.connect_ex(("127.0.0.1", PORT)) != 0


def main() -> int:
    if os.environ.get("HORUS_PRIVACIDAD_INTEGRACION") != "1":
        print("Abortado: falta el opt-in HORUS_PRIVACIDAD_INTEGRACION=1.", file=sys.stderr)
        return 2
    if len(sys.argv) < 3:
        print("Uso: run-privacidad-integracion.py <informe.json> <corpus.txt> [...]", file=sys.stderr)
        return 2
    informe = Path(sys.argv[1]).resolve()
    corpus = [str(Path(c).resolve()) for c in sys.argv[2:]]
    if ROOT in informe.parents:
        print("El informe debe escribirse fuera del repositorio.", file=sys.stderr)
        return 2
    if not puerto_libre():
        print(f"Abortado: el puerto {PORT} ya está en uso; no se inicia ni se toca ningún servicio.", file=sys.stderr)
        return 3

    token = secrets.token_hex(32)  # solo en memoria
    env = os.environ.copy()
    env.update(
        {
            "APP_ENV": "testing", "APP_DEBUG": "false", "APP_KEY": "base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=",
            "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:", "DB_URL": "", "SESSION_DRIVER": "array", "CACHE_STORE": "array",
            "MAIL_MAILER": "array", "QUEUE_CONNECTION": "sync", "OPENAI_API_KEY": "", "MATRICULA_MAIL_ENABLED": "false",
            "HORUS_ADVISOR_ENABLED": "true", "HORUS_ADVISOR_URL": f"http://127.0.0.1:{PORT}", "HORUS_ADVISOR_TOKEN": token,
            "HORUS_AI_SERVICE_TOKEN": token, "HF_HUB_OFFLINE": "1", "TRANSFORMERS_OFFLINE": "1",
        }
    )
    logdir = Path(tempfile.mkdtemp(prefix="horus-privacidad-"))
    acceso = logdir / "acceso.log"
    comando = (
        "import logging,os,sys,uvicorn; from app.main import create_app; from app.core.config import Settings; "
        "h=logging.FileHandler(os.environ['HORUS_ACCESO_LOG'],encoding='utf-8'); h.setFormatter(logging.Formatter('%(message)s')); "
        "l=logging.getLogger('uvicorn.access'); l.setLevel(logging.INFO); l.addHandler(h); l.propagate=False; "
        "s=Settings(_env_file=None, service_token=os.environ['HORUS_AI_SERVICE_TOKEN'], semantic_enabled=False, catalog_path=None, catalog_signing_key=None); "
        f"uvicorn.run(create_app(s),host='127.0.0.1',port={PORT},access_log=True,log_level='warning')"
    )
    env["HORUS_ACCESO_LOG"] = str(acceso)
    proceso = None
    resultados: dict[str, object] = {}
    try:
        proceso = subprocess.Popen(
            [str(ROOT / "chatbot-python/.venv/Scripts/python.exe"), "-c", comando], cwd=ROOT / "chatbot-python", env=env,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        for _ in range(120):
            if proceso.poll() is not None:
                raise RuntimeError("FastAPI terminó antes de estar listo.")
            try:
                with urlopen(f"http://127.0.0.1:{PORT}/ready", timeout=1):
                    break
            except OSError:
                time.sleep(0.25)
        else:
            raise RuntimeError("FastAPI no respondió /ready.")
        base = acceso.read_text(encoding="utf-8").count("\n") if acceso.exists() else 0  # peticiones de la sonda /ready

        def pasada(modo: str) -> dict[str, object]:
            antes = acceso.read_text(encoding="utf-8").splitlines() if acceso.exists() else []
            salida = subprocess.run(
                ["php", "tests/Support/privacidad-integracion.php", modo, *corpus], cwd=ROOT, env=env, capture_output=True, text=True, timeout=600,
            )
            if salida.returncode != 0:
                raise RuntimeError(f"El cliente PHP falló en la pasada {modo} (código {salida.returncode}).")
            resumen = json.loads(salida.stdout.strip().splitlines()[-1])
            nuevas = (acceso.read_text(encoding="utf-8").splitlines() if acceso.exists() else [])[len(antes):]
            resumen["peticiones_fastapi"] = len(nuevas)
            resumen["peticiones_chat"] = sum(1 for linea in nuevas if "POST /api/v1/chat" in linea)
            return resumen

        resultados["bloqueados"] = pasada("bloqueados")
        resultados["permitidos"] = pasada("permitidos")
        b, p = resultados["bloqueados"], resultados["permitidos"]
        resultados["criterios"] = {
            "bloqueados_0_peticiones_a_python": b["peticiones_fastapi"] == 0,
            "bloqueados_sin_contenido_en_chatlog": b["chatlog_con_contenido"] == 0,
            "permitidos_chat_posts_no_superan_los_mensajes_que_el_filtro_deja_pasar": p["peticiones_chat"] <= p["filtro_dice_seguro"],
            "peticiones_previas_de_sonda": base,
        }
    finally:
        if proceso is not None:
            proceso.terminate()
            try:
                proceso.wait(timeout=10)
            except subprocess.TimeoutExpired:
                proceso.kill()
                proceso.wait()
        time.sleep(1.5)
        resultados["puerto_8001_libre_al_cerrar"] = puerto_libre()
        informe.write_text(json.dumps(resultados, ensure_ascii=False, indent=2), encoding="utf-8")
        print(json.dumps(resultados, ensure_ascii=False, indent=2))
    return 0 if resultados.get("puerto_8001_libre_al_cerrar") else 1


if __name__ == "__main__":
    raise SystemExit(main())
