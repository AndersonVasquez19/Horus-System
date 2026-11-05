"""Temporary loopback services for horus-ai-integration.mjs; no persistent database."""
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time
from urllib.request import urlopen

root = Path(__file__).resolve().parents[2]
for port in (8001, 8765, 19322):
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", port))
env = os.environ.copy()
token = secrets.token_hex(32)
env.update({
    "APP_ENV": "testing", "APP_DEBUG": "false", "APP_KEY": "base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=",
    "APP_URL": "http://127.0.0.1:8765", "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:", "DB_URL": "",
    "SESSION_DRIVER": "cookie", "CACHE_STORE": "array", "MAIL_MAILER": "array", "QUEUE_CONNECTION": "sync",
    "OPENAI_API_KEY": "", "MATRICULA_MAIL_ENABLED": "false", "HORUS_AI_BROWSER_FIXTURE": "1",
    "HORUS_ADVISOR_ENABLED": "true", "HORUS_ADVISOR_URL": "http://127.0.0.1:8001", "HORUS_ADVISOR_TOKEN": token,
    "HORUS_AI_SERVICE_TOKEN": token, "HF_HUB_OFFLINE": "1", "TRANSFORMERS_OFFLINE": "1",
})
processes = []
logdir = Path(tempfile.mkdtemp(prefix="horus-ai-real-"))
handles = []
try:
    command = (
        "import os,uvicorn; from app.main import create_app; from app.core.config import Settings; "
        "settings=Settings(_env_file=None, service_token=os.environ['HORUS_AI_SERVICE_TOKEN'], "
        "semantic_enabled=True, catalog_path=None, catalog_signing_key=None); "
        "uvicorn.run(create_app(settings),host='127.0.0.1',port=8001,access_log=False)"
    )
    for name, args, cwd in [
        ("python", [str(root / "chatbot-python/.venv/Scripts/python.exe"), "-c", command], root / "chatbot-python"),
        ("laravel", ["php", "-S", "127.0.0.1:8765", "-t", "public", "tests/Support/horus-ai-browser-router.php"], root),
    ]:
        handle = (logdir / (name + ".log")).open("w", encoding="utf-8")
        handles.append(handle)
        process = subprocess.Popen(args, cwd=cwd, env=env, stdout=handle, stderr=handle, creationflags=subprocess.CREATE_NO_WINDOW)
        processes.append(process)
        print(f"START {name} PID={process.pid}", flush=True)
    for _ in range(240):
        if any(p.poll() is not None for p in processes):
            raise RuntimeError("Temporary service exited; inspect local temporary logs without exposing credentials.")
        try:
            with urlopen("http://127.0.0.1:8001/ready", timeout=1) as response:
                ready = json.load(response)
            if ready.get("semantic") == "loaded":
                print("READY semantic=loaded catalog=" + ready.get("catalog", "unknown"), flush=True)
                break
        except OSError:
            pass
        time.sleep(0.25)
    else:
        raise RuntimeError("Local semantic model did not load.")
    with urlopen("http://127.0.0.1:8765/", timeout=30) as response:
        html = response.read().decode("utf-8")
        if 'horus-chat-panel' not in html:
            raise RuntimeError("Laravel fixture did not render widget")
        print("READY Laravel widget HTTP=" + str(response.status), flush=True)
    subprocess.run(["node", "tests/browser/horus-ai-integration.mjs"], cwd=root, env=env, check=True, timeout=60)
finally:
    for process in processes:
        process.terminate()
    for process in processes:
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
        print(f"STOP PID={process.pid}", flush=True)
    for handle in handles:
        handle.close()
    time.sleep(2)
    for port in (8001, 8765):
        with socket.socket() as probe:
            if probe.connect_ex(("127.0.0.1", port)) == 0:
                raise RuntimeError(f"Port {port} remains occupied")
    print("Temporary Laravel and FastAPI ports closed.", flush=True)
