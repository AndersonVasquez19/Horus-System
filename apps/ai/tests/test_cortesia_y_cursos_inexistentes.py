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
    # No se sigue hablando solo del curso anterior: o no se elige ninguno o se muestran todos.
    assert len(motor.chat("¿y cuánto dura?", [], respuesta.state).sources) > 1


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


@pytest.mark.parametrize(("pregunta", "dato"), [
    ("¿cuánto dura?", "dura "),
    ("¿Y cuánto dura?", "dura "),
    ("y cuanto duran", "dura "),
    ("¿cuál es la duración?", "dura "),
    ("cuántas semanas dura", "dura "),
    ("¿cuándo empiezan?", "el horario es"),
    ("¿Y cuándo inicia?", "el horario es"),
])
def test_duracion_o_inicio_sin_curso_elegido_muestra_lo_publicado(motor, pregunta, dato):
    anterior = motor.chat("hay curso de cocina?", [], motor.chat(PREGUNTA_CON_FOCO, [], None).state).state
    for previo in (None, anterior):
        respuesta = motor.chat(pregunta, [], previo)
        assert respuesta.intent == "followup_attribute", respuesta.answer
        assert "No tengo un calendario" not in respuesta.answer
        assert dato in respuesta.answer and f"«{EN_FOCO}»" in respuesta.answer
        assert len(respuesta.sources) > 1  # todos los publicados, sin elegir uno por la persona


@pytest.mark.parametrize("pregunta", [
    "¿cuánto dura el soporte técnico?",
    "¿cuánto dura la instalación de cámaras?",
    "¿cuánto dura la garantía?",
    "¿cuánto dura el convenio?",
    "¿cuándo empiezan a atender?",
    "¿cuál es el horario?",
])
def test_la_duracion_de_otra_cosa_no_devuelve_datos_de_los_cursos(motor, pregunta):
    respuesta = motor.chat(pregunta, [], None)
    assert not (respuesta.intent == "followup_attribute" and len(respuesta.sources) > 1), respuesta.answer


def test_duracion_con_un_curso_elegido_responde_solo_ese(motor):
    estado = motor.chat(PREGUNTA_CON_FOCO, [], None).state
    respuesta = motor.chat("¿y cuánto dura?", [], estado)
    assert [s.title for s in respuesta.sources] == [EN_FOCO]


def test_sin_catalogo_se_mantiene_la_respuesta_de_que_no_hay_calendario():
    assert "No tengo un calendario" in make_engine([]).chat("¿cuánto dura?", [], None).answer


TODOS = ["Ofimática para oficinas", "Redes para principiantes", EN_FOCO]


def _conversar(motor, preguntas):
    estado, respuesta = None, None
    for pregunta in preguntas:
        respuesta = motor.chat(pregunta, [], estado)
        estado = respuesta.state
    return respuesta


@pytest.mark.parametrize("siguiente", [
    "¿Cuándo empiezan?", "¿Cuál es el horario?", "¿y el horario?", "¿Cuál es la modalidad?", "¿y cuánto duran?",
])
@pytest.mark.parametrize("lista", ["¿cuánto duran los cursos?", "¿Y cuánto dura?", "¿Qué cursos ofrecen?"])
def test_tras_una_lista_de_todos_los_cursos_no_se_pierde_ninguno(motor, lista, siguiente):
    respuesta = _conversar(motor, [lista, siguiente])
    assert [s.title for s in respuesta.sources] == TODOS, respuesta.answer


@pytest.mark.parametrize("otra", [
    "¿Cuánto dura el soporte técnico?", "¿cuánto dura la garantía?", "¿cuánto dura el convenio?",
    "¿cuánto dura la asesoría?", "¿cuánto dura la instalación de cámaras?", "¿cuánto dura el mantenimiento?",
])
@pytest.mark.parametrize("antes", [PREGUNTA_CON_FOCO, "¿cuánto duran los cursos?"])
def test_la_duracion_de_otra_cosa_no_se_responde_con_el_curso_anterior(motor, antes, otra):
    respuesta = _conversar(motor, [antes, otra])
    assert not {s.title for s in respuesta.sources} & set(TODOS), respuesta.answer


@pytest.mark.parametrize("seguimiento", [
    "¿y cuánto dura?", "¿y ese curso cuánto dura?", "cuánto dura el taller", "¿y cuánto duran las clases?",
])
def test_el_seguimiento_del_curso_en_foco_se_mantiene(motor, seguimiento):
    assert [s.title for s in _conversar(motor, [PREGUNTA_CON_FOCO, seguimiento]).sources] == [EN_FOCO]


def test_tras_ordenar_por_duracion_se_recuerda_ese_orden_y_no_el_del_catalogo(motor):
    orden = motor.chat("¿cuál es el curso más corto?", [], None)
    titulos = [s.title for s in orden.sources]
    assert titulos != TODOS
    assert orden.state.focus_ids == [s.id for s in orden.sources][:2]  # «el primero» sigue siendo el de la respuesta
