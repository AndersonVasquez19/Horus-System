"""Fase C: consultas públicas ficticias, sin reservas ni servidores."""

import json
from datetime import UTC, datetime
from pathlib import Path

import pytest

from app.core.config import Settings
from app.schemas.chat import HistoryItem, RecommendRequest
from app.services.chat_engine import AdvisorEngine
from app.services.knowledge import KnowledgeBase
from tests.conftest import TEST_TOKEN
from tests.test_catalog import KEY
from tests.test_catalog_sync import _catalog, _client


def public_items():
    return [
        {
            "stable_id": "curso:datos",
            "type": "curso",
            "publication_status": "vigente",
            "title": "Analisis de datos con Python",
            "summary": "Aprende analisis de datos con Python.",
            "details": "Practica limpieza de datos y construccion de graficos con Python.",
            "keywords": ["analisis de datos", "Python"],
            "modalities": ["virtual"],
            "updated_at": datetime.now(UTC).isoformat(),
        },
        {
            "stable_id": "servicio:redes",
            "type": "servicio",
            "publication_status": "vigente",
            "title": "Redes empresariales",
            "summary": "Instalacion y mantenimiento de redes empresariales.",
            "details": "Diagnostico de conectividad y configuracion de redes empresariales.",
            "keywords": ["redes empresariales", "conectividad"],
            "updated_at": datetime.now(UTC).isoformat(),
        },
    ]


def make_engine(items=None):
    s = Settings(_env_file=None)
    kb = KnowledgeBase.load_with_catalog_payload(
        s.knowledge_path,
        s.intents_path,
        _catalog(datetime.now(UTC), items=public_items() if items is None else items),
        KEY,
    )
    return AdvisorEngine(kb)


@pytest.mark.parametrize(
    "query",
    [
        "Quiero aprender analisis de datos",
        "Me interesa estudiar Python",
        "Tienen algo relacionado con Python",
        "Algun cursito de Python porfa",
        "Quisiera formarme en analisis de datos",
        "Busco capacitacion sobre analisis de datos",
        "Quiero aprender analisiss de datos",
        "Quiero aprender limpieza de datos y graficos",
    ],
)
def test_natural_learning_queries_recommend_published_evidence(query):
    engine = make_engine()
    reply = engine.chat(query)
    assert reply.intent == "course_recommendation"
    assert [r.id for r in reply.recommendations] == ["cat_curso_datos"]
    option = reply.recommendations[0]
    assert option.evidence_text
    assert option.evidence_text in option.reason
    assert option.evidence == [s.id for s in reply.sources]
    record = engine.kb.get(option.id)
    allowed = [record.answer, *(f.answer for f in engine.kb.documents[0].fragments)]
    assert reply.answer in allowed


@pytest.mark.parametrize(
    "query",
    [
        "Que capacitaciones ofrecen",
        "Que cursos tienen",
        "Cuales son sus cursos publicados",
        "Que formaciones hay disponibles",
        "Cursos?",
    ],
)
def test_broad_learning_queries_use_current_listing(query):
    reply = make_engine().chat(query)
    assert not reply.abstained and not reply.needs_clarification
    assert reply.sources[0].id == "cat_listado_formacion"
    assert "Analisis de datos con Python" in reply.answer


@pytest.mark.parametrize(
    "query", ["Que servicios informaticos realizan", "Que servicios tecnologicos ofrecen", "Servicios?"]
)
def test_broad_services_use_published_service_listing(query):
    reply = make_engine().chat(query)
    assert reply.sources[0].id == "cat_listado_servicios"
    assert "Redes empresariales" in reply.answer
    assert "Analisis de datos" not in reply.answer


def test_negated_learning_intent_does_not_become_a_recommendation():
    reply = make_engine().chat("No quiero aprender Python")
    assert not reply.recommendations
    assert reply.needs_clarification


@pytest.mark.parametrize(
    "query",
    [
        "Necesito contratar redes empresariales",
        "Busco un servicio de conectividad",
        "Quisiera que instalen redes empresariales",
    ],
)
def test_service_intent_keeps_learning_and_hiring_separate(query):
    reply = make_engine().chat(query)
    assert reply.intent == "service_recommendation"
    assert reply.recommendations[0].id == "cat_servicio_redes"
    assert "https://wa.me/51927582305" in reply.recommendations[0].next_step


@pytest.mark.parametrize(
    "query",
    [
        "Necesito una solucion tecnologica para mi empresa",
        "Quiero aprender o contratar redes empresariales",
        "Necesito algo",
    ],
)
def test_uncertainty_requests_a_simple_clarification(query):
    reply = make_engine().chat(query)
    assert reply.needs_clarification
    assert not reply.recommendations


@pytest.mark.parametrize(
    "query",
    [
        "Quiero matricularme",
        "Como me inscribo en Python",
        "Deseo inscribirme en el curso",
    ],
)
def test_enrollment_uses_official_whatsapp_without_collecting_personal_data(query):
    reply = make_engine().chat(query)
    assert reply.intent == "enrollment_request"
    assert "https://wa.me/51927582305" in reply.answer
    assert "DNI" not in reply.answer and "tu nombre" not in reply.answer


