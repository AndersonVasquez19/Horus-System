"""Regresión de sincronización del catálogo mediante ASGI aislado y fixtures ficticios."""

from __future__ import annotations

import copy
import json
from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, datetime, timedelta
from pathlib import Path
from threading import Barrier

import numpy as np
from fastapi.testclient import TestClient

from app.core.config import Settings
from app.main import create_app
from app.services.catalog import sign
from app.services.catalog_sync import expected_content_version
from app.services.semantic_search import SemanticIndex
from tests.conftest import TEST_TOKEN
from tests.test_catalog import FIXTURE, KEY


def _catalog(generated_at: datetime, *, items: list[dict] | None = None) -> dict:
    raw = json.loads(FIXTURE.read_text(encoding="utf-8"))
    raw["managed_types"] = ["curso", "servicio"]
    raw["items"] = [item for item in raw["items"] if item["type"] in raw["managed_types"]]
    raw["generated_at"] = generated_at.isoformat()
    raw["expires_at"] = (generated_at + timedelta(minutes=5)).isoformat()
    if items is not None:
        raw["items"] = copy.deepcopy(items)
    raw["catalog_version"] = expected_content_version(raw)
    raw["signature"] = sign(raw, KEY)
    return raw


def _client(tmp_path: Path) -> tuple[TestClient, Path]:
    active = tmp_path / "catalog" / "active.json"
    active.parent.mkdir(parents=True)
    settings = Settings(
        _env_file=None,
        service_token=TEST_TOKEN,
        catalog_path=active,
        catalog_signing_key=KEY,
        semantic_enabled=False,
    )
    return TestClient(create_app(settings)), active


class _FakeEmbedder:
    def encode(self, texts: list[str]) -> np.ndarray:
        return np.tile(np.array([1.0, 0.0]), (len(texts), 1))


def test_signed_catalog_is_loaded_without_restart_and_version_is_exposed(tmp_path: Path) -> None:
    client, active = _client(tmp_path)
    first = datetime.now(UTC).replace(microsecond=0)
    raw = _catalog(first)
    with client:
        response = client.post("/api/v1/catalog/sync", json=raw, headers={"X-Horus-Service-Token": TEST_TOKEN})
        assert response.status_code == 200
        assert response.json()["catalog_version"] == raw["catalog_version"]
        ready = client.get("/ready").json()
        assert ready["catalog"] == "loaded"
        assert ready["catalog_version"] == raw["catalog_version"]
        assert active.exists()
        assert client.app.state.engine.kb.catalog.catalog.catalog_version == raw["catalog_version"]
        assert all(raw["catalog_version"] not in record.answer for record in client.app.state.engine.kb.records)


def test_concurrent_catalog_updates_are_serialized_and_idempotent(tmp_path: Path) -> None:
    client, _ = _client(tmp_path)
    raw = _catalog(datetime.now(UTC))
    headers = {"X-Horus-Service-Token": TEST_TOKEN}
    barrier = Barrier(2)

    with client:
        def submit() -> int:
            barrier.wait(timeout=5)
            return client.post("/api/v1/catalog/sync", json=raw, headers=headers).status_code

        with ThreadPoolExecutor(max_workers=2) as executor:
            statuses = list(executor.map(lambda _: submit(), range(2)))

        assert statuses == [200, 200]
        assert client.get("/ready").json()["catalog_version"] == raw["catalog_version"]


def test_invalid_signature_keeps_last_valid_catalog_and_disables_stale_answers(tmp_path: Path) -> None:
    client, active = _client(tmp_path)
    first = datetime.now(UTC).replace(microsecond=0)
    valid = _catalog(first)
    with client:
        headers = {"X-Horus-Service-Token": TEST_TOKEN}
        assert client.post("/api/v1/catalog/sync", json=valid, headers=headers).status_code == 200
        old_bytes = active.read_bytes()
        old_engine = client.app.state.engine
        forged = _catalog(first + timedelta(seconds=1))
        forged["items"][0]["title"] = "Contenido manipulado"
        response = client.post("/api/v1/catalog/sync", json=forged, headers=headers)
        assert response.status_code == 422
        assert active.read_bytes() == old_bytes
        assert client.app.state.engine is old_engine
        assert client.get("/ready").json()["catalog"] == "error"
        blocked = client.post("/api/v1/chat", json={"message": "¿Qué cursos ofrecen?"}, headers=headers)
        assert blocked.status_code == 503
        recovery = _catalog(first + timedelta(seconds=2))
        assert client.post("/api/v1/catalog/sync", json=recovery, headers=headers).status_code == 200
        assert client.get("/ready").json()["catalog"] == "loaded"
        assert client.app.state.engine.kb.catalog.catalog.catalog_version == recovery["catalog_version"]


