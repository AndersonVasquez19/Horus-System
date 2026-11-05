"""Real Laravel service -> FastAPI; loopback, ephemeral token, SQLite memory only."""

import json
import os
import secrets
import socket
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.request import urlopen

root = Path(__file__).resolve().parents[2]
port = 8001
with socket.socket() as probe:
    probe.bind(("127.0.0.1", port))
env = os.environ.copy()
token = secrets.token_hex(32)
env.update(
    {
        "APP_ENV": "testing",
        "APP_DEBUG": "false",
        "APP_KEY": "base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=",
        "DB_CONNECTION": "sqlite",
        "DB_DATABASE": ":memory:",
        "DB_URL": "",
        "SESSION_DRIVER": "array",
        "CACHE_STORE": "array",
        "MAIL_MAILER": "array",
        "QUEUE_CONNECTION": "sync",
        "LOG_CHANNEL": "null",
        "MATRICULA_MAIL_ENABLED": "false",
        "HORUS_OBSERVACIONES_FIXTURE": "1",
        "HORUS_ADVISOR_ENABLED": "true",
        "HORUS_ADVISOR_URL": f"http://127.0.0.1:{port}",
        "HORUS_ADVISOR_TOKEN": token,
        "HORUS_AI_SERVICE_TOKEN": token,
        "HF_HUB_OFFLINE": "1",
        "TRANSFORMERS_OFFLINE": "1",
    }
)
evidence = Path(tempfile.mkdtemp(prefix="horus-observaciones-real-"))
env["VIEW_COMPILED_PATH"] = str(evidence)


def bridge(scenario):
    result = subprocess.run(
        ["php", "tests/Support/horus-observaciones-bridge.php", scenario],
        check=False,
        cwd=root,
        env=env,
        capture_output=True,
        text=True,
        encoding="utf-8",
        timeout=180,
        creationflags=subprocess.CREATE_NO_WINDOW,
    )
    (evidence / (scenario + ".txt")).write_text(
        result.stdout + result.stderr, encoding="utf-8"
    )
    if result.returncode:
        raise RuntimeError(f"Bridge {scenario} failed; evidence {evidence}")
    json.loads(
        result.stdout.strip()
    )  # An exception rendered by Laravel is never a pass.
    print(result.stdout.strip(), flush=True)


for mode in ("lexical", "hybrid"):
    command = (
        "import os,uvicorn; from app.main import create_app; from app.core.config import Settings; "
        "settings=Settings(_env_file=None,service_token=os.environ['HORUS_AI_SERVICE_TOKEN'],"
        f"semantic_enabled={mode == 'hybrid'},catalog_path=None,catalog_signing_key=None); "
        f"uvicorn.run(create_app(settings),host='127.0.0.1',port={port},access_log=False)"
    )
    with (evidence / (mode + "-server.txt")).open("w", encoding="utf-8") as handle:
        process = subprocess.Popen(
            [str(root / "chatbot-python/.venv/Scripts/python.exe"), "-c", command],
            cwd=root / "chatbot-python",
            env=env,
            stdout=handle,
            stderr=handle,
            creationflags=subprocess.CREATE_NO_WINDOW,
        )
        print(f"START {mode} PID={process.pid}", flush=True)
        try:
            for _ in range(240):
                if process.poll() is not None:
                    raise RuntimeError("Temporary FastAPI exited")
                try:
                    with urlopen(
                        f"http://127.0.0.1:{port}/ready", timeout=1
                    ) as response:
                        ready = json.load(response)
                    if ready["semantic"] == (
                        "loaded" if mode == "hybrid" else "disabled"
                    ):
                        break
                except OSError:
                    pass
                time.sleep(0.25)
            else:
                raise RuntimeError("FastAPI readiness timeout")
            bridge(mode)
        finally:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
            print(f"STOP PID={process.pid}", flush=True)

bridge("off")
bridge("disabled")


class SlowHandler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        body = json.dumps(
            {
                "status": "ready",
                "knowledge": "loaded",
                "auth": "configured",
                "catalog": "none",
                "semantic": "disabled",
            }
        ).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        time.sleep(3.2)
        self.close_connection = True


server = ThreadingHTTPServer(("127.0.0.1", port), SlowHandler)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    bridge("timeout")
finally:
    server.shutdown()
    server.server_close()
    thread.join()
for check_port in (8001,):
    with socket.socket() as probe:
        if probe.connect_ex(("127.0.0.1", check_port)) == 0:
            raise RuntimeError("Temporary port still open")
print(f"Evidence: {evidence}; temporary services stopped", flush=True)
