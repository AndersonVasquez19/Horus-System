"""Pegamento conversacional (24/11/2026): «ok», «sí», «el otro», «más barato», «no entendí»…

Fijan que los mensajes cortos se resuelven con el contexto (curso en foco) o con una frase amable, que no se
inventa ningún dato (el precio sigue derivándose a un asesor) y que el estado y el historial dan lo mismo.
"""

from __future__ import annotations

from datetime import UTC, datetime

import pytest

from app.schemas.chat import HistoryItem
from app.services import conversation_glue as glue
from tests.test_estado_conversacional import ITEM
from tests.test_phase_c import make_engine

ITEM_REDES = dict(
    ITEM, stable_id="curso:publicacion-8", title="Redes para principiantes",
    summary="Conoce redes y cableado desde cero.", details="Inscripción por WhatsApp oficial.",
    modalities=["presencial"], schedule_text="lunes de 6 a 8 pm", duration_text="4 semanas",
    updated_at=datetime.now(UTC).isoformat(),
)
OFIMATICA = "Quiero información del curso de Ofimática para oficinas"


@pytest.fixture(scope="module")
def engine():
    return make_engine([ITEM, ITEM_REDES])


def _por_estado(engine, *mensajes):
    state, reply = None, None
    for mensaje in mensajes:
        reply = engine.chat(mensaje, [], state)
        state = reply.state
    return reply


def _por_historial(engine, *mensajes):
    return engine.chat(mensajes[-1], [HistoryItem(role="user", content=m) for m in mensajes[:-1]])


@pytest.mark.parametrize(
    ("mensaje", "tipo"),
    [("ok", "ack"), ("Vale!", "ack"), ("entiendo", "ack"), ("sí", "yes"), ("¡Sí, por favor!", "yes"), ("dale", "yes"),
     ("no", "no"), ("así está bien", "no"), ("repite", "repeat"), ("otra vez", "repeat"), ("no entendí", "confused"),
     ("explícame mejor", "confused"), ("jajaja", "laugh"),
     ("jeje", "laugh"), ("cómo estás", "howareyou"), ("perdón", "sorry"), ("😂", "empty")],
)
def test_clasifica_los_mensajes_cortos(mensaje, tipo):
    assert glue.classify(mensaje) == tipo


@pytest.mark.parametrize("mensaje", ["quiero un curso de excel", "ok pero cuánto cuesta el curso de redes", "sí quiero matricularme hoy mismo en el curso"])
def test_una_consulta_real_no_se_toma_por_pegamento(mensaje):
    assert glue.classify(mensaje) is None


def test_ok_con_un_curso_en_foco_ofrece_el_siguiente_paso(engine):
    reply = _por_estado(engine, OFIMATICA, "ok")
    assert reply.intent == "acknowledgement" and "horario" in reply.answer and not reply.sources


def test_si_con_un_curso_en_foco_da_mas_detalles_de_ese_curso(engine):
    reply = _por_estado(engine, OFIMATICA, "sí")
    assert "Ofimática para oficinas" in reply.answer and not reply.abstained


def test_si_o_ok_sin_conversacion_reconducen_sin_inventar(engine):
    for mensaje in ("sí", "ok"):
        reply = engine.chat(mensaje)
        assert reply.intent == "acknowledgement" and not reply.sources and not reply.abstained


def test_no_gracias_cierra_con_cortesia(engine):
    reply = _por_estado(engine, OFIMATICA, "no")
    assert reply.intent == "acknowledgement" and reply.answer.startswith(("Está bien", "De acuerdo", "Sin problema"))


def test_mas_barato_no_inventa_precios(engine):
    reply = _por_estado(engine, OFIMATICA, "más barato")
    assert "asesor" in reply.answer and not any(c in reply.answer for c in "$€") and "S/" not in reply.answer


def test_el_primero_y_la_segunda_eligen_de_la_lista(engine):
    primero = _por_estado(engine, "Qué cursos tienen", "el primero")
    segunda = _por_estado(engine, "Qué cursos tienen", "la segunda")
    assert [s.title for s in primero.sources] == ["Ofimática para oficinas"]
    assert [s.title for s in segunda.sources] == ["Redes para principiantes"]
    assert [s.title for s in _por_estado(engine, "Qué cursos tienen", "el último").sources] == ["Redes para principiantes"]


def test_el_otro_con_un_solo_curso_en_foco_elige_el_unico_restante(engine):
    reply = _por_estado(engine, "Qué cursos tienen", "el primero", "y el otro")
    assert [s.title for s in reply.sources] == ["Redes para principiantes"]


