"""Banco único de frases de visitantes (también lo lee `ChatFrasesVisitanteTest` en Laravel, con el filtro de privacidad).

Cada frase y cada conversación se prueba sobre el catálogo de `chat-jurado-catalogo.json`; las conversaciones arrastran
el estado turno a turno, como en la web.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

import pytest

from app.services import conversation_glue as glue
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


@pytest.mark.parametrize("regla, mensaje", [
    (glue.asks_level, "quiero aprender excel nivel básico"),
    (glue.asks_recordings, "¿cuándo es el examen del curso grabado?"),
    (glue.asks_with_whom, "con quiénes puedo hablar sobre el pago"),
    (glue.points_at_this, "quiero ese certificado"),
    (glue.numbered_choice, "quiero 2 certificados"),
    (glue.asks_unpublished_study_fact, "curso de computación"),
    (glue.asks_unpublished_study_fact, "necesito reparar mi laptop"),
    (glue.asks_unpublished_study_fact, "capacitación para adultos de mi empresa"),
    (glue.asks_unpublished_study_fact, "¿cuál es el turno de atención?"),
    (glue.asks_class_platform, "¿qué plataforma de pago usan?"),
    (glue.asks_class_platform, "¿cómo son las clases de redes?"),
    (glue.asks_after_enrolling, "no me agregaron al grupo de WhatsApp"),
    (glue.asks_class_days, "falté a la clase práctica, ¿cuándo la recupero?"),
    (glue.is_open_recommendation, "no sé cuál es el horario"),
])
def test_las_reglas_nuevas_no_capturan_otras_preguntas(regla, mensaje):
    assert not regla(mensaje)


@pytest.mark.parametrize("mensaje", ["quiero ese", "1", "prefiero el horario de redes", "mejor virtual"])
def test_tras_hablar_de_convenios_no_se_ofrece_un_curso_por_posicion(motor, mensaje):
    estado = motor.chat("tienen convenios", [], None).state
    assert motor._choose_course(mensaje, [], motor._prior(estado)) is None


def test_con_dos_cursos_el_otro_tras_la_lista_pregunta_cual():
    cursos = json.loads((FIXTURES / "chat-jurado-catalogo.json").read_text(encoding="utf-8"))["items"][:2]
    motor_dos = make_engine(cursos)
    estado = motor_dos.chat("que cursos tienen", [], None).state
    respuesta = motor_dos.chat("y el otro", [], estado)
    assert respuesta.needs_clarification
    assert all(c["title"] in respuesta.clarification_question for c in cursos)


def test_con_un_curso_elegido_los_dias_de_clase_siguen_saliendo_de_su_ficha(motor):
    estado = motor.chat("me interesa redes", [], None).state
    respuesta = motor.chat("¿qué días son las clases presenciales?", [], estado)
    assert [s.id for s in respuesta.sources] != ["cursos_modalidades"], respuesta.answer


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
