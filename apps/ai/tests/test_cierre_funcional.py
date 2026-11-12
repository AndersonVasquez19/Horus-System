"""Cierre funcional (05/11/2026): consultas naturales que antes pedían una aclaración innecesaria.

Catálogo ficticio publicado; sin servidores, sin red y sin reservas restringidas. Cada consulta se
midió antes del cambio (aclaración genérica o abstención) y se fija aquí como regresión.
"""

import json
from datetime import UTC, datetime
from pathlib import Path

import pytest

from app.core.security import detect_prompt_injection
from tests.test_phase_c import make_engine, public_items


def _items():
    now = datetime.now(UTC).isoformat()
    return public_items() + [
        {
            "stable_id": "curso:excel",
            "type": "curso",
            "publication_status": "vigente",
            "title": "Excel empresarial",
            "summary": "Aprende Excel con tablas dinamicas y formulas.",
            "details": "Formulas, tablas dinamicas y graficos en Excel.",
            "keywords": ["Excel", "hojas de calculo"],
            "modalities": ["presencial"],
            "updated_at": now,
        },
        {
            "stable_id": "curso:prog",
            "type": "curso",
            "publication_status": "vigente",
            "title": "Programacion con Python",
            "summary": "Aprende programacion desde cero con Python.",
            "details": "Variables, funciones y estructuras de control.",
            "keywords": ["programacion", "Python"],
            "modalities": ["virtual"],
            "updated_at": now,
        },
        {
            "stable_id": "servicio:cableado",
            "type": "servicio",
            "publication_status": "vigente",
            "title": "Cableado estructurado",
            "summary": "Instalacion de cableado estructurado para oficinas.",
            "details": "Diseno, instalacion y certificacion de puntos de red.",
            "keywords": ["cableado estructurado", "puntos de red"],
            "updated_at": now,
        },
        {
            "stable_id": "servicio:mant",
            "type": "servicio",
            "publication_status": "vigente",
            "title": "Soporte y mantenimiento",
            "summary": "Mantenimiento preventivo y correctivo de computadoras.",
            "details": "Limpieza, diagnostico y reparacion de equipos de computo.",
            "keywords": ["mantenimiento de computadoras", "soporte tecnico"],
            "updated_at": now,
        },
    ]


@pytest.fixture(scope="module")
def engine():
    return make_engine(_items())


@pytest.mark.parametrize(
    "query",
    [
        "¿Qué cursos están relacionados con análisis de datos?",
        "¿Tienen capacitaciones relacionadas con análisis de datos?",
        "Busco algo relacionado a analisis de datos",
    ],
)
def test_related_to_phrasing_recommends_the_published_course(engine, query):
    reply = engine.chat(query)
    assert reply.intent == "course_recommendation"
    assert [r.id for r in reply.recommendations] == ["cat_curso_datos"]


def test_long_conversational_query_keeps_the_published_key_phrase(engine):
    reply = engine.chat(
        "Hola buenas tardes, quisiera saber si ustedes tienen algun curso o capacitacion que me sirva "
        "para aprender a manejar hojas de calculo porque en mi trabajo me lo piden"
    )
    assert [r.id for r in reply.recommendations] == ["cat_curso_excel"]
    assert reply.answer == engine.kb.get("cat_curso_excel").answer


@pytest.mark.parametrize(
    ("query", "listing"),
    [
        ("¿Qué capacitaciones tienen para empresas?", "cat_listado_formacion"),
        ("¿Qué servicios tienen para empresas?", "cat_listado_servicios"),
    ],
)
def test_audience_only_questions_use_the_current_listing(engine, query, listing):
    reply = engine.chat(query)
    assert [s.id for s in reply.sources] == [listing]
    assert reply.recommendations == []


@pytest.mark.parametrize(
    "query",
    ["¿Tienen curso de cocina?", "Quiero aprender reposteria", "¿Tienen un servicio de jardineria?"],
)
def test_unpublished_topic_is_recognised_without_echo_or_invented_offer(engine, query):
    reply = engine.chat(query)
    assert reply.intent == "unavailable_info"
    assert reply.abstained is True
    assert reply.sources == [] and reply.recommendations == []
    lowered = reply.answer.lower()
    for word in ("cocina", "reposteria", "jardineria"):
        assert word not in lowered
    assert "catálogo vigente" in reply.answer


def test_unpublished_topic_reply_is_deterministic(engine):
    assert engine.chat("¿Tienen curso de cocina?").answer == engine.chat("¿Tienen curso de cocina?").answer


@pytest.mark.parametrize(
    ("query", "expected"),
    [
        (
            "Quiero aprender Excel y también necesito cableado para mi oficina",
            ["cat_curso_excel", "cat_servicio_cableado"],
        ),
        (
            "Necesito cableado estructurado y mantenimiento de computadoras",
            ["cat_servicio_cableado", "cat_servicio_mant"],
        ),
    ],
)
def test_multi_topic_query_returns_one_published_option_per_topic(engine, query, expected):
    reply = engine.chat(query)
    assert [r.id for r in reply.recommendations] == expected
    assert [s.id for s in reply.sources] == expected
    # El texto afirmado es exactamente el de la primera ficha; la segunda solo se nombra por su título.
    assert reply.answer == engine.kb.get(expected[0]).answer
    assert reply.needs_clarification is True
    assert engine.kb.get(expected[1]).title in reply.clarification_question


def test_multi_topic_with_an_ambiguous_part_asks_instead_of_choosing(engine):
    reply = engine.chat("me interesa python y excel")
    assert reply.intent == "clarification"
    assert reply.recommendations == []
    assert "Analisis de datos con Python" in reply.clarification_question
    assert "Programacion con Python" in reply.clarification_question


def test_single_topic_with_conjunction_inside_the_topic_is_not_split(engine):
    reply = engine.chat("Quiero aprender limpieza de datos y graficos")
    assert [r.id for r in reply.recommendations] == ["cat_curso_datos"]
    assert reply.needs_clarification is False


@pytest.mark.parametrize("query", ["¿Dónde puedo encontrar más información?", "Quiero más información"])
def test_more_information_points_to_the_official_contact_record(engine, query):
    reply = engine.chat(query)
    assert reply.intent == "contact_info"
    assert [s.id for s in reply.sources] == ["contacto_canales"]
    assert reply.answer == engine.kb.get("contacto_canales").answer


def test_php_contract_fixture_is_produced_by_the_current_python_engine():
    fixture = json.loads(
        (Path(__file__).parents[2] / "tests/Fixtures/cierre-funcional-replies.json").read_text(
            encoding="utf-8"
        )
    )
    engine = make_engine(fixture["items"])
    for case in fixture["cases"]:
        assert engine.chat(case["query"]).model_dump() == case["reply"], case["query"]


@pytest.mark.parametrize(
    "query",
    [
        "${jndi:ldap://x.example/a}",
        "{{7*7}} ¿qué cursos tienen?",
        "#{7*7}",
        "'; DROP TABLE cursos; --",
        "' OR 1=1 --",
        "<img src=x onerror=alert(1)>",
    ],
)
def test_code_injection_probes_are_treated_as_manipulation(query):
    assert detect_prompt_injection(query)


@pytest.mark.parametrize(
    "query",
    [
        "Quiero aprender programación en Python",
        "¿Tienen curso de SQL?",
        "Necesito instalar cámaras de seguridad",
        "¿El curso tiene certificado?",
        "Mi computadora tiene virus, ¿la pueden revisar?",
    ],
)
def test_legitimate_technical_questions_are_not_injection(query):
    assert not detect_prompt_injection(query)
