"""MiniLM local real; sin descargas, servicios ni consultas de reservas."""

import statistics
import time

import pytest

from app.core.config import Settings
from app.services.semantic_search import SemanticIndex, SentenceTransformerEmbedder
from tests.test_phase_c import make_engine

pytestmark = pytest.mark.semantic


@pytest.fixture(scope="module")
def conversation_engine():
    settings = Settings(_env_file=None)
    assert settings.semantic_model_dir.is_dir(), "Se requiere el modelo local existente; no descargar."
    engine = make_engine()
    index = SemanticIndex(engine.kb.search_records)
    index.attach(SentenceTransformerEmbedder(settings.semantic_model_dir, threads=2))
    engine.semantic = index
    return engine


@pytest.mark.parametrize(
    ("query", "source"),
    [
        ("Quiero aprender analisis de datos", "cat_curso_datos"),
        ("Me interesa estudiar Python", "cat_curso_datos"),
        ("Quiero aprender limpieza de datos y graficos", "cat_curso_datos"),
        ("Busco un servicio de conectividad", "cat_servicio_redes"),
        ("Necesito contratar redes empresariales", "cat_servicio_redes"),
        ("Quiero aprender analisiss de datos", "cat_curso_datos"),
    ],
)
def test_local_minilm_handles_natural_queries(conversation_engine, query, source):
    reply = conversation_engine.chat(query)
    assert reply.mode == "hybrid"
    assert reply.recommendations[0].id == source
    assert reply.recommendations[0].evidence_text
    assert reply.sources[0].id == source


@pytest.mark.parametrize(
    "query",
    [
        "Quiero aprender cocina marciana",
        "Necesito contratar cirugia veterinaria",
        "Quiero aprender a predecir resultados de loteria",
        "La palabra curso aparece en mi poema",
    ],
)
def test_local_model_cannot_force_unrelated_recommendations(conversation_engine, query):
    reply = conversation_engine.chat(query)
    assert not reply.recommendations
    assert reply.abstained or reply.needs_clarification


def test_local_model_latency_and_offline_inference(conversation_engine, record_property):
    from tests import conftest

    times = []
    for _ in range(10):
        started = time.perf_counter()
        reply = conversation_engine.chat("Me interesa estudiar Python")
        times.append(1000 * (time.perf_counter() - started))
        assert reply.recommendations[0].id == "cat_curso_datos"
    record_property("chat_p50_ms", round(statistics.median(times), 3))
    record_property("chat_p95_ms", round(sorted(times)[-1], 3))
    record_property("samples", len(times))
    assert conftest.attempted_connections == []
