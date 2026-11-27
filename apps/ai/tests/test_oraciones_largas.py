"""Oraciones largas: el conjunto de desarrollo no puede volver a dar respuestas equivocadas.

El examen (data/oraciones_examen.json) no se usa aquí: solo se mide con scripts/medir_oraciones.py, para que siga
siendo una medición honesta de oraciones que el motor no vio al ajustarse.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest

from app.services import conversation_glue as glue

sys.path.insert(0, str(Path(__file__).parents[1] / "scripts"))
from medir_oraciones import medir, medir_conversaciones, motor  # noqa: E402

DESARROLLO = json.loads((Path(__file__).parents[1] / "data" / "oraciones_desarrollo.json").read_text(encoding="utf-8"))


def test_el_conjunto_de_desarrollo_no_tiene_respuestas_equivocadas():
    filas, total = medir(DESARROLLO["casos"], con_filtro=False)
    equivocadas = [(f["texto"], f["fuentes"]) for f in filas if f["resultado"] == "equivocada"]
    assert equivocadas == []
    assert total["acierta"] >= 52, total  # medición del 26/11/2026; si baja, algo se rompió


@pytest.mark.parametrize("regla, mensaje", [
    (glue.asks_discarded_service, "quiero aprender a instalar cámaras en un curso"),
    (glue.asks_discarded_service, "me interesa el mantenimiento de computadoras"),
    (glue.asks_certificate_validity, "¿el certificado tiene validez internacional?"),
    (glue.asks_certificate_validity, "perdí mi certificado, ¿me dan un duplicado?"),
    (glue.asks_missed_class, "¿qué días son las clases prácticas?"),
    (glue.asks_privacy, "¿qué datos necesito para matricularme?"),
    (glue.asks_how_to_enroll, "¿cuánto cuesta el curso de redes?"),
])
def test_las_reglas_de_oraciones_largas_no_capturan_otras_preguntas(regla, mensaje):
    assert not regla(mensaje)


@pytest.mark.parametrize("mensaje", [
    "Hacen el cableado de red de mi oficina nueva, cuánto me cobrarían",
    "tambien reparan computadoras porque una de mis maquinas esta muy lenta",
])
def test_los_servicios_retirados_no_se_ofrecen_ni_se_nombran(engine, mensaje):
    respuesta = engine.chat(mensaje)
    assert respuesta.abstained and not respuesta.sources
    assert not any(p in respuesta.answer.lower() for p in ("cámara", "camara", "cableado", "soporte técnico", "reparación"))


# ---------------------------------------------------------------- fase 3 (26/11/2026): simulación de visitante
VISITANTE = json.loads((Path(__file__).parents[1] / "data" / "oraciones_visitante.json").read_text(encoding="utf-8"))


def test_la_simulacion_de_visitante_no_retrocede():
    _, sueltas = medir(VISITANTE["casos"], con_filtro=False)
    _, turnos = medir_conversaciones(VISITANTE["conversaciones"], con_filtro=False)
    total = sueltas + turnos
    # Medición del 26/11/2026 sin el filtro de Laravel: 194 de 225 y 9 equivocadas (antes de la fase 3: 64 % y 31).
    assert total["acierta"] >= 194, total
    assert total["equivocada"] <= 9, total


@pytest.fixture(scope="module")
def visita():
    return motor()


def _conversar(m, *turnos):
    estado, respuesta = None, None
    for texto in turnos:
        respuesta = m.chat(texto, [], estado)
        estado = respuesta.state
    return respuesta


def test_el_si_confirma_la_opcion_de_una_aclaracion(visita):
    primera = visita.chat("¿Tienen fotos de sus eventos o capacitaciones anteriores para ver cómo trabajan?", [], None)
    if primera.needs_clarification:  # si un día responde directo, no hay nada que confirmar
        respuesta = visita.chat("si", [], primera.state)
        assert [s.id for s in respuesta.sources] == ["galeria_institucional"]
        assert not respuesta.needs_clarification


@pytest.mark.parametrize("mensaje", [
    "Quisiera acercarme en persona a su oficina, ¿cuál es la dirección?",  # prueba del propietario: daba un curso
    "Disculpe, ¿en qué parte de Cajamarca están ubicados? Soy de provincia",
    "donde queda su local? quiero ir a preguntar personalmente",
])
def test_la_ubicacion_dicha_de_muchas_formas(visita, mensaje):
    assert [s.id for s in visita.chat(mensaje, [], None).sources] == ["ubicacion_sede"]


def test_inscribirse_en_un_curso_da_los_pasos_y_deja_el_curso_en_foco(visita):
    respuesta = visita.chat("Quiero inscribirme en el curso de Comunicación", [], None)
    assert respuesta.intent == "enrollment_request"
    assert respuesta.answer.startswith("Para matricularte sigue estos pasos")
    assert "+51 972 021 374" in respuesta.answer  # la web muestra el botón de WhatsApp
    assert {s.id for s in respuesta.sources} == {"cat_curso_publicacion_9", "proceso_matricula_pasos"}


def test_el_horario_del_whatsapp_es_el_de_atencion(visita):
    respuesta = visita.chat("¿En qué horario responden por WhatsApp? Les escribí en la noche y nadie contestó", [], None)
    assert [s.id for s in respuesta.sources] == ["horario_atencion"]
    assert respuesta.answer.startswith("Atendemos de lunes a viernes")


def test_la_recomendacion_abierta_dentro_de_una_oracion(visita):
    respuesta = visita.chat("Soy secretaria y quiero mejorar en mi trabajo, ¿qué curso me recomiendan?", [], None)
    assert respuesta.intent == "course_recommendation" and respuesta.needs_clarification
    assert "Ofimática para oficinas" in (respuesta.clarification_question or "")


@pytest.mark.parametrize("mensaje, no_debe", [
    ("¿Dónde queda la plaza de armas de Cajamarca?", "ubicacion_sede"),
    ("¿Venden celulares de segunda?", "contacto_canales"),  # «celular» está en dos fichas: no basta
    ("¿Me ayudas con mi tarea de matemáticas?", "faq_medios_pago"),  # «tarea» ya no se corrige a «tarjeta»
    ("quiero aprender redes desde cero", "formacion_personalizada"),  # gana el curso de redes
])
def test_las_reglas_de_la_fase_3_no_capturan_otras_preguntas(visita, mensaje, no_debe):
    respuesta = visita.chat(mensaje, [], None)
    assert no_debe not in {s.id for s in respuesta.sources} | {r.id for r in respuesta.recommendations}


def test_con_quien_hablo_solo_sigue_preguntando_por_el_asistente(visita):
    assert visita.chat("¿Con quién hablo?", [], None).intent == "capabilities"
    assert visita.chat("Soy de una municipalidad y queremos firmar un convenio, ¿con quién hablo?", [], None).intent == "human_handoff"
