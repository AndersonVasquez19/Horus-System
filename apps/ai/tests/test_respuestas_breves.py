"""Brevedad y procedencia compartida, sin proveedores externos ni datos reales."""

import json

import httpx
import pytest
from pydantic import SecretStr

from app.core.config import PROJECT_ROOT
from app.services.chat_engine import AdvisorEngine
from app.services.gemini import GeminiSelector
from app.services.knowledge import KnowledgeError, load_knowledge_file
from tests.test_gemini import respuesta
from tests.test_phase_c import make_engine


@pytest.mark.parametrize("query", ["Qué convenios tienen", "Con qué instituciones tienen convenio", "Lista de convenios"])
def test_convenios_enumera_solo_las_tarjetas_y_no_agrega_beneficios(engine, query):
    reply = engine.chat(query)
    cards = [r for r in engine.kb.records if r.public_card]
    assert reply.answer == "Tenemos convenios con:\n" + "\n".join(f"- {r.title}" for r in cards)
    assert len(cards) == 5
    assert not reply.abstained and not reply.needs_clarification
    assert [s.id for s in reply.sources] == ["convenios_resumen"]
    for irrelevant in ("decano", "certificados", "postulaciones", "2027", "2029"):
        assert irrelevant not in reply.answer.lower()


def test_gemini_no_sustituye_el_listado_de_tarjetas(kb):
    def forbidden(_):
        raise AssertionError("El listado tiene una fuente determinada")

    with_selector = AdvisorEngine(kb, gemini=GeminiSelector(
        SecretStr("clave-ficticia"), "gemini-3.1-flash-lite", httpx.MockTransport(forbidden),
    ))
    assert with_selector.chat("Qué convenios tienen") == AdvisorEngine(kb).chat("Qué convenios tienen")


def test_gemini_respeta_la_respuesta_breve_y_la_ampliacion_explicita(kb):
    def handle(_):
        return httpx.Response(200, json=respuesta("servicio_soporte"))

    engine = AdvisorEngine(kb, gemini=GeminiSelector(
        SecretStr("clave-ficticia"), "gemini-3.1-flash-lite", httpx.MockTransport(handle),
    ))
    record = kb.get("servicio_soporte")
    brief = engine.chat("Qué servicios de soporte y mantenimiento ofrecen")
    assert brief.answer == record.answer and len(brief.answer) < 200
    detailed = engine.chat("Dame detalles del soporte y mantenimiento")
    assert detailed.answer == record.detail_answer
    assert "copias de seguridad" in detailed.answer


def test_vigencia_es_solo_la_de_la_tarjeta_consultada(engine):
    reply = engine.chat("Vigencia del convenio con CORLAD")
    card = engine.kb.get("convenio_administracion")
    assert reply.answer == f"{card.title}: Vigencia publicada hasta el {card.public_card.vigencia}."
    assert "certificados" not in reply.answer


def test_libertad_tiene_su_propia_fuente_y_no_se_confunde_con_cajamarca(engine):
    reply = engine.chat("Qué convenio tienen con Enfermeros de La Libertad")
    assert [s.id for s in reply.sources] == ["convenio_enfermeria_libertad"]
    assert "Cajamarca" not in reply.answer


def test_isam_conserva_la_abstencion_sobre_detalles_no_confirmados(engine):
    reply = engine.chat("Dame detalles del convenio con ISAM")
    assert reply.abstained
    assert "pendientes de confirmación" in reply.answer


def test_atributo_concreto_no_recibe_el_resumen_general(engine):
    reply = engine.chat("En cuánto tiempo responden una consulta de asesoría")
    assert "48 horas" in reply.answer
    assert "educativo, empresarial y legal" not in reply.answer


def test_pregunta_por_caracteristica_recibe_la_frase_que_la_confirma(engine):
    reply = engine.chat("Sus cámaras tienen visión nocturna")
    assert "visión nocturna" in reply.answer
    assert "proyectos de videovigilancia para hogares" not in reply.answer
    assert len(reply.answer) < 350


def test_resumen_editorial_no_agrega_modalidad_ni_metadatos_no_pedidos():
    engine = make_engine()
    reply = engine.chat("Quiero aprender análisis de datos")
    assert reply.answer == "Analisis de datos con Python: Aprende analisis de datos con Python."
    assert "Modalidad" not in reply.answer and "actualizado" not in reply.answer
    assert reply.sources and reply.recommendations
    modality = engine.chat("Cuál es la modalidad del curso de análisis de datos")
    assert "virtual" in modality.answer


def test_detalles_no_pueden_introducir_contactos_privados(tmp_path):
    raw = json.loads((PROJECT_ROOT / "data/knowledge.json").read_text(encoding="utf-8"))
    raw["records"][0]["detail_answer"] = "Contacta al correo privado@example.org para conocer detalles."
    path = tmp_path / "knowledge.json"
    path.write_text(json.dumps(raw), encoding="utf-8")
    with pytest.raises(KnowledgeError, match="privados"):
        load_knowledge_file(path)


def test_el_listado_se_reconstruye_desde_las_tarjetas_aunque_el_resumen_guardado_sea_anterior(tmp_path):
    raw = json.loads((PROJECT_ROOT / "data/knowledge.json").read_text(encoding="utf-8"))
    card = next(r for r in raw["records"] if r.get("public_card"))
    previous = card["title"]
    card["title"] = "Institución pública de prueba"
    path = tmp_path / "knowledge.json"
    path.write_text(json.dumps(raw), encoding="utf-8")
    records = load_knowledge_file(path).records
    listing = next(r for r in records if r.id == "convenios_resumen")
    assert card["title"] in listing.answer
    assert previous not in listing.answer


@pytest.mark.parametrize("name", ["chat-breve-catalogo.json", "chat-breve-replies.json"])
def test_fixtures_que_consume_laravel_proceden_del_motor_actual(kb, name):
    fixture = json.loads((PROJECT_ROOT.parent / "tests/Fixtures" / name).read_text(encoding="utf-8"))
    engine = make_engine(fixture["items"]) if isinstance(fixture, dict) else AdvisorEngine(kb)
    cases = fixture["cases"] if isinstance(fixture, dict) else fixture
    for case in cases:
        assert engine.chat(case["query"]).model_dump() == case["reply"]
