"""Validación, construcción y persistencia atómica de snapshots firmados."""

from __future__ import annotations

import hashlib
import json
import os
import tempfile
from contextlib import suppress
from datetime import UTC, datetime, timedelta
from pathlib import Path
from typing import Any

from app.core.config import Settings
from app.services.chat_engine import AdvisorEngine
from app.services.knowledge import KnowledgeBase
from app.services.semantic_search import SemanticIndex


class CatalogSyncRejected(ValueError):
    def __init__(self, message: str, status_code: int = 422) -> None:
        super().__init__(message)
        self.status_code = status_code


def expected_content_version(raw: dict[str, Any]) -> str:
    items = []
    for item in raw.get("items", []):
        projected = dict(item)
        projected.pop("updated_at", None)
        items.append(projected)
    content = {"managed_types": raw.get("managed_types", []), "items": items}
    encoded = json.dumps(content, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()[:40]


def persist_catalog_atomically(path: Path, raw: dict[str, Any]) -> None:
    if path.is_symlink() or path.parent.is_symlink() or not path.parent.is_dir():
        raise OSError("Destino de catálogo no seguro o inexistente.")
    encoded = json.dumps(raw, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    descriptor, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        os.chmod(temporary, 0o600)
        with os.fdopen(descriptor, "wb") as output:
            output.write(encoded)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
    finally:
        with suppress(OSError):
            temporary.unlink(missing_ok=True)


def build_catalog_candidate(
    raw: dict[str, Any], settings: Settings, engine: AdvisorEngine, semantic: SemanticIndex | None
) -> tuple[AdvisorEngine, SemanticIndex | None, str, datetime]:
    if settings.catalog_path is None or settings.catalog_key is None:
        raise CatalogSyncRejected("La sincronización del catálogo no está configurada.", 503)
    if raw.get("catalog_version") != expected_content_version(raw):
        raise CatalogSyncRejected("La versión del catálogo no corresponde a su contenido.")
    try:
        candidate_kb = KnowledgeBase.load_with_catalog_payload(
            settings.knowledge_path,
            settings.intents_path,
            raw,
            settings.catalog_key,
            allow_unsigned_catalog=False,
        )
    except Exception as exc:
        # No propagar mensajes de validación que podrían incluir texto editorial.
        raise CatalogSyncRejected("El catálogo no superó la validación de firma, esquema o privacidad.") from exc

    catalog = candidate_kb.catalog.catalog if candidate_kb.catalog else None
    if catalog is None:
        raise CatalogSyncRejected("El catálogo no contiene un snapshot válido.")
    now = datetime.now(UTC)
    if catalog.expires_at <= now:
        raise CatalogSyncRejected("El snapshot del catálogo ya caducó.")
    if catalog.generated_at > now + timedelta(minutes=2):
        raise CatalogSyncRejected("La fecha de generación no es válida.")

    current_catalog = engine.kb.catalog.catalog if engine.kb.catalog else None
    if current_catalog is not None:
        if catalog.generated_at < current_catalog.generated_at:
            raise CatalogSyncRejected("Se rechazó una versión anterior del catálogo.", 409)
        if catalog.generated_at == current_catalog.generated_at:
            if catalog.catalog_version == current_catalog.catalog_version and catalog.signature == current_catalog.signature:
                return engine, semantic, current_catalog.catalog_version, catalog.generated_at
            raise CatalogSyncRejected("La versión no avanza respecto del catálogo activo.", 409)

    replacement_semantic = semantic.rebuilt(candidate_kb.search_records) if semantic is not None else None
    candidate_engine = AdvisorEngine(candidate_kb, replacement_semantic, engine.gemini)
    if catalog.expires_at <= datetime.now(UTC):
        raise CatalogSyncRejected("El snapshot caducó durante la construcción del índice.")
    return candidate_engine, replacement_semantic, catalog.catalog_version, catalog.generated_at
