"""Carga ligera: formatos desconocidos conservan el cargador original."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from app.core.config import Settings
from app.services.minilm_inference import MiniLMInference, supports_local_minilm


@pytest.fixture
def model_config(tmp_path: Path) -> Path:
    files = {
        "modules.json": [
            {"idx": 0, "name": "0", "path": "", "type": "sentence_transformers.models.Transformer"},
            {"idx": 1, "name": "1", "path": "1_Pooling", "type": "sentence_transformers.models.Pooling"},
        ],
        "1_Pooling/config.json": {
            "word_embedding_dimension": 384,
            "pooling_mode_cls_token": False,
            "pooling_mode_mean_tokens": True,
            "pooling_mode_max_tokens": False,
            "pooling_mode_mean_sqrt_len_tokens": False,
        },
        "sentence_bert_config.json": {"max_seq_length": 128, "do_lower_case": False},
        "config.json": {"model_type": "bert", "architectures": ["BertModel"], "hidden_size": 384},
        "config_sentence_transformers.json": {"__version__": {"sentence_transformers": "2.0.0"}},
    }
    for name, content in files.items():
        target = tmp_path / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(content), encoding="utf-8")
    return tmp_path


def test_known_minilm_pipeline_is_recognized(model_config: Path) -> None:
    assert supports_local_minilm(model_config)


@pytest.mark.parametrize(
    ("name", "content"),
    [
        ("modules.json", []),
        ("modules.json", [{"type": "custom.Module", "path": "../custom"}]),
        ("1_Pooling/config.json", {"pooling_mode_mean_tokens": False}),
        ("sentence_bert_config.json", {"max_seq_length": 512, "do_lower_case": False}),
        ("sentence_bert_config.json", {"max_seq_length": 128, "do_lower_case": True}),
        ("config_sentence_transformers.json", {"prompts": {"query": "query: "}}),
        ("config_sentence_transformers.json", {"truncate_dim": 128}),
        ("config.json", {"model_type": "custom", "hidden_size": 384}),
        ("config.json", []),
    ],
)
def test_other_pipelines_are_not_silently_reinterpreted(
    model_config: Path, name: str, content: object
) -> None:
    (model_config / name).write_text(json.dumps(content), encoding="utf-8")
    assert not supports_local_minilm(model_config)


@pytest.mark.parametrize("content", ["{ no es json", "[]", "null"])
def test_invalid_configuration_uses_original_loader(model_config: Path, content: str) -> None:
    (model_config / "modules.json").write_text(content, encoding="utf-8")
    assert not supports_local_minilm(model_config)


def test_missing_model_uses_original_loader(tmp_path: Path) -> None:
    assert not supports_local_minilm(tmp_path)


def test_adapters_and_remote_code_do_not_use_fast_path(model_config: Path) -> None:
    config_path = model_config / "config.json"
    config = json.loads(config_path.read_text())
    config["auto_map"] = {"AutoModel": "custom.Model"}
    config_path.write_text(json.dumps(config), encoding="utf-8")
    assert not supports_local_minilm(model_config)
    del config["auto_map"]
    config_path.write_text(json.dumps(config), encoding="utf-8")
    (model_config / "adapter_config.json").write_text("{}")
    assert not supports_local_minilm(model_config)


@pytest.mark.semantic
def test_real_vectors_and_answers_match_original_pipeline(tmp_path: Path) -> None:
    import gc

    import numpy as np
    import torch
    from sentence_transformers import SentenceTransformer

    from app.services.chat_engine import AdvisorEngine
    from app.services.knowledge import KnowledgeBase
    from app.services.semantic_search import SemanticIndex, SentenceTransformerEmbedder
    from app.services.vector_cache import PassageVectorCache, model_fingerprint

    settings = Settings(_env_file=None)
    if not settings.semantic_model_dir.is_dir():
        pytest.skip("Se requiere MiniLM local; no se descarga.")
    torch.set_num_threads(settings.ml_threads)
    original = SentenceTransformer(str(settings.semantic_model_dir), device="cpu", local_files_only=True)
    texts = [
        "Hola",
        "¿Instalan cámaras de seguridad?",
        "  cámaras de SEGURIDAD  ",
        "",
        "\n\t",
        "Educación, redes y asesoramiento en Cajamarca.",
        "áéíóú ñ 中文 English",
        "quiero información sobre redes " * 150,
    ] + [f"Información pública de servicios {'redes ' * i}" for i in range(18)]
    expected = original.encode(
        texts, batch_size=16, normalize_embeddings=True, convert_to_numpy=True, show_progress_bar=False
    )
    kb = KnowledgeBase.load(settings.knowledge_path, settings.intents_path)
    fingerprint = model_fingerprint(settings.semantic_model_dir, settings.semantic_model_name, "")
    cache = PassageVectorCache(tmp_path, fingerprint)

    class OriginalEmbedder:
        def __init__(self, model):
            self.model = model

        def encode(self, texts):
            return self.model.encode(
                texts,
                batch_size=16,
                normalize_embeddings=True,
                convert_to_numpy=True,
                show_progress_bar=False,
            )

    original_index = SemanticIndex(kb.search_records)
    original_index.vector_cache = cache
    original_index.attach(OriginalEmbedder(original))
    queries = [
        "hola",
        "¿Instalan cámaras de seguridad?",
        "Necesito redes para mi empresa",
        "¿Aceptan Yape?",
        "¿Me ayudan a terminar mi investigación de pregrado?",
        "¿Qué precio tiene la capacitación?",
        "receta de lomo saltado",
    ]
    engine = AdvisorEngine(kb, original_index)
    replies = [engine.chat(query).model_dump() for query in queries]
    matrix = original_index._matrix.copy()
    del engine, original_index, original
    gc.collect()

    optimized = SentenceTransformerEmbedder(settings.semantic_model_dir, settings.ml_threads)
    assert isinstance(optimized._model, MiniLMInference)
    actual = optimized.encode(texts)
    np.testing.assert_allclose(actual, expected, atol=1e-6, rtol=1e-5)
    assert actual.dtype == np.float32
    assert optimized.encode([]).shape == (0, 384)
    index = SemanticIndex(kb.search_records)
    index.attach(optimized)
    np.testing.assert_allclose(index._matrix, matrix, atol=1e-6, rtol=1e-5)
    assert [AdvisorEngine(kb, index).chat(query).model_dump() for query in queries] == replies
    # El reinicio puede reutilizar la caché creada por el cargador anterior.
    cached = SemanticIndex(kb.search_records)
    cached.vector_cache = cache
    cached.attach(optimized)
    np.testing.assert_array_equal(cached._matrix, matrix)
    assert [AdvisorEngine(kb, cached).chat(query).model_dump() for query in queries] == replies


@pytest.mark.semantic
def test_background_startup_reaches_ready_and_serves_authenticated_chat(tmp_path: Path) -> None:
    from fastapi.testclient import TestClient

    from app.main import create_app
    from tests.conftest import TEST_TOKEN

    settings = Settings(
        _env_file=None,
        service_token=TEST_TOKEN,
        semantic_enabled=True,
        semantic_cache_dir=tmp_path,
        gemini_api_key=None,
    )
    if not settings.semantic_model_dir.is_dir():
        pytest.skip("Se requiere MiniLM local; no se descarga.")
    with TestClient(create_app(settings)) as client:
        semantic = client.app.state.semantic
        assert semantic._loader_thread is not None
        semantic._loader_thread.join(timeout=60)
        assert semantic.ready
        assert isinstance(semantic._embedder._model, MiniLMInference)
        ready = client.get("/ready").json()
        assert ready["status"] == "ready" and ready["semantic"] == "loaded"
        assert client.post("/api/v1/chat", json={"message": "Hola"}).status_code == 401
        reply = client.post(
            "/api/v1/chat", json={"message": "Hola"}, headers={"X-Horus-Service-Token": TEST_TOKEN}
        )
        assert reply.status_code == 200
        assert reply.json()["mode"] == "hybrid"
        assert reply.json()["answer"] == client.app.state.engine.chat("Hola").answer
