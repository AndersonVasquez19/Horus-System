"""Aclaraciones de objetivo sin inventar ofertas ni perder consultas explícitas."""

import json

import pytest

from app.core.config import PROJECT_ROOT
from tests.test_phase_c import make_engine, public_items


@pytest.mark.parametrize(
    ("query", "record"),
    [
        ("quiero cámaras", "servicio_camaras"),
        ("quisiera cámaras", "servicio_camaras"),
        ("necesito camaras de seguridda", "servicio_camaras"),
        ("busco cableado estructurado", "servicio_cableado"),
        ("quisiera soporte técnico", "servicio_soporte"),
    ],
)
def test_necesidad_breve_aclara_el_objetivo(query, record):
    reply = make_engine([]).chat(query)
    assert reply.needs_clarification and reply.intent == "clarification"
    assert "características" in reply.clarification_question
    assert "capacitación publicada" in reply.clarification_question
    assert reply.context.focus_ids == [record]
    assert not reply.sources and not reply.recommendations


@pytest.mark.parametrize(
    "query",
    [
        "qué cámaras tienen",
        "quiero saber sobre las cámaras de seguridad",
        "me interesa instalar cámaras",
        "qué incluye el servicio de videovigilancia",
        "camaras de seguridda",
    ],
)
def test_informacion_o_instalacion_explicita_no_recibe_la_pregunta_generica(query):
    reply = make_engine([]).chat(query)
    assert not reply.needs_clarification
    assert reply.sources and reply.sources[0].id == "servicio_camaras"


def test_aprender_no_se_confunde_con_un_servicio_ni_publica_un_curso():
    reply = make_engine([]).chat("Busco un curso de Cámaras de seguridad y videovigilancia")
    assert reply.abstained or reply.needs_clarification
    assert reply.recommendations == []


def test_la_aclaracion_no_desplaza_una_ficha_editorial_pertinente():
    item = dict(
        public_items()[1],
        stable_id="servicio:camaras",
        title="Cámaras de seguridad",
        summary="Instalación publicada de cámaras de seguridad para empresas.",
        keywords=["cámaras", "seguridad", "videovigilancia"],
    )
    reply = make_engine([item]).chat("me interesa cámaras de seguridad")
    assert [r.id for r in reply.recommendations] == ["cat_servicio_camaras"]


def test_la_fixture_compartida_procede_del_motor_actual():
    cases = json.loads(
        (PROJECT_ROOT.parent / "tests/Fixtures/chat-aclaraciones-replies.json").read_text(encoding="utf-8")
    )
    engine = make_engine([])
    for case in cases:
        assert engine.chat(case["query"]).model_dump() == case["reply"]
