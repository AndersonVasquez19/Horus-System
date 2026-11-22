"""Varias opciones publicadas (22/11/2026): la mejor ficha y hasta dos más que también coinciden, cada una con su motivo
y su enlace. El seguimiento («¿cuánto dura?») responde sobre las opciones en foco con sus datos publicados.

apps/api/tests/Fixtures/chat-recomendaciones-varias.json contiene las respuestas exactas que consume
AsistenteRecomendacionesVariasTest.php; la última prueba exige que el motor actual las siga produciendo.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from app.schemas.chat import ConversationState
from tests.test_phase_c import make_engine

FIXTURE = Path(__file__).parents[2] / "api" / "tests" / "Fixtures" / "chat-recomendaciones-varias.json"


@pytest.fixture(scope="module")
def fixture() -> dict:
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


@pytest.fixture(scope="module")
def engine(fixture):
    return make_engine(fixture["items"])


def test_la_mejor_opcion_va_primero_y_las_demas_como_alternativas(engine) -> None:
    reply = engine.chat("Quiero aprender Excel desde cero")
    assert reply.intent == "course_recommendation"
    assert [r.id for r in reply.recommendations] == [
        "cat_curso_publicacion_1", "cat_curso_publicacion_2", "cat_curso_publicacion_3"]
    assert reply.answer.startswith("Excel desde cero:")
    assert reply.clarification_question == (
        "También coinciden con lo que buscas: «Excel para finanzas» y «Excel avanzado con macros». "
        "¿Quieres que te cuente de alguno?")
    for rec in reply.recommendations:
        assert rec.reason and rec.evidence == [rec.id] and rec.url_path == "/educacion/cursos"
    assert {s.id for s in reply.sources} == {r.id for r in reply.recommendations}


def test_sin_otras_coincidencias_se_mantiene_una_sola_opcion(engine) -> None:
    reply = engine.chat("quiero aprender redes")
    assert [r.id for r in reply.recommendations] == ["cat_curso_publicacion_4"]
    assert not reply.needs_clarification and reply.clarification_question is None


def test_una_consulta_ambigua_sigue_preguntando_cual(engine) -> None:
    reply = engine.chat("Quiero aprender Excel")
    assert reply.intent == "clarification" and reply.recommendations == []


def test_otro_tipo_de_ficha_no_se_mezcla(engine) -> None:
    reply = engine.chat("Quiero aprender Excel desde cero")
    assert all(engine.kb.get(r.id).kind == "course" for r in reply.recommendations)


def test_el_seguimiento_responde_sobre_las_opciones_en_foco(engine) -> None:
    primera = engine.chat("Quiero aprender Excel desde cero")
    reply = engine.chat("¿cuánto dura?", [], primera.state)
    assert reply.answer == "«Excel desde cero»: dura 6 semanas.\n«Excel para finanzas»: dura 6 semanas."
    assert [s.id for s in reply.sources] == ["cat_curso_publicacion_1", "cat_curso_publicacion_2"]


def test_las_respuestas_que_consume_laravel_proceden_del_motor_actual(engine, fixture) -> None:
    for case in fixture["cases"]:
        state = ConversationState(**case["state"]) if case.get("state") else None
        assert engine.chat(case["query"], [], state).model_dump() == case["reply"], case["query"]