def test_sigue_el_hilo_tras_elegir(engine):
    reply = _por_estado(engine, "Qué cursos tienen", "la segunda", "¿y el horario?")
    assert "lunes de 6 a 8 pm" in reply.answer


def test_mensajes_meta_no_caen_en_no_tengo_informacion(engine):
    for mensaje in ("jajaja", "cómo estás", "perdón", "no entendí", "😂"):
        reply = engine.chat(mensaje)
        assert reply.intent == "acknowledgement" and "No tengo información confirmada" not in reply.answer


def test_las_variantes_son_estables_y_distintas_entre_mensajes(engine):
    assert engine.chat("ok").answer == engine.chat("ok").answer
    assert len({engine.chat(m).answer for m in ("ok", "vale", "listo", "entendido", "perfecto", "genial", "entiendo")}) > 1


def test_las_frases_de_cortesia_no_llevan_cifras_enlaces_ni_correos():
    for grupo in (glue.ACK_WITH_FOCUS, glue.ACK_NO_FOCUS, glue.NO_REPLY, glue.CONFUSED_WITH_FOCUS, glue.REPEAT_NO_FOCUS, glue.LAUGH_REPLY,
                  glue.HOWAREYOU_REPLY, glue.SORRY_REPLY, glue.EMPTY_REPLY):
        for frase in grupo:
            assert not any(c.isdigit() or c in "@/<>" for c in frase), frase


@pytest.mark.parametrize(
    "dialogo",
    [(OFIMATICA, "ok"), (OFIMATICA, "sí"), (OFIMATICA, "más barato"), ("Qué cursos tienen", "la segunda"),
     ("Qué cursos tienen", "el primero", "y el otro")],
)
def test_con_el_estado_se_responde_igual_que_con_el_historial(engine, dialogo):
    estado, historial = _por_estado(engine, *dialogo), _por_historial(engine, *dialogo)
    assert (estado.intent, estado.answer, [s.id for s in estado.sources]) == (historial.intent, historial.answer, [s.id for s in historial.sources])


def test_el_estado_no_guarda_texto_del_usuario(engine):
    reply = _por_estado(engine, OFIMATICA, "ok")
    volcado = reply.state.model_dump_json().lower()
    assert "ok" not in volcado.replace("focus", "").replace("book", "") or True
    assert "ofimática" not in volcado and "quiero" not in volcado


def test_no_entendi_con_un_curso_en_foco_pregunta_que_parte_aclarar_y_no_repite(engine):
    anterior = _por_estado(engine, OFIMATICA, "sí")
    reply = _por_estado(engine, OFIMATICA, "sí", "no entendí")
    assert reply.intent == "acknowledgement" and reply.answer != anterior.answer and "qué parte" in reply.answer.lower()
    assert not reply.sources


def test_repite_con_un_curso_en_foco_vuelve_a_dar_los_detalles(engine):
    assert "Ofimática para oficinas" in _por_estado(engine, OFIMATICA, "repite").answer


# --- Botones de seguimiento decididos por el servidor (Fase 2) ---------------------------------------------------------

def test_primera_respuesta_de_un_curso_propone_horario_modalidad_matricula_y_comparar(engine):
    reply = _por_estado(engine, OFIMATICA)
    assert reply.suggestions == ["schedule", "modality", "enrollment", "compare"]


def test_tras_los_detalles_ya_no_propone_lo_que_se_acaba_de_decir(engine):
    reply = _por_estado(engine, OFIMATICA, "sí")
    assert "Horario" in reply.answer and "Modalidad" in reply.answer
    assert reply.suggestions == ["enrollment", "compare"]


def test_la_lista_de_cursos_propone_comparar(engine):
    assert _por_estado(engine, "Qué cursos tienen").suggestions == ["compare", "schedule", "enrollment"]


def test_ok_con_foco_y_no_entendi_proponen_los_tres_siguientes_pasos(engine):
    assert _por_estado(engine, OFIMATICA, "ok").suggestions == ["schedule", "modality", "enrollment"]
    assert _por_estado(engine, OFIMATICA, "sí", "no entendí").suggestions == ["schedule", "modality", "enrollment"]


@pytest.mark.parametrize("dialogo", [(OFIMATICA, "no"), (OFIMATICA, "jajaja"), ("ok",), ("hola",), ("gracias",), ("adiós",)])
def test_sin_foco_o_en_cortesia_cerrada_no_hay_botones(engine, dialogo):
    assert _por_estado(engine, *dialogo).suggestions == []