@pytest.mark.parametrize(
    "query",
    [
        "Quiero aprender cocina marciana",
        "Necesito contratar cirugia veterinaria",
        "Quiero aprender a predecir resultados de loteria",
        "La palabra curso aparece en mi poema",
    ],
)
def test_unrelated_queries_do_not_force_recommendations(query):
    reply = make_engine().chat(query)
    assert not reply.recommendations
    assert reply.abstained or reply.needs_clarification


def test_current_query_does_not_inherit_a_previous_recommendation_interest():
    reply = make_engine().chat(
        "Quiero aprender cocina marciana", [HistoryItem(role="user", content="Python")]
    )
    assert not reply.recommendations


def test_recommend_endpoint_uses_same_current_query_understanding():
    reply = make_engine().recommend(RecommendRequest(goal="Me interesa estudiar Python"))
    assert reply.recommendations[0].id == "cat_curso_datos"
    assert reply.recommendations[0].evidence_text


def test_public_php_contract_fixture_is_produced_by_the_current_python_engine():
    fixture = json.loads(
        (Path(__file__).parents[2] / "tests/Fixtures/phase-c-public-replies.json").read_text(encoding="utf-8")
    )
    engine = make_engine(fixture["items"])
    for case in fixture["cases"]:
        assert engine.chat(case["query"]).model_dump() == case["reply"]


@pytest.mark.parametrize(
    "query",
    [
        "Quiero aprender redes empresariales",
        "Necesito contratar analisis de datos con Python",
        "Quiero aprender Python en modalidad presencial",
    ],
)
def test_requested_kind_and_modality_cannot_be_invented(query):
    reply = make_engine().chat(query)
    assert not reply.recommendations


@pytest.mark.parametrize(
    "query",
    [
        "Cuanto cuesta el curso de Python",
        "Cuantos cupos quedan en Python",
        "Cuando empieza el curso de Python",
    ],
)
def test_unpublished_attributes_keep_abstention_ahead_of_recommendations(query):
    reply = make_engine().chat(query)
    assert reply.abstained
    assert not reply.recommendations
    assert reply.missing_data


def test_outdated_catalog_never_returns_a_browse_listing():
    from datetime import timedelta

    engine = make_engine()
    engine.kb.catalog.catalog.expires_at = datetime.now(UTC) - timedelta(seconds=1)
    reply = engine.chat("Que servicios tecnologicos ofrecen")
    assert reply.abstained
    assert reply.sources == []


def test_ambiguous_recommendation_does_not_choose_an_arbitrary_publication():
    items = public_items()
    items.append(dict(items[0], stable_id="curso:otra-edicion"))
    reply = make_engine(items).chat("Quiero aprender analisis de datos")
    assert reply.needs_clarification
    assert not reply.recommendations


def test_parallel_queries_keep_current_interests_isolated():
    from concurrent.futures import ThreadPoolExecutor

    engine = make_engine()
    before = engine.kb.documents
    queries = ["Me interesa estudiar Python", "Busco un servicio de conectividad"] * 8
    with ThreadPoolExecutor(max_workers=4) as pool:
        replies = list(pool.map(engine.chat, queries))
    assert [r.recommendations[0].id for r in replies] == ["cat_curso_datos", "cat_servicio_redes"] * 8
    assert engine.kb.documents is before


def test_query_planning_does_not_encode_the_same_recommendation_twice():
    import numpy as np

    from app.services.semantic_search import SemanticIndex

    class Counter:
        def __init__(self):
            self.calls = 0

        def encode(self, texts):
            self.calls += 1
            return np.tile([1.0, 0.0], (len(texts), 1))

    engine = make_engine()
    embedder = Counter()
    engine.semantic = SemanticIndex(engine.kb.search_records)
    engine.semantic.attach(embedder)
    embedder.calls = 0
    engine.chat("Me interesa estudiar Python")
    assert embedder.calls == 1


def test_real_asgi_catalog_replacement_removes_recommendations_and_old_evidence(tmp_path):
    client, _ = _client(tmp_path)
    headers = {"X-Horus-Service-Token": TEST_TOKEN}
    with client:
        assert (
            client.post(
                "/api/v1/catalog/sync",
                json=_catalog(datetime.now(UTC), items=public_items()),
                headers=headers,
            ).status_code
            == 200
        )
        result = client.post("/api/v1/chat", json={"message": "Me interesa estudiar Python"}, headers=headers)
        assert result.status_code == 200
        assert result.json()["recommendations"][0]["id"] == "cat_curso_datos"
        assert (
            client.post(
                "/api/v1/catalog/sync", json=_catalog(datetime.now(UTC), items=[]), headers=headers
            ).status_code
            == 200
        )
        reply = client.post(
            "/api/v1/chat", json={"message": "Me interesa estudiar Python"}, headers=headers
        ).json()
        assert reply["recommendations"] == []
        assert "Analisis de datos con Python" not in reply["answer"]
