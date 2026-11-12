"""Casos públicos ficticios: recuperación, procedencia y abstención sin reservas privadas."""

from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, datetime, timedelta

import numpy as np
import pytest
from fastapi.testclient import TestClient

from app.core.config import Settings
from app.main import create_app
from app.schemas.chat import HistoryItem, RecommendRequest
from app.services import catalog_sync
from app.services.catalog_documents import split_content
from app.services.chat_engine import AdvisorEngine
from app.services.knowledge import KnowledgeBase, KnowledgeError
from app.services.semantic_search import SemanticIndex
from tests.conftest import TEST_TOKEN
from tests.test_catalog import KEY
from tests.test_catalog_sync import _catalog


def build(
    details: str = "El laboratorio permite configurar segmentacion VLAN y diagnosticar enlaces.",
) -> KnowledgeBase:
    item = {
        "stable_id": "curso:rag-redes",
        "type": "curso",
        "publication_status": "vigente",
        "title": "Administracion de redes",
        "summary": "Formacion publica sobre redes empresariales.",
        "details": details,
        "keywords": ["Administracion de redes"],
        "modalities": ["virtual"],
        "updated_at": datetime.now(UTC).isoformat(),
    }
    settings = Settings(_env_file=None)
    return KnowledgeBase.load_with_catalog_payload(
        settings.knowledge_path, settings.intents_path, _catalog(datetime.now(UTC), items=[item]), KEY
    )


def test_normalization_preserves_document_version_and_fragment_provenance():
    kb = build()
    document = kb.documents[0]
    assert document.stable_id == "curso:rag-redes"
    assert document.version == kb.catalog.catalog.catalog_version
    assert document.modalities == ("virtual",)
    assert document.publication_status == "vigente"
    assert len(document.fragments) == 1
    assert document.fragments[0].source_id == document.source_id


def test_long_details_are_searchable_without_losing_the_tail_or_duplicating_sources():
    prefix = ("La formacion utiliza ejercicios guiados para comprender conexiones locales. " * 30)[
        :1400
    ].rsplit(" ", 1)[0]
    details = prefix + "\n\nEl laboratorio permite configurar segmentacion VLAN y diagnosticar enlaces."
    kb = build(details)
    assert len(kb.documents[0].fragments) > 1
    assert all(len(f.text) <= 600 for f in kb.documents[0].fragments)
    engine = AdvisorEngine(kb)
    hits = engine.retrieve("segmentacion VLAN diagnosticar enlaces")
    assert hits[0].fragment is not None
    assert len({h.record.id for h in hits}) == len(hits)
    reply = engine.chat("segmentacion VLAN diagnosticar enlaces")
    assert "segmentacion VLAN" in reply.answer
    assert reply.sources[0].id == "cat_curso_rag_redes"
    assert not reply.abstained


def test_unrelated_question_abstains_and_does_not_invent_prices_or_capacity():
    engine = AdvisorEngine(build())
    reply = engine.chat("cirugia submarina extraterrestre")
    assert reply.abstained or reply.needs_clarification
    reply = engine.chat("Cuantos cupos quedan en administracion de redes y cuanto cuesta")
    assert reply.abstained
    assert reply.intent == "unavailable_info"
    assert reply.missing_data


def test_recommendation_uses_current_published_content_without_manual_topics():
    reply = AdvisorEngine(build()).chat("Recomiendame administracion de redes")
    assert reply.recommendations
    assert reply.recommendations[0].id == "cat_curso_rag_redes"
    assert reply.sources[0].id == reply.recommendations[0].id


def test_splitter_keeps_small_unicode_text_and_preserves_long_text():
    assert split_content("Información pública\n\ncon tildes y 漢字.") == (
        "Información pública con tildes y 漢字.",
    )
    text = "Configuración de redes públicas y conectividad. " * 30
    assert " ".join(split_content(text)) == " ".join(text.split())
    assert all(len(part) <= 600 for part in split_content(text))
    assert [len(part) for part in split_content("á" * 900)] == [600, 300]


def test_snapshot_expiring_during_embedding_cannot_be_activated(tmp_path, monkeypatch):
    now = datetime.now(UTC)

    class Later(datetime):
        @classmethod
        def now(cls, tz=None):
            return now + timedelta(minutes=6)

    class SlowIndex:
        def rebuilt(self, records):
            monkeypatch.setattr(catalog_sync, "datetime", Later)
            return None

    settings = Settings(_env_file=None, catalog_path=tmp_path / "active.json", catalog_signing_key=KEY)
    base = KnowledgeBase.load(settings.knowledge_path, settings.intents_path)
    with pytest.raises(catalog_sync.CatalogSyncRejected, match="caducó"):
        catalog_sync.build_catalog_candidate(_catalog(now), settings, AdvisorEngine(base), SlowIndex())


