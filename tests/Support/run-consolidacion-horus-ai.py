"""Integración nueva: exportador Laravel real, cola database en memoria y MiniLM offline."""

import json
import os
import secrets
import socket
import subprocess
import tempfile
import time
from pathlib import Path
from urllib.request import ProxyHandler, build_opener

root = Path(__file__).resolve().parents[2]
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
evidence = Path(tempfile.mkdtemp(prefix="horus-consolidacion-real-"))
token = secrets.token_hex(32)
signing = secrets.token_hex(32)
base = os.environ.copy()
for name in ("HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY", "http_proxy", "https_proxy", "all_proxy"):
    base.pop(name, None)
base["NO_PROXY"] = "127.0.0.1,localhost"
opener = build_opener(ProxyHandler({}))
base.update(
    {
        "HORUS_AI_SERVICE_TOKEN": token,
        "HORUS_AI_CATALOG_SIGNING_KEY": signing,
        "HORUS_AI_PORT": str(port),
        "HORUS_AI_CATALOG_PATH": str(evidence / "catalog.json"),
        "HORUS_AI_SEMANTIC_ENABLED": "true",
        "HORUS_OPS_ENABLED": "true",
        "HF_HUB_OFFLINE": "1",
        "TRANSFORMERS_OFFLINE": "1",
    }
)
python_env = {k: v for k, v in base.items() if not k.startswith(("DB_", "PG"))}
with (evidence / "fastapi.log").open("w", encoding="utf-8") as log:
    process = subprocess.Popen(
        [
            str(root / "chatbot-python/.venv/Scripts/python.exe"),
            "-m",
            "app.operacion",
            "run",
            "--execute",
        ],
        cwd=root / "chatbot-python",
        env=python_env,
        stdout=log,
        stderr=log,
        creationflags=subprocess.CREATE_NO_WINDOW,
    )
    try:
        last_state = "sin respuesta"
        for _ in range(240):
            if process.poll() is not None:
                raise RuntimeError(f"FastAPI no arrancó; revisar {evidence}")
            try:
                with opener.open(f"http://127.0.0.1:{port}/ready", timeout=1) as response:
                    ready = json.load(response)
                last_state = json.dumps(ready)
                if ready.get("semantic") == "loaded":
                    break
            except OSError as exc:
                last_state = type(exc).__name__
            time.sleep(0.25)
        else:
            raise RuntimeError(f"MiniLM no disponible dentro del presupuesto existente: {last_state}")
        php_env = base | {
            "APP_ENV": "testing",
            "APP_DEBUG": "false",
            "APP_KEY": "base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=",
            "DB_CONNECTION": "sqlite",
            "DB_DATABASE": ":memory:",
            "DB_URL": "",
            "CACHE_STORE": "array",
            "SESSION_DRIVER": "array",
            "MAIL_MAILER": "array",
            "QUEUE_CONNECTION": "sync",
            "LOG_CHANNEL": "null",
            "MATRICULA_MAIL_ENABLED": "false",
            "HORUS_CONSOLIDACION_FIXTURE": "1",
            "HORUS_ADVISOR_URL": f"http://127.0.0.1:{port}",
            "HORUS_ADVISOR_TOKEN": token,
            "HORUS_ADVISOR_SIGNING_KEY": signing,
            "VIEW_COMPILED_PATH": str(evidence),
        }
        result = subprocess.run(
            ["php", "tests/Support/consolidacion-horus-ai.php"],
            check=False,
            cwd=root,
            env=php_env,
            capture_output=True,
            text=True,
            encoding="utf-8",
            timeout=180,
            creationflags=subprocess.CREATE_NO_WINDOW,
        )
        (evidence / "integracion.txt").write_text(
            result.stdout + result.stderr, encoding="utf-8"
        )
        if result.returncode or result.stdout.splitlines()[-1:] != ["CONSOLIDACION_OK"]:
            raise RuntimeError(
                f"Integración fallida, exit={result.returncode}; evidencia {evidence}"
            )
        print(result.stdout, end="")
    finally:
        process.terminate()
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
        print(f"FastAPI temporal detenido. Evidencia: {evidence}")
