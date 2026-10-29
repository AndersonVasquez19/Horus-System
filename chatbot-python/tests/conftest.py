"""Fixtures comunes. Las pruebas básicas son deterministas: sin modelo semántico ni red."""

from __future__ import annotations

import socket
from collections.abc import Iterator

import pytest
from fastapi.testclient import TestClient

from app.core.config import Settings, get_settings
from app.main import create_app
from app.services.chat_engine import AdvisorEngine
from app.services.knowledge import KnowledgeBase

TEST_TOKEN = "prueba-local-" + "x" * 40  # valor ficticio, solo para pruebas
LOOPBACK = {"127.0.0.1", "::1", "localhost"}

attempted_connections: list[object] = []


@pytest.fixture(autouse=True, scope="session")
def block_external_network() -> Iterator[None]:
    """Cualquier conexión a un destino no loopback falla y queda registrada."""
    original_connect = socket.socket.connect
    original_create = socket.create_connection

    def _host(address: object) -> str:
        return str(address[0]) if isinstance(address, tuple) else str(address)

    def guarded_connect(self: socket.socket, address: object) -> object:
        if _host(address) not in LOOPBACK:
            attempted_connections.append(address)
            raise OSError(f"Conexión externa bloqueada en pruebas: {address!r}")
        return original_connect(self, address)  # type: ignore[arg-type]

    def guarded_create(address: object, *args: object, **kwargs: object) -> object:
        if _host(address) not in LOOPBACK:
            attempted_connections.append(address)
            raise OSError(f"Conexión externa bloqueada en pruebas: {address!r}")
        return original_create(address, *args, **kwargs)  # type: ignore[arg-type]

    socket.socket.connect = guarded_connect  # type: ignore[method-assign]
    socket.create_connection = guarded_create  # type: ignore[assignment]
    try:
        yield
    finally:
        socket.socket.connect = original_connect  # type: ignore[method-assign]
        socket.create_connection = original_create  # type: ignore[assignment]


@pytest.fixture(autouse=True)
def _no_external_keys(monkeypatch: pytest.MonkeyPatch) -> None:
    for name in ("OPENAI_API_KEY", "RESEND_API_KEY", "HORUS_AI_SERVICE_TOKEN", "HORUS_AI_SEMANTIC_ENABLED"):
        monkeypatch.delenv(name, raising=False)
    get_settings.cache_clear()


def make_settings(**overrides: object) -> Settings:
    values: dict[str, object] = {"service_token": TEST_TOKEN, "semantic_enabled": False, "_env_file": None}
    values.update(overrides)
    return Settings(**values)  # type: ignore[arg-type]


@pytest.fixture
def settings() -> Settings:
    return make_settings()


@pytest.fixture
def client(settings: Settings) -> Iterator[TestClient]:
    with TestClient(create_app(settings)) as test_client:
        yield test_client


@pytest.fixture
def auth() -> dict[str, str]:
    return {"X-Horus-Service-Token": TEST_TOKEN}


@pytest.fixture(scope="session")
def kb() -> KnowledgeBase:
    s = Settings(_env_file=None)  # type: ignore[call-arg]
    return KnowledgeBase.load(s.knowledge_path, s.intents_path)


@pytest.fixture(scope="session")
def engine(kb: KnowledgeBase) -> AdvisorEngine:
    return AdvisorEngine(kb)
