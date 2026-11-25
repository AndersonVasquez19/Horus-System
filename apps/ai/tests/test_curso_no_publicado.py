"""Nombrar un curso que no está publicado (25/11/2026).

Con «Redes para principiantes» en foco, «¿cuál es el horario del curso de cocina?» respondía con el horario de Redes.
La regla solo actúa cuando la palabra nombrada no aparece en ningún contenido publicado.
"""

from __future__ import annotations

import pytest

from tests.test_conversacion_fluida import AUDITORIA_ITEMS
from tests.test_phase_c import make_engine

EN_FOCO = "Redes para principiantes"
SIN_TEMA = "un curso o capacitación sobre ese tema"


@pytest.fixture(scope="module")
def motor():
    return make_engine(AUDITORIA_ITEMS)


def con_foco(motor, pregunta: str):
    estado = motor.chat(f"¿Cuál es el horario del curso {EN_FOCO}?", [], None).state
    return motor.chat(pregunta, [], estado)


@pytest.mark.parametrize("pregunta", [
    "¿cuál es el horario del curso de cocina?",
    "¿cuánto cuesta el taller de oratoria?",
    "¿cuánto dura el curso de panadería?",
    "¿el curso de cocina es virtual?",
])
def test_un_curso_no_publicado_no_responde_con_el_curso_en_foco(motor, pregunta):
    respuesta = con_foco(motor, pregunta)
    assert SIN_TEMA in respuesta.answer, respuesta.answer
    assert EN_FOCO not in respuesta.answer
    assert respuesta.intent == "unavailable_info"


def test_preguntar_si_existe_no_se_contesta_con_el_curso_en_foco(motor):
    assert EN_FOCO not in con_foco(motor, "¿tienen el curso de contabilidad?").answer


def test_tambien_sin_curso_en_foco(motor):
    assert SIN_TEMA in motor.chat("¿cuál es el horario del curso de cocina?", [], None).answer


@pytest.mark.parametrize("pregunta", [
    "¿cuál es el horario del curso?",
    "¿este curso es virtual?",
    "¿cuánto dura el curso?",
    "¿el curso tiene certificado?",
    "quiero matricularme",
    "quiero matricularme en este curso",
    "quiero inscribirme en línea",
    "como me inscribo en Python",  # la matrícula sigue yendo al WhatsApp (decisión anterior)
    "quiero matricularme en el curso de redes",
    "¿cuál es el horario del curso de Comunicacion?",
    "¿cómo puedo matricularme en un curso?",
])
def test_las_preguntas_sobre_cursos_publicados_o_en_foco_no_cambian(motor, pregunta):
    assert SIN_TEMA not in con_foco(motor, pregunta).answer


def test_sin_catalogo_no_se_afirma_que_un_curso_no_existe():
    assert SIN_TEMA not in make_engine([]).chat("¿cuál es el horario del curso de cocina?", [], None).answer
