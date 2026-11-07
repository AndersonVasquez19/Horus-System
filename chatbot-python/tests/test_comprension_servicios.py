"""Regresiones de consultas reales: información pública con un catálogo editorial vacío."""

import json

import httpx
import pytest
from pydantic import SecretStr

from app.core.config import PROJECT_ROOT
from tests.test_phase_c import make_engine, public_items


@pytest.mark.parametrize("query", [
    "que cámaras tienen", "quiero saber sobre las cámaras de seguridad", "me interesa instalar cámaras",
    "qué tipos de cámaras tienen", "información de cableado estructurado", "soporte y mantenimiento",
])
def test_un_catalogo_vacio_no_borra_la_explicacion_institucional(query):
    reply = make_engine([]).chat(query)
    assert not reply.needs_clarification and not reply.abstained
    assert reply.intent == "service_info"
    assert reply.sources and reply.sources[0].id.startswith("servicio_")
    assert reply.recommendations == []
    assert len(reply.answer) <= 350


def test_los_tipos_publicados_no_se_presentan_como_modelos_disponibles():
    engine = make_engine([])
    reply = engine.chat("que cámaras tienen")
    assert "IP" in reply.answer and "domo" in reply.answer
    assert "disponibilidad deben confirmarse" in reply.answer
    assert "servicio_camaras" not in {r.id for r in engine.kb.recommendable()}
    record = engine.kb.get("servicio_camaras")
    assert record.kind == "info" and not record.recommendable
    assert record.public_price is None and record.schedule is None


def test_gemini_no_sustituye_una_respuesta_institucional_inequivoca():
    from app.services.gemini import GeminiSelector

    def forbidden(_):
        raise AssertionError("La consulta ya tiene una fuente institucional inequívoca")

    engine = make_engine([])
    engine.gemini = GeminiSelector(SecretStr("clave-ficticia"), "gemini-3.1-flash-lite", httpx.MockTransport(forbidden))
    reply = engine.chat("que cámaras tienen")
    assert [s.id for s in reply.sources] == ["servicio_camaras"]
    assert not reply.needs_clarification


def test_la_informacion_no_recrea_cursos_o_servicios_editoriales_retirados():
    engine = make_engine([])
    for query in ["quiero aprender instalación de cámaras", "tienen curso de cámaras", "curso de jardinería"]:
        reply = engine.chat(query)
        assert reply.abstained or reply.needs_clarification
        assert reply.recommendations == []
    assert not [r for r in engine.kb.records if r.kind in {"course", "service"}]


def test_la_ficha_editorial_vigente_conserva_la_prioridad():
    item = dict(public_items()[1], stable_id="servicio:camaras", title="Cámaras de seguridad",
        summary="Instalación publicada de cámaras de seguridad para empresas.",
        keywords=["cámaras", "seguridad", "videovigilancia"])
    reply = make_engine([item]).chat("me interesa cámaras de seguridad")
    assert [r.id for r in reply.recommendations] == ["cat_servicio_camaras"]


def test_las_respuestas_consumidas_por_laravel_proceden_del_motor_actual():
    fixture = json.loads((PROJECT_ROOT.parent / "tests/Fixtures/chat-comprension-replies.json").read_text(encoding="utf-8"))
    engine = make_engine([])
    for case in fixture:
        assert engine.chat(case["query"]).model_dump() == case["reply"]


@pytest.fixture(scope="module")
def informational_hybrid():
    from app.core.config import Settings
    from app.services.semantic_search import SemanticIndex, SentenceTransformerEmbedder

    settings = Settings(_env_file=None)
    assert settings.semantic_model_dir.is_dir(), "Se requiere el modelo local; no descargar."
    engine = make_engine([])
    index = SemanticIndex(engine.kb.search_records)
    index.attach(SentenceTransformerEmbedder(settings.semantic_model_dir, threads=2))
    engine.semantic = index
    return engine


@pytest.mark.semantic
@pytest.mark.parametrize("query", ["que cámaras tienen", "quiero saber sobre las cámaras de seguridad"])
def test_las_capturas_se_resuelven_con_minilm_local_y_catalogo_vacio(informational_hybrid, query):
    reply = informational_hybrid.chat(query)
    assert reply.mode == "hybrid"
    assert not reply.needs_clarification and not reply.abstained
    assert [s.id for s in reply.sources] == ["servicio_camaras"]
    assert reply.recommendations == [] and "disponibilidad deben confirmarse" in reply.answer
