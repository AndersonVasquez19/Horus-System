"""Estado conversacional estructurado (22/11/2026).

Laravel no guarda el texto de la conversación. En su lugar reenvía el `state` que el motor devolvió en el turno
anterior: claves de vocabulario cerrado, identificadores de registros publicados y contadores. Estas pruebas
fijan que (1) con el estado se obtiene lo mismo que con el historial, (2) el estado nunca lleva texto del usuario,
(3) un estado manipulado se descarta y (4) el contexto caduca como el historial (3 turnos).
"""

from __future__ import annotations

import json
import re
from datetime import UTC, datetime

import pytest
from fastapi.testclient import TestClient

from app.schemas.chat import ConversationState, HistoryItem
from tests.test_phase_c import make_engine

ITEM = {
    "stable_id": "curso:publicacion-7",
    "type": "curso",
    "publication_status": "vigente",
    "title": "Ofimática para oficinas",
    "summary": "Aprende Excel y Word para el trabajo diario.",
    "details": "Inscripción por WhatsApp oficial. Temario: Excel básico, tablas dinámicas y Word profesional.",
    "modalities": ["virtual"],
    "duration_text": "8 semanas",
    "schedule_text": "sábados de 9 a 12",
    "start_date": "2026-12-05",
    "certification_text": "Certificado digital a nombre de Horus Group.",
    "url_path": "/educacion/cursos",
    "updated_at": datetime.now(UTC).isoformat(),
}


@pytest.fixture(scope="module")
def engine():
    return make_engine([ITEM])


def _por_estado(engine, *mensajes: str):
    state = None
    reply = None
    for mensaje in mensajes:
        reply = engine.chat(mensaje, [], state)
        state = reply.state
    return reply


def _por_historial(engine, *mensajes: str):
    history = [HistoryItem(role="user", content=m) for m in mensajes[:-1]]
    return engine.chat(mensajes[-1], history)


@pytest.mark.parametrize(
    "dialogo",
    [
        ("Quiero información del curso de Ofimática para oficinas", "¿y cuándo inicia?"),
        ("Quiero información del curso de Ofimática para oficinas", "¿y dan certificado?"),
        ("Quiero información del curso de Ofimática para oficinas", "¿y eso es virtual?"),
        ("Quiero aprender ofimática", "¿cuánto dura?"),
        ("¿Reparan autos?", "¿y eso lo hacen a domicilio?"),
        ("Quiero una cotización", "para capacitar a mi personal en ofimática"),
        ("Hola", "¿Qué cursos tienen?"),
    ],
)
def test_con_el_estado_se_responde_igual_que_con_el_historial(engine, dialogo) -> None:
    por_estado = _por_estado(engine, *dialogo)
    por_historial = _por_historial(engine, *dialogo)
    assert por_estado.answer == por_historial.answer
    assert [s.id for s in por_estado.sources] == [s.id for s in por_historial.sources]
    assert por_estado.intent == por_historial.intent


def test_el_seguimiento_sobre_un_curso_responde_su_dato_publicado(engine) -> None:
    reply = _por_estado(engine, "Quiero información del curso de Ofimática para oficinas", "¿y cuándo inicia?")
    assert reply.answer == "«Ofimática para oficinas»: inicia el 5 de diciembre de 2026; el horario es sábados de 9 a 12."
    assert [s.id for s in reply.sources] == ["cat_curso_publicacion_7"]


def test_el_estado_no_contiene_texto_del_usuario(engine) -> None:
    mensaje = "Soy Zacarías Quispe y quiero aprender ofimática nivel básico en modalidad virtual, gracias"
    state = engine.chat(mensaje).state
    assert state is not None
    serializado = json.dumps(state.model_dump(), ensure_ascii=False).lower()
    for palabra in ("zacarías", "zacarias", "quispe", "soy", "gracias", "quiero"):
        assert palabra not in serializado
    rules = engine.kb.intents
    assert set(state.topics) <= set(rules.topic_lexicon)
    assert state.level in {*rules.level_lexicon, "desconocido"}
    assert state.modality in {*rules.modality_lexicon, "cualquiera"}
    assert all(engine.kb.get(x) is not None for x in state.focus_ids)
    for valor in (*state.topics, *state.objectives, state.level, state.modality, state.audience):
        assert re.fullmatch(r"[a-z0-9_]{1,40}", valor)


def test_un_estado_manipulado_se_descarta(engine) -> None:
    falso = ConversationState(
        topics=["inventado"], level="experto_total", modality="teletransporte", profile_age=0,
        focus_ids=["registro_que_no_existe"], focus_age=0, cue_age=0,
    )
    reply = engine.chat("¿cuánto dura?", [], falso)
    assert all(s.id != "registro_que_no_existe" for s in reply.sources)
    assert not reply.recommendations
    assert reply.state is not None
    assert "inventado" not in reply.state.topics
    assert "registro_que_no_existe" not in reply.state.focus_ids


def test_el_contexto_caduca_a_los_cinco_turnos(engine) -> None:
    state = engine.chat("Quiero aprender ofimática nivel básico").state
    assert state.profile_age == 0 and state.cue_age == 0
    edades = []
    for mensaje in ("Hola", "¿Dónde están ubicados?", "¿Aceptan Yape?", "¿Tienen convenios?", "¿Entregan factura?"):
        state = engine.chat(mensaje, [], state).state
        edades.append((state.profile_age, state.cue_age))
    assert edades[:4] == [(1, 1), (2, 2), (3, 3), (4, 4)]
    assert edades[4] == (None, None)
    assert state.topics == [] and state.level == "desconocido"


def test_otra_cosa_descarta_el_contexto(engine) -> None:
    state = engine.chat("Quiero información del curso de Ofimática para oficinas").state
    assert state.focus_ids == ["cat_curso_publicacion_7"]
    reply = engine.chat("Ahora otra cosa, ¿dónde están ubicados?", [], state)
    assert "cat_curso_publicacion_7" not in [s.id for s in reply.sources]


def test_si_llega_historial_el_estado_se_ignora(engine) -> None:
    falso = ConversationState(focus_ids=["cat_curso_publicacion_7"], focus_age=0)
    con_historial = engine.chat("¿cuánto dura?", [HistoryItem(role="user", content="¿Dónde están ubicados?")], falso)
    solo_historial = engine.chat("¿cuánto dura?", [HistoryItem(role="user", content="¿Dónde están ubicados?")])
    assert con_historial.answer == solo_historial.answer


def test_la_api_acepta_el_estado_y_rechaza_campos_ajenos(client: TestClient, auth: dict[str, str]) -> None:
    primera = client.post("/api/v1/chat", json={"message": "Quiero aprender ofimática"}, headers=auth)
    assert primera.status_code == 200
    state = primera.json()["state"]
    assert state is not None
    segunda = client.post("/api/v1/chat", json={"message": "¿y es virtual?", "state": state}, headers=auth)
    assert segunda.status_code == 200
    for malo in (
        {**state, "texto": "lo que escribió el usuario"},
        {**state, "topics": ["quiero aprender ofimática"]},
        {**state, "focus_ids": ["a", "b", "c"]},
        {**state, "profile_age": 5},
    ):
        respuesta = client.post("/api/v1/chat", json={"message": "hola", "state": malo}, headers=auth)
        assert respuesta.status_code == 422, malo
