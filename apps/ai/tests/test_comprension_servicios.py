"""Regresiones de consultas reales: información pública con un catálogo editorial vacío."""

import json

import httpx
import pytest
from pydantic import SecretStr

from app.core.config import PROJECT_ROOT
from tests.test_phase_c import make_engine, public_items


def test_la_informacion_no_recrea_cursos_o_servicios_editoriales_retirados():
    engine = make_engine([])
    for query in ["quiero aprender instalación de cámaras", "tienen curso de cámaras", "curso de jardinería"]:
        reply = engine.chat(query)
        assert reply.abstained or reply.needs_clarification
        assert reply.recommendations == []
    assert not [r for r in engine.kb.records if r.kind in {"course", "service"}]


def test_las_respuestas_consumidas_por_laravel_proceden_del_motor_actual():
    fixture = json.loads((PROJECT_ROOT.parent / "api" / "tests/Fixtures/chat-comprension-replies.json").read_text(encoding="utf-8"))
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
