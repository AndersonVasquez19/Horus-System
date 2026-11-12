"""Integración con el modelo de embeddings REAL (local). Se ejecuta con:  pytest -m semantic

Requiere el extra semántico instalado y el modelo en models/ (scripts/download_model.py).
No descarga nada: si el modelo no está en disco, las pruebas se omiten.
"""

from __future__ import annotations

import os

import pytest

from app.core.config import Settings
from app.services.chat_engine import AdvisorEngine
from app.services.knowledge import KnowledgeBase
from app.services.semantic_search import SemanticIndex

pytestmark = pytest.mark.semantic

os.environ.setdefault("HF_HUB_OFFLINE", "1")
os.environ.setdefault("TRANSFORMERS_OFFLINE", "1")


@pytest.fixture(scope="module")
def hybrid_engine() -> AdvisorEngine:
    pytest.importorskip("sentence_transformers")
    settings = Settings(_env_file=None)  # type: ignore[call-arg]
    if not settings.semantic_model_dir.is_dir():
        pytest.skip("Modelo no descargado (scripts/download_model.py)")
    from app.services.semantic_search import SentenceTransformerEmbedder

    kb = KnowledgeBase.load(settings.knowledge_path, settings.intents_path)
    index = SemanticIndex(kb.records)
    index.attach(SentenceTransformerEmbedder(settings.semantic_model_dir, settings.ml_threads))
    engine = AdvisorEngine(kb, index)
    assert engine.mode == "hybrid"
    return engine


def test_semantic_reorders_lexical_candidates(hybrid_engine: AdvisorEngine) -> None:
    reply = hybrid_engine.chat("¿Me ayudan a terminar mi investigación de pregrado?")
    ids = [s.id for s in reply.sources] + [r.id for r in reply.recommendations]
    assert ids and ids[0] == "asesoramiento_educativo"


@pytest.mark.parametrize(
    ("message", "expected_title"),
    [
        ("¿Cuál es el rubro de su compañía?", "Qué es Horus Group SRL"),
        ("¿Por qué medio les escribo?", "Canales de contacto"),
    ],
)
def test_semantic_only_evidence_leads_to_clarification(
    hybrid_engine: AdvisorEngine, message: str, expected_title: str
) -> None:
    """Diseño: sin apoyo léxico, la similitud semántica no basta para afirmar; propone la opción correcta."""
    reply = hybrid_engine.chat(message)
    assert reply.needs_clarification and reply.sources == []
    assert expected_title in (reply.clarification_question or "")


def test_digital_wallets_resolved_in_sprint2(hybrid_engine: AdvisorEngine) -> None:
    """Antes era xfail: hueco de vocabulario (Yape y Plin no se describían como billeteras digitales)."""
    reply = hybrid_engine.chat("¿Aceptan billeteras digitales?")
    assert reply.sources and reply.sources[0].id == "faq_medios_pago"


def test_model_loaded_without_network(hybrid_engine: AdvisorEngine) -> None:
    """La carga ocurrió bajo el bloqueo de red de conftest: no hubo ningún intento de conexión."""
    from tests import conftest

    assert hybrid_engine.mode == "hybrid"
    assert conftest.attempted_connections == []


def test_broken_model_falls_back_to_lexical(tmp_path) -> None:  # type: ignore[no-untyped-def]
    broken = tmp_path / "modelo-roto"
    broken.mkdir()
    (broken / "config.json").write_text("{ no es json", encoding="utf-8")
    settings = Settings(_env_file=None)  # type: ignore[call-arg]
    kb = KnowledgeBase.load(settings.knowledge_path, settings.intents_path)
    index = SemanticIndex(kb.records)
    thread = index.load_in_background(broken, threads=1)
    assert thread is not None
    thread.join(timeout=60)
    assert index.status == "unavailable"
    engine = AdvisorEngine(kb, index)
    assert engine.mode == "lexical"
    assert engine.chat("¿Aceptan Yape?").sources[0].id == "faq_medios_pago"


@pytest.mark.parametrize(
    "message",
    [
        "¿Cuál es la capital de Francia?",
        "Escríbeme una canción",
        "¿Quién ganó el mundial?",
        "receta de lomo saltado",
    ],
)
def test_semantic_does_not_force_irrelevant_matches(hybrid_engine: AdvisorEngine, message: str) -> None:
    reply = hybrid_engine.chat(message)
    assert reply.sources == [] and reply.recommendations == []


def test_semantic_keeps_abstention_for_unavailable(hybrid_engine: AdvisorEngine) -> None:
    reply = hybrid_engine.chat("¿Qué precio tiene la capacitación?")
    assert reply.abstained and reply.intent == "unavailable_info"