def test_building_catalog_reports_pending_version_and_blocks_chat(tmp_path, monkeypatch):
    import threading

    from app.api import routes
    from tests.test_catalog_sync import _client

    original = routes.build_catalog_candidate
    entered, finish = threading.Event(), threading.Event()

    def blocked(*args):
        entered.set()
        assert finish.wait(5)
        return original(*args)

    monkeypatch.setattr(routes, "build_catalog_candidate", blocked)
    client, _ = _client(tmp_path)
    raw = _catalog(datetime.now(UTC))
    with client, ThreadPoolExecutor() as pool:
        future = pool.submit(
            client.post, "/api/v1/catalog/sync", json=raw, headers={"X-Horus-Service-Token": TEST_TOKEN}
        )
        try:
            assert entered.wait(5)
            ready = client.get("/ready").json()
            assert ready["catalog_pending_version"] == raw["catalog_version"]
            assert ready["status"] == "not_ready"
            assert (
                client.post(
                    "/api/v1/chat", json={"message": "cursos"}, headers={"X-Horus-Service-Token": TEST_TOKEN}
                ).status_code
                == 503
            )
        finally:
            finish.set()
        assert future.result().status_code == 200
        assert "catalog_pending_version" not in client.get("/ready").json()


def test_new_fragments_cannot_introduce_private_contact_hidden_beyond_old_truncation():
    prefix = ("Contenido publico autorizado. " * 60)[:1455].rsplit(" ", 1)[0]
    details = prefix + " contacto-privado@example.invalid"
    assert len(details) <= 1500
    with pytest.raises(KnowledgeError, match="privados"):
        build(details)


class VectorFixture:
    def encode(self, texts):
        return np.array([[1.0, 0.0] if "VLAN" in text else [0.0, 1.0] for text in texts])


def test_semantic_reformulation_reuses_the_same_fragment_index():
    kb = build()
    semantic = SemanticIndex(kb.search_records)
    semantic.attach(VectorFixture())
    engine = AdvisorEngine(kb, semantic)
    hits = engine.retrieve("Como revisar enlaces y configurar VLAN")
    assert hits[0].record.id == "cat_curso_rag_redes"
    assert hits[0].semantic is not None
    assert hits[0].fragment is not None
    reply = engine.chat("Como revisar enlaces y configurar VLAN")
    assert "segmentacion VLAN" in reply.answer


@pytest.mark.semantic
def test_real_local_minilm_retrieves_published_fragment_and_preserves_its_source():
    from app.services.semantic_search import SentenceTransformerEmbedder

    settings = Settings(_env_file=None)
    if not settings.semantic_model_dir.is_dir():
        pytest.skip("Requiere el modelo MiniLM ya instalado localmente.")
    kb = build()
    semantic = SemanticIndex(kb.search_records)
    semantic.attach(SentenceTransformerEmbedder(settings.semantic_model_dir, settings.ml_threads))
    engine = AdvisorEngine(kb, semantic)
    reply = engine.chat("Como revisar fallos en enlaces y configurar VLAN")
    assert engine.mode == "hybrid"
    assert "segmentacion VLAN" in reply.answer
    assert reply.sources[0].id == "cat_curso_rag_redes"
    assert reply.answer in {fragment.answer for fragment in kb.documents[0].fragments}


def test_semantic_rebuild_reuses_public_vectors_but_never_caches_queries():
    class CountingVector(VectorFixture):
        def __init__(self):
            self.batches = []

        def encode(self, texts):
            self.batches.append(list(texts))
            return super().encode(texts)

    kb = build()
    vector = CountingVector()
    semantic = SemanticIndex(kb.search_records)
    semantic.attach(vector)
    batches = len(vector.batches)
    rebuilt = semantic.rebuilt(kb.search_records)
    assert len(vector.batches) == batches
    rebuilt.search("consulta temporal no persistida")
    assert "consulta temporal no persistida" not in rebuilt._passage_vectors


def test_similarity_alone_cannot_turn_an_unrelated_query_into_a_fact():
    class ConstantVector:
        def encode(self, texts):
            return np.tile([1.0, 0.0], (len(texts), 1))

    kb = build()
    semantic = SemanticIndex(kb.search_records)
    semantic.attach(ConstantVector())
    reply = AdvisorEngine(kb, semantic).chat("zoologia de cometas")
    assert reply.abstained or reply.needs_clarification
    assert "segmentacion VLAN" not in reply.answer