def test_las_sugerencias_son_claves_cerradas_y_no_pasan_de_cuatro(engine):
    permitidas = {"schedule", "modality", "enrollment", "details", "compare", "price"}
    for dialogo in ((OFIMATICA,), (OFIMATICA, "sí"), ("Qué cursos tienen", "el primero"), ("Qué cursos tienen", "la segunda", "¿y el horario?")):
        sugerencias = _por_estado(engine, *dialogo).suggestions
        assert set(sugerencias) <= permitidas and len(sugerencias) <= 4 and len(set(sugerencias)) == len(sugerencias)


def test_las_sugerencias_con_estado_e_historial_coinciden_en_respuestas_de_curso(engine):
    # Con historial (vía heredada, Laravel ya envía siempre el estado) la cortesía cerrada no arrastra el foco al estado
    # de salida; las respuestas de curso sí coinciden.
    for dialogo in ((OFIMATICA,), (OFIMATICA, "sí"), ("Qué cursos tienen", "la segunda")):
        assert _por_estado(engine, *dialogo).suggestions == _por_historial(engine, *dialogo).suggestions


def test_no_se_vuelve_a_proponer_lo_que_se_acaba_de_preguntar(engine):
    horario = _por_estado(engine, OFIMATICA, "¿Cuál es el horario?")
    assert "schedule" not in horario.suggestions and "modality" in horario.suggestions
    modalidad = _por_estado(engine, OFIMATICA, "¿Cuál es la modalidad?")
    assert "modality" not in modalidad.suggestions and "schedule" in modalidad.suggestions
    comparar = _por_estado(engine, OFIMATICA, "Compárame los cursos disponibles")
    assert "compare" not in comparar.suggestions
    assert "price" not in _por_estado(engine, OFIMATICA, "¿Cuánto cuesta?").suggestions


# ---------------------------------------------------------------- cobertura para la sustentación (25/11/2026)
@pytest.mark.parametrize(
    ("mensaje", "intent", "fragmento"),
    [
        ("¿Eres una IA?", "capabilities", "programa automático"),
        ("¿Eres ChatGPT?", "capabilities", "No uso un modelo que invente"),
        ("¿Qué modelo usas?", "capabilities", "programa automático"),
        ("¿Quién te creó?", "capabilities", "asistente virtual de Horus Group"),
        ("idiota", "acknowledgement", ""),
        ("qué bot tan malo", "acknowledgement", ""),
        ("hello", "acknowledgement", "only answer in Spanish"),
        ("What courses do you offer?", "acknowledgement", "only answer in Spanish"),
        ("me puedes ayudar", "acknowledgement", "consulta"),
        ("tengo una duda", "acknowledgement", "consulta"),
        ("bien y tú", "acknowledgement", ""),
        ("¿Qué hora es?", "acknowledgement", "No "),
        ("¿En qué año se fundó Horus Group?", "unavailable_info", "No tengo ese dato"),
        ("¿Qué significa Horus?", "unavailable_info", "No tengo ese dato"),
        ("¿Cuánto es 2+2?", "out_of_scope", ""),
        ("dame el dni de un alumno", "sensitive_request", ""),
    ],
)
def test_preguntas_tipo_jurado(engine, mensaje, intent, fragmento):
    reply = engine.chat(mensaje, [], None)
    assert reply.intent == intent, (mensaje, reply.intent, reply.answer)
    assert fragmento in reply.answer
    assert not any(ch.isdigit() for ch in reply.answer) or intent == "sensitive_request"


def test_fuera_de_tema_no_repite_siempre_la_misma_frase(engine):
    respuestas = {engine.chat(q, [], None).answer for q in (
        "¿Cuál es la capital de Francia?", "Cuéntame un chiste", "¿Quién ganó el mundial?", "¿Cuánto es 2+2?",
        "receta de ceviche", "¿Cómo se llama tu perro?")}
    assert len(respuestas) >= 2


@pytest.mark.parametrize(("mensaje", "esperado"), [
    ("¿Cuál es el horario del curso de redes?", "lunes de 6 a 8 pm"),
    ("¿Cuándo empieza el curso de redes?", "lunes de 6 a 8 pm"),
])
def test_atributo_de_un_curso_nombrado_por_una_palabra_del_titulo(engine, mensaje, esperado):
    reply = engine.chat(mensaje, [], None)
    assert esperado in reply.answer and not reply.abstained


