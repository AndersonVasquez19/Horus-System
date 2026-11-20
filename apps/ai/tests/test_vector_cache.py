"""Caché opcional de vectores de pasajes públicos: sin modelo real (embedder determinista simulado)."""

from __future__ import annotations

import numpy as np
import pytest

from app.core.config import Settings
from app.main import build_engine
from app.operacion import errores
from app.schemas.knowledge import KnowledgeRecord
from app.services.semantic_search import SemanticIndex
from app.services.vector_cache import PassageVectorCache, model_fingerprint


class CountingEmbedder:
    """Vector determinista por texto; cuenta cuántos textos codifica."""

    def __init__(self) -> None:
        self.encoded: list[str] = []

    def encode(self, texts: list[str]):
        self.encoded.extend(texts)
        rows = []
        for text in texts:
            rng = np.random.default_rng(abs(hash(text)) % (2**32))
            v = rng.standard_normal(8).astype(np.float32)
            rows.append(v / np.linalg.norm(v))
        return np.stack(rows) if rows else np.empty((0, 8), dtype=np.float32)


def _record(rid: str, title: str, description: str) -> KnowledgeRecord:
    return KnowledgeRecord.model_validate(
        {
            "id": f"rec_{rid}",
            "category": "formacion",
            "kind": "course",
            "title": title,
            "description": description,
            "answer": f"{title}: {description}",
            "status": "verified",
            "source": {
                "kind": "web_publica",
                "url_path": "/educacion/cursos",
                "file": "fixture.json",
                "checked_on": "2026-11-05",
            },
            "keywords": [title.split()[0].lower()],
            "examples": [],
            "topics": [],
            "related": [],
        }
    )


RECORDS = [
    _record("a", "Excel empresarial", "Tablas dinamicas."),
    _record("b", "Redes", "Cableado de oficinas."),
]


def _index(records, cache) -> tuple[SemanticIndex, CountingEmbedder]:
    index = SemanticIndex(records)
    index.vector_cache = cache
    embedder = CountingEmbedder()
    index.attach(embedder)
    return index, embedder


def test_a_restart_reuses_public_passage_vectors_without_encoding(tmp_path):
    cache = PassageVectorCache(tmp_path, "f" * 64)
    first, first_embedder = _index(RECORDS, cache)
    assert len(first_embedder.encoded) == 2
    second, second_embedder = _index(RECORDS, PassageVectorCache(tmp_path, "f" * 64))
    assert second_embedder.encoded == []  # reinicio: nada que recodificar
    assert second.ready
    np.testing.assert_allclose(first._matrix, second._matrix)


def test_retired_passages_are_not_kept_and_new_ones_are_encoded_once(tmp_path):
    cache = PassageVectorCache(tmp_path, "f" * 64)
    index, _ = _index(RECORDS, cache)
    updated = [RECORDS[0], _record("c", "Camaras", "Videovigilancia.")]
    candidate = index.rebuilt(updated)
    assert candidate.vector_cache is cache
    keys = np.load(cache.path, allow_pickle=False)["keys"].tolist()
    assert len(keys) == 2  # «Redes» retirado ya no figura en disco
    restarted, embedder = _index(updated, PassageVectorCache(tmp_path, "f" * 64))
    assert embedder.encoded == []
    assert restarted.ready


def test_another_model_fingerprint_never_reuses_vectors(tmp_path):
    _index(RECORDS, PassageVectorCache(tmp_path, "a" * 64))
    _, embedder = _index(RECORDS, PassageVectorCache(tmp_path, "b" * 64))
    assert len(embedder.encoded) == 2


def test_model_fingerprint_changes_with_weights_or_prefix(tmp_path):
    (tmp_path / "config.json").write_text("{}")
    weights = tmp_path / "model.safetensors"
    weights.write_bytes(b"1234")
    base = model_fingerprint(tmp_path, "m", "")
    assert model_fingerprint(tmp_path, "m", "passage: ") != base
    weights.write_bytes(b"123456")
    assert model_fingerprint(tmp_path, "m", "") != base


@pytest.mark.parametrize("content", [b"no es npz", b""])
def test_a_corrupt_cache_is_ignored_and_rewritten(tmp_path, content):
    cache = PassageVectorCache(tmp_path, "f" * 64)
    cache.path.write_bytes(content)
    index, embedder = _index(RECORDS, cache)
    assert index.ready and len(embedder.encoded) == 2
    assert len(np.load(cache.path, allow_pickle=False)["keys"]) == 2


