"""Banco único de frases de visitantes (también lo lee `ChatFrasesVisitanteTest` en Laravel, con el filtro de privacidad).

Cada frase y cada conversación se prueba sobre el catálogo de `chat-jurado-catalogo.json`; las conversaciones arrastran
el estado turno a turno, como en la web.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

import pytest

from tests.test_phase_c import make_engine

FIXTURES = Path(__file__).parents[2] / "api" / "tests" / "Fixtures"
BANCO = json.loads((FIXTURES / "chat-frases-visitante.json").read_text(encoding="utf-8"))


@pytest.fixture(scope="module")
def motor():
    return make_engine(json.loads((FIXTURES / "chat-jurado-catalogo.json").read_text(encoding="utf-8"))["items"])


def _comprobar(turno, respuesta):
    assert respuesta.intent == turno["intencion"], f"{turno['texto']}: {respuesta.intent} · {respuesta.answer}"
    if "cursos" in turno:
        citados = [s.title for s in respuesta.sources if s.id.startswith("cat_curso_")]
        assert citados == turno["cursos"], f"{turno['texto']}: {respuesta.answer}"


@pytest.mark.parametrize("frase", BANCO["frases"], ids=lambda f: f["texto"])
def test_frase(motor, frase):
    _comprobar(frase, motor.chat(frase["texto"], [], None))


@pytest.mark.parametrize("turnos", BANCO["conversaciones"], ids=lambda c: c[0]["texto"])
def test_conversacion(motor, turnos):
    estado = None
    for turno in turnos:
        respuesta = motor.chat(turno["texto"], [], estado)
        _comprobar(turno, respuesta)
        estado = respuesta.state


def _respuestas(motor):
    """Cada frase y cada turno con lo que responde el motor hoy: `HorusJuradoTest` comprueba que Laravel lo acepte."""
    casos, vistos = [], set()
    for turnos in [[f] for f in BANCO["frases"]] + BANCO["conversaciones"]:
        estado = None
        for turno in turnos:
            respuesta = motor.chat(turno["texto"], [], estado)
            previo = estado.model_dump(mode="json") if estado else None
            clave = json.dumps([turno["texto"], previo], sort_keys=True)
            if clave not in vistos:
                vistos.add(clave)
                casos.append({"query": turno["texto"], "state": previo, "reply": respuesta.model_dump(mode="json")})
            estado = respuesta.state
    return casos


def test_las_respuestas_guardadas_para_laravel_salen_del_motor_actual(motor):
    ruta = FIXTURES / "chat-frases-replies.json"
    casos = _respuestas(motor)
    if os.environ.get("REGENERAR_BANCO") == "1":
        ruta.write_text(json.dumps(casos, ensure_ascii=False, indent=1) + "\n", encoding="utf-8", newline="\r\n")
    assert json.loads(ruta.read_text(encoding="utf-8")) == casos, "Regenera con REGENERAR_BANCO=1"
