"""Comprensión sin modelo (modo léxico determinista) y el índice semántico con un embedder simulado.

Las pruebas con el modelo real están en test_semantic_integration.py (marca `semantic`).
"""

from __future__ import annotations

import pytest

from app.services.chat_engine import AdvisorEngine
from app.services.semantic_search import SemanticIndex, normalize_cosine


def _top_source(engine: AdvisorEngine, message: str) -> str | None:
    reply = engine.chat(message)
    return reply.sources[0].id if reply.sources else None


@pytest.mark.parametrize(
    ("message", "expected"),
    [
        ("¿Cuál es su número de celular?", "contacto_canales"),
        ("¿Por dónde queda su local?", "ubicacion_sede"),
        ("¿Ponen cámaras de vigilancia en casas?", "servicio_camaras"),
        ("¿Hacen instalaciones de red para oficinas?", "servicio_cableado"),
        ("¿Aceptan pagos con Plin?", "faq_medios_pago"),
        ("¿Me pueden emitir factura?", "faq_comprobantes"),
    ],
)
def test_synonyms_and_reformulations(engine: AdvisorEngine, message: str, expected: str) -> None:
    assert _top_source(engine, message) == expected


@pytest.mark.parametrize(
    ("message", "expected"),
    [
        ("ubicasion de la sede", "ubicacion_sede"),
        ("numero de telefno", "contacto_canales"),
        ("acepatn yape?", "faq_medios_pago"),
        ("camaras de seguridda", "servicio_camaras"),
        ("mantenimeinto de computadoras para mi empresa", "servicio_soporte"),
    ],
)
def test_simple_misspellings(engine: AdvisorEngine, message: str, expected: str) -> None:
    reply = engine.chat(message)
    ids = [s.id for s in reply.sources] + [r.id for r in reply.recommendations]
    assert ids and ids[0] == expected, (message, reply.intent, ids)


@pytest.mark.parametrize(
    "message",
    [
        "¿Cuál es la capital de Francia?",
        "Recomiéndame una receta de pizza",
        "¿Quién ganó el partido de ayer?",
        "asdkjh qwe zxcmn",
        "¿Cuál es el sentido de la vida?",
    ],
)
def test_irrelevant_questions_do_not_match(engine: AdvisorEngine, message: str) -> None:
    reply = engine.chat(message)
    assert reply.sources == [] and reply.recommendations == []
    assert reply.abstained or reply.needs_clarification


@pytest.mark.parametrize(
    "message",
    [
        "¿Cuánto cuesta el curso?",
        "¿Cuándo empieza el próximo curso?",
        "¿Quedan cupos?",
        "¿Tienen descuentos o becas?",
    ],
)
def test_unavailable_information_abstains(engine: AdvisorEngine, message: str) -> None:
    reply = engine.chat(message)
    assert reply.intent == "unavailable_info" and reply.abstained


class _FakeEmbedder:
    """Embedder determinista: vector one-hot por palabra clave. Solo para probar el cableado."""

    vocab = ("camara", "red", "pago", "tesis")

    def encode(self, texts: list[str]):  # type: ignore[no-untyped-def]
        import numpy as np

        rows = []
        for text in texts:
            low = text.lower()
            v = np.array([1.0 if w in low else 0.0 for w in self.vocab]) + 1e-6
            rows.append(v / np.linalg.norm(v))
        return np.vstack(rows)


def test_semantic_index_with_fake_embedder(engine: AdvisorEngine) -> None:
    pytest.importorskip("numpy")
    index = SemanticIndex(engine.kb.records)
    assert index.status == "disabled" and index.search("cámaras") == {}
    index.attach(_FakeEmbedder())
    assert index.ready
    hits = index.search("quiero una camara")
    best = max(hits.values(), key=lambda h: h.cosine)
    assert engine.kb.get(best.record_id).topics  # type: ignore[union-attr]
    assert 0.0 <= best.score <= 1.0


def test_missing_model_falls_back_to_lexical(engine: AdvisorEngine, tmp_path) -> None:  # type: ignore[no-untyped-def]
    index = SemanticIndex(engine.kb.records)
    assert index.load_in_background(tmp_path / "no-existe", threads=1) is None
    assert index.status == "unavailable"
    hybrid_engine = AdvisorEngine(engine.kb, index)
    assert hybrid_engine.mode == "lexical"
    assert hybrid_engine.chat("¿Aceptan Yape?").sources[0].id == "faq_medios_pago"


def test_cosine_normalization_is_bounded() -> None:
    assert normalize_cosine(-1.0) == 0.0
    assert normalize_cosine(1.0) == 1.0
    assert 0.0 < normalize_cosine(0.55) < 1.0
