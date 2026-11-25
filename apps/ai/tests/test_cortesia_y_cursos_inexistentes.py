"""Fase 5 (25/11/2026): cursos que no existen y mensajes de cortesía.

Antes: «¿tienen el curso de contabilidad?» pedía aclarar (la palabra se daba por conocida por parecerse a «contacto»),
tras «no tengo ese curso» se seguía hablando del curso anterior, y «listo gracias» listaba todos los cursos.
"""

from __future__ import annotations

import pytest

from tests.test_conversacion_fluida import AUDITORIA_ITEMS
from tests.test_phase_c import make_engine

SIN_TEMA = "un curso o capacitación sobre ese tema"
EN_FOCO = "Comunicacion"
PREGUNTA_CON_FOCO = f"¿Cuál es el horario del curso {EN_FOCO}?"


@pytest.fixture(scope="module")
def motor():
    return make_engine(AUDITORIA_ITEMS)


@pytest.mark.parametrize("pregunta", [
    "¿tienen el curso de contabilidad?",
    "tienen curso de contabilidad",
    "quiero el curso de contabilidad",
    "información del curso de enfermería",
    "hay curso de cocina?",
    "¿cuál es el horario del curso de contabilidad?",
])
def test_un_curso_que_no_esta_publicado_se_reconoce_como_tal(motor, pregunta):
    estado = motor.chat(PREGUNTA_CON_FOCO, [], None).state
    for previo in (None, estado):
        respuesta = motor.chat(pregunta, [], previo)
        assert SIN_TEMA in respuesta.answer, respuesta.answer
        assert respuesta.intent == "unavailable_info"


def test_despues_de_no_tener_un_curso_no_se_sigue_hablando_del_anterior(motor):
    estado = motor.chat(PREGUNTA_CON_FOCO, [], None).state
    respuesta = motor.chat("hay curso de cocina?", [], estado)
    assert respuesta.state.focus_ids == []
    assert respuesta.suggestions == []
    assert EN_FOCO not in motor.chat("¿y cuánto dura?", [], respuesta.state).answer


def test_una_consulta_sin_dato_no_borra_el_curso_en_foco(motor):
    estado = motor.chat(PREGUNTA_CON_FOCO, [], None).state
    assert motor.chat("¿puedo pagar en cuotas?", [], estado).state.focus_ids == estado.focus_ids


@pytest.mark.parametrize("pregunta", [
    "¿tienen el curso de comunicación?", "información del curso de redes", "quiero el curso de ofimática",
])
def test_un_curso_publicado_no_se_da_por_inexistente(motor, pregunta):
    assert SIN_TEMA not in motor.chat(pregunta, [], None).answer


@pytest.mark.parametrize(("mensaje", "intencion"), [
    ("holaa", "greeting"), ("holaaa", "greeting"), ("buen día", "greeting"), ("hola, buen día", "greeting"),
    ("perfecto, muchas gracias", "thanks"), ("listo gracias", "thanks"), ("excelente, gracias", "thanks"),
    ("eres muy amable", "thanks"), ("muy amable", "thanks"),
    ("que tengas buen día", "farewell"), ("que tenga un buen día", "farewell"), ("hasta mañana", "farewell"),
])
def test_cortesia(motor, mensaje, intencion):
    respuesta = motor.chat(mensaje, [], None)
    assert respuesta.intent == intencion, respuesta.answer
    assert respuesta.suggestions == []


@pytest.mark.parametrize(("mensaje", "prohibidas"), [
    ("hola, qué cursos tienen?", {"greeting"}),
    ("gracias, ¿cuál es el horario del curso Comunicacion?", {"thanks"}),
    ("¿atienden hasta mañana?", {"farewell"}),
    ("bien gracias y tú", {"thanks"}),
    ("perfecto, quiero matricularme", {"thanks", "greeting"}),
])
def test_una_consulta_con_cortesia_sigue_siendo_una_consulta(motor, mensaje, prohibidas):
    assert motor.chat(mensaje, [], None).intent not in prohibidas
