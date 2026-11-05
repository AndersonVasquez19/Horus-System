"""Entrada operativa opt-in: check no carga MiniLM, run requiere autorización de proceso."""

from __future__ import annotations

import argparse
import json
import logging
import logging.config
import math
import os
import re

from app.core.config import Settings


class RegistroOperativo(logging.Filter):
    """Lista positiva de métricas. No formatea ni reenvía argumentos/trazas originales."""

    def filter(self, record: logging.LogRecord) -> bool:
        template = record.msg if isinstance(record.msg, str) else ""
        args = record.args if isinstance(record.args, tuple) else ()
        data: dict[str, object] = {"event": "runtime"}
        if template.startswith("chat intent="):
            data["event"] = "chat"
            if len(args) == 6:
                elapsed = args[5]
                if isinstance(elapsed, (int, float)) and math.isfinite(elapsed) and 0 <= elapsed <= 30000:
                    data["ms"] = round(elapsed, 2)
                if args[4] in ("hybrid", "lexical"):
                    data["mode"] = args[4]
        elif template.startswith("catalog-sync outcome=applied"):
            data["event"] = "catalog_applied"
            if args and isinstance(args[0], str) and re.fullmatch(r"[a-f0-9]{40}", args[0]):
                data["version"] = args[0]
        elif template.startswith("catalog-sync outcome=rejected"):
            data["event"] = "catalog_rejected"
        elif template.startswith("catalog-sync outcome=failed"):
            data["event"] = "catalog_failed"
        elif template.startswith("Modelo semántico cargado"):
            data["event"] = "semantic_loaded"
        elif template.startswith("No se pudo cargar"):
            data["event"] = "load_failed"
        elif template.startswith("Catálogo rechazado"):
            data["event"] = "catalog_rejected"
        elif template.startswith("vector-cache outcome=ignored"):
            data["event"] = "vector_cache_ignored"
        elif template.startswith("vector-cache outcome=write_failed"):
            data["event"] = "vector_cache_write_failed"
        record.msg = json.dumps(data)
        record.args = ()
        record.exc_info = None
        record.exc_text = None
        record.stack_info = None
        return True


def logging_config() -> dict:
    return {"version": 1, "disable_existing_loggers": False,
            "filters": {"safe": {"()": RegistroOperativo}},
            "handlers": {"safe": {"class": "logging.StreamHandler", "filters": ["safe"]}},
            "root": {"handlers": ["safe"], "level": "INFO"},
            "loggers": {"uvicorn": {"handlers": ["safe"], "level": "INFO", "propagate": False},
                        "uvicorn.error": {"handlers": ["safe"], "level": "INFO", "propagate": False},
                        "uvicorn.access": {"handlers": [], "propagate": False}}}


def errores(settings: Settings) -> list[str]:
    errors: list[str] = []
    if settings.host != "127.0.0.1" or settings.allow_non_loopback:
        errors.append("loopback_required")
    if not settings.service_token_configured:
        errors.append("service_token")
    if settings.catalog_key is None or settings.catalog_allow_unsigned:
        errors.append("signed_catalog_required")
    if settings.catalog_path is None or not settings.catalog_path.parent.is_dir():
        errors.append("catalog_storage")
    if not settings.semantic_enabled or not (settings.semantic_model_dir / "modules.json").is_file():
        errors.append("local_model_required")
    if settings.semantic_cache_dir is not None and not settings.semantic_cache_dir.is_dir():
        errors.append("semantic_cache_storage")
    if not settings.knowledge_path.is_file() or not settings.intents_path.is_file():
        errors.append("knowledge_files")
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Preflight seguro de Horus AI; no activa visitantes")
    parser.add_argument("action", nargs="?", choices=["check", "run"], default="check")
    parser.add_argument("--execute", action="store_true")
    args = parser.parse_args(argv)
    enabled = os.environ.get("HORUS_OPS_ENABLED", "false").lower() == "true"
    if not enabled:
        print(json.dumps({"enabled": False, "started": False, "errors": []}))
        return 1 if args.action == "run" and args.execute else 0
    try:
        # Solo entorno suministrado por el operador; no carga .env del checkout.
        settings = Settings(_env_file=None)
        errors = errores(settings)
    except Exception:
        # Pydantic puede incluir el valor original en ValidationError: nunca imprimirlo.
        print(json.dumps({"enabled": True, "started": False, "errors": ["configuration_invalid"]}))
        return 1
    model = settings.semantic_model_dir
    result = {"enabled": True, "started": False, "errors": errors, "workers": 1,
              "ml_threads": settings.ml_threads, "model_present": (model / "modules.json").is_file()}
    if errors or args.action == "check" or not args.execute:
        print(json.dumps(result))
        return 1 if errors else 0
    # Importación tardía: un preflight nunca construye el motor ni inicia sockets.
    import uvicorn

    from app.main import create_app

    safe_logs = logging_config()
    logging.config.dictConfig(safe_logs)
    uvicorn.run(create_app(settings), host="127.0.0.1", port=settings.port,
                workers=1, proxy_headers=False, access_log=False, log_config=safe_logs)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