def test_queries_and_plain_text_are_never_written(tmp_path):
    cache = PassageVectorCache(tmp_path, "f" * 64)
    index, _ = _index(RECORDS, cache)
    before = cache.path.read_bytes()
    index.search("consulta de un visitante sobre excel")
    assert cache.path.read_bytes() == before
    assert b"Excel empresarial" not in before and b"visitante" not in before
    assert [p.name for p in tmp_path.iterdir()] == [cache.path.name]  # sin temporales huérfanos


def test_unwritable_cache_never_breaks_the_index(tmp_path):
    blocker = tmp_path / "archivo"
    blocker.write_text("x")
    index, embedder = _index(RECORDS, PassageVectorCache(blocker / "sub", "f" * 64))
    assert index.ready and len(embedder.encoded) == 2


def test_cache_is_disabled_by_default_and_only_wired_when_configured(tmp_path):
    default = Settings(_env_file=None)
    assert default.semantic_cache_dir is None
    assert Settings(_env_file=None, semantic_cache_dir="").semantic_cache_dir is None
    _, semantic, _ = build_engine(default)
    assert semantic is not None and semantic.vector_cache is None
    configured = Settings(
        _env_file=None,
        semantic_enabled=True,
        semantic_cache_dir=tmp_path,
        semantic_model_dir=tmp_path / "sin-modelo",
    )
    _, semantic, _ = build_engine(configured)
    assert semantic is not None and semantic.vector_cache is not None
    assert semantic.vector_cache.path.parent == tmp_path


def test_operational_check_requires_an_existing_cache_directory(tmp_path):
    (tmp_path / "modules.json").write_text("[]")
    base = dict(
        _env_file=None,
        service_token="t" * 32,
        catalog_signing_key="k" * 32,
        catalog_path=tmp_path / "active.json",
        semantic_enabled=True,
        semantic_model_dir=tmp_path,
    )
    assert "semantic_cache_storage" not in errores(Settings(**base))
    assert "semantic_cache_storage" in errores(Settings(**base, semantic_cache_dir=tmp_path / "falta"))
    assert "semantic_cache_storage" not in errores(Settings(**base, semantic_cache_dir=tmp_path))


@pytest.mark.semantic
def test_real_minilm_restart_with_cache_gives_identical_answers_faster(tmp_path, record_property):
    """MiniLM real, CPU y offline: mismos vectores y mismas respuestas tras un reinicio con caché."""
    import os
    import time

    os.environ.setdefault("HF_HUB_OFFLINE", "1")
    os.environ.setdefault("TRANSFORMERS_OFFLINE", "1")
    pytest.importorskip("sentence_transformers")
    from app.services.chat_engine import AdvisorEngine
    from app.services.knowledge import KnowledgeBase
    from app.services.semantic_search import SentenceTransformerEmbedder

    settings = Settings(_env_file=None)
    if not settings.semantic_model_dir.is_dir():
        pytest.skip("Modelo no descargado")
    kb = KnowledgeBase.load(settings.knowledge_path, settings.intents_path)
    embedder = SentenceTransformerEmbedder(settings.semantic_model_dir, settings.ml_threads)
    fingerprint = model_fingerprint(settings.semantic_model_dir, settings.semantic_model_name, "")

    cold = SemanticIndex(kb.search_records)
    cold.vector_cache = PassageVectorCache(tmp_path, fingerprint)
    started = time.perf_counter()
    cold.attach(embedder)
    cold_seconds = time.perf_counter() - started

    warm = SemanticIndex(kb.search_records)
    warm.vector_cache = PassageVectorCache(tmp_path, fingerprint)
    started = time.perf_counter()
    warm.attach(embedder)
    warm_seconds = time.perf_counter() - started

    np.testing.assert_allclose(cold._matrix, warm._matrix, rtol=0, atol=0)
    question = "¿Me ayudan a terminar mi investigación de pregrado?"
    assert (
        AdvisorEngine(kb, cold).chat(question).model_dump()
        == AdvisorEngine(kb, warm).chat(question).model_dump()
    )
    record_property("passages", len(cold._owners))
    record_property("cold_index_seconds", round(cold_seconds, 3))
    record_property("warm_index_seconds", round(warm_seconds, 3))
    print(f"\npasajes={len(cold._owners)} frio={cold_seconds:.3f}s caliente={warm_seconds:.3f}s")
    assert warm_seconds < cold_seconds