def test_structured_recommendations_use_current_publication_without_topics():
    reply = AdvisorEngine(build()).recommend(RecommendRequest(interests=["Administracion de redes"]))
    assert reply.recommendations[0].id == "cat_curso_rag_redes"


def test_recommendations_do_not_import_interests_from_previous_turns():
    engine = AdvisorEngine(build())
    reply = engine.chat(
        "Recomiendame zoologia de cometas",
        history=[HistoryItem(role="user", content="Recomiendame administracion de redes")],
    )
    assert reply.recommendations == []


def test_service_details_are_retrieved_with_the_same_signed_fragment_contract():
    original = build()
    item = original.catalog.catalog.items[0].model_dump(mode="json", exclude_none=True)
    item.update(
        stable_id="servicio:rag-enlaces",
        type="servicio",
        title="Diagnostico de enlaces",
        summary="Servicio publico para revisar conectividad empresarial.",
        details="Permite verificar fibra optica y revisar conectores.",
    )
    settings = Settings(_env_file=None)
    kb = KnowledgeBase.load_with_catalog_payload(
        settings.knowledge_path, settings.intents_path, _catalog(datetime.now(UTC), items=[item]), KEY
    )
    reply = AdvisorEngine(kb).chat("verificar fibra optica revisar conectores")
    assert "fibra optica" in reply.answer
    assert reply.sources[0].id == "cat_servicio_rag_enlaces"
    assert kb.documents[0].content_type == "servicio"


def test_signed_catalog_does_not_recommend_legacy_offerings_outside_its_publications():
    assert all(
        r.source.kind == "catalogo_laravel" and r.kind in {"course", "service"}
        for r in build().recommendable()
    )


def test_concurrent_queries_do_not_mutate_documents_or_store_interests():
    kb = build()
    engine = AdvisorEngine(kb)
    documents = kb.documents
    with ThreadPoolExecutor(max_workers=4) as executor:
        replies = list(executor.map(engine.chat, ["segmentacion VLAN diagnosticar enlaces"] * 24))
    assert len({r.answer for r in replies}) == 1
    assert kb.documents is documents
    assert all(r.sources[0].id == "cat_curso_rag_redes" for r in replies)


def test_a1_update_and_retirement_replace_fragment_owners_atomically(tmp_path):
    from datetime import timedelta

    initial = build()
    item = initial.catalog.catalog.items[0].model_dump(mode="json", exclude_none=True)
    path = tmp_path / "catalog.json"
    settings = Settings(
        _env_file=None,
        service_token=TEST_TOKEN,
        catalog_path=path,
        catalog_signing_key=KEY,
        semantic_enabled=False,
    )
    headers = {"X-Horus-Service-Token": TEST_TOKEN}
    generated = datetime.now(UTC)
    with TestClient(create_app(settings)) as client:
        raw = _catalog(generated, items=[item])
        assert client.post("/api/v1/catalog/sync", json=raw, headers=headers).status_code == 200
        old_ids = set(client.app.state.engine.kb.fragments)
        client.app.state.semantic.attach(VectorFixture())
        item["details"] = "El laboratorio permite verificar fibra optica y revisar conectores."
        changed = _catalog(generated + timedelta(seconds=1), items=[item])
        assert client.post("/api/v1/catalog/sync", json=changed, headers=headers).status_code == 200
        kb = client.app.state.engine.kb
        assert old_ids.isdisjoint(kb.fragments)
        assert old_ids.isdisjoint(client.app.state.semantic._owners)
        assert kb.documents[0].version == changed["catalog_version"]
        reply = client.post(
            "/api/v1/chat", json={"message": "verificar fibra optica revisar conectores"}, headers=headers
        )
        assert "fibra optica" in reply.json()["answer"]
        empty = _catalog(generated + timedelta(seconds=2), items=[])
        assert client.post("/api/v1/catalog/sync", json=empty, headers=headers).status_code == 200
        assert client.app.state.engine.kb.documents == ()
        assert client.app.state.engine.kb.fragments == {}


def test_ambiguous_public_documents_request_clarification_instead_of_arbitrary_choice():
    original = build()
    first = original.catalog.catalog.items[0].model_dump(mode="json", exclude_none=True)
    second = dict(first, stable_id="curso:rag-alternativo", title="Gestion de redes")
    settings = Settings(_env_file=None)
    kb = KnowledgeBase.load_with_catalog_payload(
        settings.knowledge_path,
        settings.intents_path,
        _catalog(datetime.now(UTC), items=[first, second]),
        KEY,
    )
    reply = AdvisorEngine(kb).chat("segmentacion VLAN diagnosticar enlaces")
    assert reply.needs_clarification
    assert reply.sources == []
