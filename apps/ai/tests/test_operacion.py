"""Operación simulada: no inicia Uvicorn ni carga modelos o .env."""

import json
import logging
from unittest.mock import patch

from app.core.config import Settings
from app.operacion import RegistroOperativo, errores, main


def settings(tmp_path):
    (tmp_path / "modules.json").write_text("[]")
    return Settings(_env_file=None, service_token="t" * 32, catalog_signing_key="k" * 32,
                    catalog_path=tmp_path / "active.json", semantic_enabled=True,
                    semantic_model_dir=tmp_path)


def test_disabled_never_constructs_app_or_loads_settings(monkeypatch, capsys):
    monkeypatch.delenv("HORUS_OPS_ENABLED", raising=False)
    with patch("app.operacion.Settings") as config:
        assert main([]) == 0
        assert main(["run", "--execute"]) == 1
        config.assert_not_called()
    assert "started" in capsys.readouterr().out


def test_preflight_does_not_start_uvicorn(tmp_path, monkeypatch, capsys):
    monkeypatch.setenv("HORUS_OPS_ENABLED", "true")
    with patch("app.operacion.Settings", return_value=settings(tmp_path)), patch("uvicorn.run") as run:
        assert main(["check"]) == 0
        assert main(["run"]) == 0
        run.assert_not_called()
    assert not json.loads(capsys.readouterr().out.splitlines()[0])["started"]


def test_configuration_errors_never_print_original_values(monkeypatch, capsys):
    monkeypatch.setenv("HORUS_OPS_ENABLED", "true")
    with patch("app.operacion.Settings", side_effect=ValueError("NO_DIVULGAR")):
        assert main([]) == 1
    assert "NO_DIVULGAR" not in capsys.readouterr().out


def test_rejects_unsigned_network_missing_model_and_storage(tmp_path):
    config = settings(tmp_path)
    config.allow_non_loopback = True
    config.catalog_allow_unsigned = True
    config.semantic_enabled = False
    config.catalog_path = tmp_path / "absent" / "active.json"
    assert set(errores(config)) >= {"loopback_required", "signed_catalog_required",
                                   "local_model_required", "catalog_storage"}


def test_explicit_launcher_passes_safe_process_settings(tmp_path, monkeypatch):
    monkeypatch.setenv("HORUS_OPS_ENABLED", "true")
    with (patch("app.operacion.Settings", return_value=settings(tmp_path)),
          patch("app.main.create_app", return_value=object()), patch("uvicorn.run") as run,
          patch("logging.config.dictConfig")):
        assert main(["run", "--execute"]) == 0
        assert run.call_args.kwargs["host"] == "127.0.0.1"
        assert run.call_args.kwargs["workers"] == 1
        assert run.call_args.kwargs["access_log"] is False


def test_renewal_extends_expiry_without_changing_content_version(tmp_path):
    """ASGI en proceso, sin sockets ni modelo: reutiliza el contrato A1 existente."""
    from datetime import UTC, datetime, timedelta

    from tests.conftest import TEST_TOKEN
    from tests.test_catalog_sync import _catalog, _client

    client, active = _client(tmp_path)
    now = datetime.now(UTC)
    first = _catalog(now - timedelta(minutes=3))
    renewed = _catalog(now)
    headers = {"X-Horus-Service-Token": TEST_TOKEN}
    assert first["catalog_version"] == renewed["catalog_version"]
    with client:
        assert client.post("/api/v1/catalog/sync", json=first, headers=headers).status_code == 200
        assert client.post("/api/v1/catalog/sync", json=renewed, headers=headers).status_code == 200
        assert json.loads(active.read_text(encoding="utf-8"))["expires_at"] == renewed["expires_at"]
        assert client.get("/ready").json()["catalog_version"] == first["catalog_version"]


def test_operational_logging_removes_arguments_and_exception_text():
    record = logging.LogRecord("horus_ai", logging.ERROR, "fixture", 1,
                               "Catálogo rechazado: %s", ("NO_DIVULGAR",), None)
    record.exc_text = "NO_DIVULGAR"
    record.stack_info = "NO_DIVULGAR"
    assert RegistroOperativo().filter(record)
    assert "NO_DIVULGAR" not in logging.Formatter().format(record)
    assert record.args == () and record.exc_info is None
    assert record.exc_text is None and record.stack_info is None


def test_operational_metrics_keep_only_whitelisted_values():
    record = logging.LogRecord("horus_ai.api", logging.INFO, "fixture", 1,
                               "chat intent=%s match=%s clarify=%s abstained=%s mode=%s ms=%.1f",
                               ("NO_DIVULGAR", "NO_DIVULGAR", False, False, "hybrid", 123.4), None)
    RegistroOperativo().filter(record)
    assert json.loads(record.getMessage()) == {"event": "chat", "mode": "hybrid", "ms": 123.4}
