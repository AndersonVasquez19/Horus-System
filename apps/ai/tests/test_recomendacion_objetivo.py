"""Recomendación por objetivo (27/11/2026): la meta del visitante lleva al único curso publicado que la trata."""

from __future__ import annotations

import json

import pytest

from app.core.config import PROJECT_ROOT
from app.services.chat_engine import AdvisorEngine
from tests.test_phase_c import make_engine

CATALOG = PROJECT_ROOT.parent / "api" / "tests" / "Fixtures" / "chat-jurado-catalogo.json"


@pytest.fixture(scope="module")
def engine() -> AdvisorEngine:
    return make_engine(json.loads(CATALOG.read_text(encoding="utf-8"))["items"])


@pytest.mark.parametrize(("query", "course"), [
    ("Me cuesta hablar en público, ¿algún curso?", "Comunicacion"),
    ("Soy docente y quiero comunicarme mejor con mis alumnos", "Comunicacion"),
    ("tengo miedo de hablar en público", "Comunicacion"),
    ("Quiero ser técnico de redes", "Redes para principiantes"),
    ("Me interesan los routers y switches", "Redes para principiantes"),
    ("No sé nada de computadoras, ¿por dónde empiezo?", "Ofimática para oficinas"),
    ("Soy administrador y quiero dominar Excel", "Ofimática para oficinas"),
])
def test_la_meta_recomienda_el_curso_publicado(engine: AdvisorEngine, query: str, course: str) -> None:
    reply = engine.chat(query)
    assert reply.intent == "course_recommendation"
    assert [r.title for r in reply.recommendations] == [course]
    record = next(r for r in engine.kb.records if r.title == course)
    assert reply.answer == record.answer  # texto exacto de la ficha publicada


@pytest.mark.parametrize("query", [
    "Quiero inscribirme en el curso de Comunicación",  # pide los pasos de matrícula
    # Capacitación para empresas (banco de 870 casos).
    "Trabajo en una empresa minera y estamos buscando capacitar a nuestro personal administrativo en ofimática, "
    "¿ustedes pueden venir a dictar el curso a nuestras instalaciones o tendría que ser en su local?",
    "mi router no funciona",  # un problema, no una meta de aprendizaje
    "quiero aprender redes sociales",
])
def test_no_es_una_recomendacion_por_objetivo(engine: AdvisorEngine, query: str) -> None:
    assert engine.chat(query).intent != "course_recommendation"


def test_sin_catalogo_vigente_no_recomienda_cursos() -> None:
    reply = make_engine([]).chat("Me cuesta hablar en público, ¿algún curso?")
    assert not reply.recommendations