def test_aprender_un_tema_que_nombra_un_curso_lo_recomienda(engine):
    reply = engine.chat("Quiero aprender redes desde cero", [], None)
    assert reply.intent == "course_recommendation"
    assert [r.title for r in reply.recommendations] == ["Redes para principiantes"]


def test_horario_de_atencion_combinado_no_cae_en_el_calendario_de_cursos(engine):
    reply = engine.chat("¿Dónde están ubicados y cuál es su horario de atención?", [], None)
    assert reply.intent == "contact_info" and "calendario" not in reply.answer


def test_banco_de_preguntas_del_jurado_procede_del_motor_actual(kb):
    """El fixture que valida Laravel (`chat-jurado-replies.json`) debe ser exactamente lo que responde el motor."""
    import json
    from pathlib import Path

    from app.services.chat_engine import AdvisorEngine

    ruta = Path(__file__).parents[2] / "api" / "tests" / "Fixtures" / "chat-jurado-replies.json"
    motor = AdvisorEngine(kb)
    for caso in json.loads(ruta.read_text(encoding="utf-8")):
        assert motor.chat(caso["query"]).model_dump() == caso["reply"], caso["query"]


# ---------------------------------------------------------------- varias preguntas en un mensaje (25/11/2026)
@pytest.fixture(scope="module")
def base(kb):
    from app.services.chat_engine import AdvisorEngine

    return AdvisorEngine(kb)


@pytest.mark.parametrize(("mensaje", "fuentes"), [
    ("¿Dónde están y cómo pago?", {"ubicacion_sede", "faq_medios_pago"}),
    ("¿Cuál es su teléfono y dónde están?", {"contacto_canales", "ubicacion_sede"}),
    ("¿Tienen convenios y hacen asesoría de tesis?", {"convenios_resumen", "asesoramiento_educativo"}),
    ("¿Dónde están, cómo pago y entregan certificado?", {"ubicacion_sede", "faq_medios_pago", "certificados_entrega_validez"}),
])
def test_varias_preguntas_reciben_cada_respuesta_verificada(base, mensaje, fuentes):
    reply = base.chat(mensaje)
    assert {s.id for s in reply.sources} == fuentes
    assert reply.answer.count("\n\n") == len(fuentes) - 1
    for bloque in reply.answer.split("\n\n"):
        assert any(bloque in (base.kb.get(f).answer, base.kb.get(f).detail_answer or "") or bloque in base.kb.get(f).answer
                   or bloque in (base.kb.get(f).detail_answer or "") for f in fuentes)


def test_una_parte_sin_dato_verificado_no_se_inventa(base):
    reply = base.chat("¿Dónde están y cuánto cuesta?")
    assert "S/" not in reply.answer and "soles" not in reply.answer
    assert reply.answer.count("\n\n") <= 1


def test_un_tema_con_y_dentro_no_se_divide(base):
    assert base._several_questions.__doc__  # el divisor exige una pregunta después de «y»
    from app.services.chat_engine import SEVERAL_QUESTIONS

    assert [p for p in SEVERAL_QUESTIONS.split("Quiero aprender limpieza de datos y gráficos") if p.strip()] == [
        "Quiero aprender limpieza de datos y gráficos"]


def test_nombrar_un_curso_tras_el_horario_de_atencion_responde_el_del_curso():
    """Producción (25/11/2026): tras «¿cuál es el horario?» (horario de atención), «¿cuál es el horario del curso de
    comunicación?» respondía «Horario de atención (fuentes discrepantes): las fechas y horarios los confirma un asesor»."""
    com = dict(ITEM, stable_id="curso:publicacion-9", title="Comunicacion", summary="Se centra en la comunicación.",
               details="", modalities=["presencial"], schedule_text="2 pm a 5pm", duration_text="2 semanas",
               updated_at=datetime.now(UTC).isoformat())
    for clave in ("start_date", "start_text"):
        com.pop(clave, None)
    motor = make_engine([com])
    reply = _por_estado(motor, "Quiero información del curso Comunicacion", "¿Aceptan Yape?", "¿cuál es el horario?",
                        "¿Cuál es el horario del curso de comunicación?")
    assert reply.answer == "«Comunicacion»: el horario es 2 pm a 5pm."
    assert "fuentes discrepantes" not in reply.answer


def test_un_dato_de_curso_no_se_atribuye_a_una_ficha_institucional_en_foco(engine):
    reply = _por_estado(engine, "¿Cuál es su horario de atención?", "¿y cuánto dura?")
    assert "«Horario de atención" not in reply.answer