def test_older_catalog_is_rejected_and_empty_snapshot_removes_old_course_records(tmp_path: Path) -> None:
    client, active = _client(tmp_path)
    first = datetime.now(UTC).replace(microsecond=0)
    current = _catalog(first)
    with client:
        headers = {"X-Horus-Service-Token": TEST_TOKEN}
        assert client.post("/api/v1/catalog/sync", json=current, headers=headers).status_code == 200
        old_bytes = active.read_bytes()
        older = _catalog(first - timedelta(seconds=1))
        assert client.post("/api/v1/catalog/sync", json=older, headers=headers).status_code == 409
        assert active.read_bytes() == old_bytes
        empty = _catalog(first + timedelta(seconds=2), items=[])
        assert client.post("/api/v1/catalog/sync", json=empty, headers=headers).status_code == 200
        assert not any(record.stable_id and record.stable_id.startswith("curso:") for record in client.app.state.engine.kb.records)
        assert not any(record.stable_id and record.stable_id.startswith("servicio:") for record in client.app.state.engine.kb.records)
        assert client.app.state.engine.kb.catalog.records == []


def test_missing_token_or_unconfigured_store_cannot_replace_catalog(tmp_path: Path) -> None:
    client, active = _client(tmp_path)
    raw = _catalog(datetime.now(UTC).replace(microsecond=0))
    with client:
        assert client.post("/api/v1/catalog/sync", json=raw).status_code == 401
        assert not active.exists()


def test_sync_without_a_configured_storage_path_fails_closed() -> None:
    settings = Settings(
        _env_file=None,
        service_token=TEST_TOKEN,
        catalog_path=None,
        catalog_signing_key=KEY,
        semantic_enabled=False,
    )
    raw = _catalog(datetime.now(UTC).replace(microsecond=0))
    with TestClient(create_app(settings)) as client:
        response = client.post("/api/v1/catalog/sync", json=raw, headers={"X-Horus-Service-Token": TEST_TOKEN})
        assert response.status_code == 503
        assert client.get("/ready").json()["catalog"] == "error"
        assert client.app.state.catalog_generation == 0


def test_sync_route_uses_catalog_body_limit_instead_of_chat_body_limit(tmp_path: Path) -> None:
    active = tmp_path / "catalog" / "active.json"
    active.parent.mkdir()
    settings = Settings(
        _env_file=None,
        service_token=TEST_TOKEN,
        catalog_path=active,
        catalog_signing_key=KEY,
        semantic_enabled=False,
        max_body_bytes=1024,
        max_catalog_bytes=16384,
    )
    raw = _catalog(datetime.now(UTC))
    raw["padding"] = "x" * 2000

    with TestClient(create_app(settings)) as client:
        response = client.post(
            "/api/v1/catalog/sync", json=raw, headers={"X-Horus-Service-Token": TEST_TOKEN}
        )

    assert response.status_code == 422


def test_startup_with_expired_catalog_is_stale_and_cannot_answer(tmp_path: Path) -> None:
    client, active = _client(tmp_path)
    expired = _catalog(datetime.now(UTC) - timedelta(minutes=10))
    active.write_text(json.dumps(expired, ensure_ascii=False), encoding="utf-8")
    with client:
        ready = client.get("/ready").json()
        assert ready["catalog"] == "stale"
        assert ready["status"] == "not_ready"
        response = client.post("/api/v1/chat", json={"message": "¿Qué cursos ofrecen?"}, headers={"X-Horus-Service-Token": TEST_TOKEN})
        assert response.status_code == 503


def test_loaded_catalog_becomes_stale_after_expiry_and_cannot_answer(tmp_path: Path) -> None:
    client, _ = _client(tmp_path)
    with client:
        headers = {"X-Horus-Service-Token": TEST_TOKEN}
        raw = _catalog(datetime.now(UTC))
        assert client.post("/api/v1/catalog/sync", json=raw, headers=headers).status_code == 200
        catalog_load = client.app.state.engine.kb.catalog
        assert catalog_load is not None
        catalog_load.catalog = catalog_load.catalog.model_copy(
            update={"expires_at": datetime.now(UTC) - timedelta(seconds=1)}
        )

        ready = client.get("/ready").json()
        response = client.post("/api/v1/chat", json={"message": "consulta informativa"}, headers=headers)

        assert ready["catalog"] == "stale"
        assert ready["status"] == "not_ready"
        assert response.status_code == 503


def test_semantic_index_is_rebuilt_before_catalog_swap(tmp_path: Path) -> None:
    client, _ = _client(tmp_path)
    raw = _catalog(datetime.now(UTC).replace(microsecond=0))
    with client:
        old_index: SemanticIndex = client.app.state.semantic
        old_index.attach(_FakeEmbedder())
        response = client.post("/api/v1/catalog/sync", json=raw, headers={"X-Horus-Service-Token": TEST_TOKEN})
        assert response.status_code == 200
        new_index: SemanticIndex = client.app.state.semantic
        assert new_index is not old_index
        assert new_index.status == "loaded"
        assert "cat_curso_redes_basico" in new_index.search("redes", {"cat_curso_redes_basico"})
        assert not old_index.search("redes", {"cat_curso_redes_basico"})
