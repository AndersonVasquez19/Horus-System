"""Pruebas de visitante del 27/11/2026 (data/oraciones_pruebas_870.json): lo corregido no puede volver a fallar.

El examen sellado (data/oraciones_examen_sellado.json) NO se usa aquí: se mide una sola vez, al final, con
scripts/medir_oraciones.py, para que siga siendo una medición honesta.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest

from app.services import conversation_glue as glue

sys.path.insert(0, str(Path(__file__).parents[1] / "scripts"))
from medir_oraciones import medir, medir_conversaciones, motor  # noqa: E402

BANCO = json.loads((Path(__file__).parents[1] / "data" / "oraciones_pruebas_870.json").read_text(encoding="utf-8"))


@pytest.fixture(scope="module")
def engine():
    return motor()


def test_el_banco_de_870_no_retrocede():
    _, total = medir(BANCO["casos"], con_filtro=False)
    _, turnos = medir_conversaciones(BANCO["conversaciones"], con_filtro=False)
    total += turnos
    # Medición SIN el filtro de Laravel tras la fase 2 (27/11/2026): 725 aciertos y 73 equivocadas (sin filtro, los mensajes
    # con datos personales llegan al motor). Con filtro, scripts/medir_oraciones.py: 642 antes de corregir, 730 después.
    assert total["acierta"] >= 725, total
    assert total["equivocada"] <= 73, total


@pytest.mark.parametrize("mensaje, tipo", [
    ("buen día joven", "greeting"), ("Hola, buenas tardes, espero se encuentre bien", "greeting"), ("hola horus", "greeting"),
    ("buenas tardes señorita", "greeting"), ("habla", "greeting"),
    ("gracias de verdad", "thanks"), ("le agradezco", "thanks"), ("muchísimas gracias", "thanks"), ("se agradece", "thanks"),
    ("Dios te pague", "thanks"), ("ya entendí, gracias", "thanks"),
    ("me despido", "farewell"), ("nos vemos pronto", "farewell"), ("buenas noches, hasta mañana", "farewell"),
    ("me retiro, gracias", "farewell"), ("adiosito", "farewell"),
])
def test_la_cortesia_con_vocativos_y_formulas(engine, mensaje, tipo):
    assert engine.chat(mensaje, [], None).intent == tipo


@pytest.mark.parametrize("mensaje", [
    "gracias, ¿y el horario?", "hola, quiero aprender redes", "habla inglés?", "buenas tardes, ¿cuánto cuesta el curso?",
    "me despido del curso, ¿me devuelven el dinero?",
])
def test_una_consulta_con_cortesia_no_se_toma_como_cortesia(mensaje):
    assert glue.courtesy_kind(mensaje) is None


@pytest.mark.parametrize("mensaje", [
    "Muito obrigado", "Bom dia", "Onde fica o escritório?", "Merci", "Grazie mille", "Rimaykullayki", "Thanks a lot!",
    "What are your opening hours?", "I want to talk to a human", "hola, what time do you open?",
])
def test_otros_idiomas_reciben_el_aviso_de_que_solo_responde_en_espanol(engine, mensaje):
    respuesta = engine.chat(mensaje, [], None)
    assert respuesta.intent == "acknowledgement"
    assert "Spanish" in respuesta.answer or "solo puedo responder en español" in respuesta.answer


@pytest.mark.parametrize("mensaje", ["por curso", "a dia", "quiero estudiar Excel", "cuanto custa el curso"])
def test_palabras_parecidas_en_espanol_no_disparan_el_aviso_de_idioma(mensaje):
    assert glue.classify(mensaje) not in {"foreign", "english"}


@pytest.mark.parametrize("mensaje", ["me tienes harto", "deja de repetir lo mismo", "nadie me ayuda", "respóndeme bien pues"])
def test_la_frustracion_con_el_asistente_se_responde_con_calma(engine, mensaje):
    respuesta = engine.chat(mensaje, [], None)
    assert respuesta.intent == "acknowledgement" and not respuesta.sources


@pytest.mark.parametrize("mensaje", ["¿hablo con una máquina?", "¿eres un asistente virtual?", "¿qué tipo de preguntas respondes?"])
def test_preguntas_sobre_el_asistente(engine, mensaje):
    assert engine.chat(mensaje, [], None).intent == "capabilities"


@pytest.mark.parametrize("mensaje", ["okis", "bueno", "no me entiendes"])  # «dale», «ajá»: el filtro los bloquea a propósito
def test_acuses_cortos(engine, mensaje):
    assert engine.chat(mensaje, [], None).intent == "acknowledgement"


def _conversar(engine, *turnos):
    estado, respuestas = None, []
    for turno in turnos:
        respuesta = engine.chat(turno, [], estado)
        estado = respuesta.state
        respuestas.append(respuesta)
    return respuestas


@pytest.mark.parametrize("primero, ficha, fecha", [
    ("Hola, ¿tienen convenio con el Colegio de Economistas?", "convenio_economia", "6 de febrero del 2027"),
    ("¿Tienen convenio con CORLAD?", "convenio_administracion", "10 de diciembre del 2026"),
])
def test_hasta_cuando_con_un_convenio_en_contexto_da_su_vigencia(engine, primero, ficha, fecha):
    respuesta = _conversar(engine, primero, "¿y hasta cuándo es?")[-1]
    assert [s.id for s in respuesta.sources] == [ficha]
    assert fecha in respuesta.answer


def test_hasta_cuando_tras_el_horario_sigue_siendo_el_horario(engine):
    respuesta = _conversar(engine, "¿A qué hora atienden?", "¿hasta cuándo?")[-1]
    assert [s.id for s in respuesta.sources] == ["horario_atencion"]


@pytest.mark.parametrize("turnos, ficha", [
    (("¿Dan certificado?", "perdí el mío"), "faq_certificado_perdido"),
    (("quiero pedir una devolución", "¿dónde presento la solicitud?"), "politica_devoluciones"),
    (("¿Hacen capacitaciones para empresas?", "somos 15 trabajadores"), "capacitaciones_empresariales"),
])
def test_un_seguimiento_corto_se_queda_en_el_tema(engine, turnos, ficha):
    respuesta = _conversar(engine, *turnos)[-1]
    assert not respuesta.needs_clarification
    assert ficha in [s.id for s in respuesta.sources]


def test_sin_contexto_el_seguimiento_corto_sigue_pidiendo_aclaracion(engine):
    assert engine.chat("perdí el mío", [], None).needs_clarification
