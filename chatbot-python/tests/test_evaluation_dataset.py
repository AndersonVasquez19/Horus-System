"""Calidad del dataset de evaluación: no reutiliza las formulaciones del conocimiento y es coherente."""

from __future__ import annotations

import json

from app.core.config import DATA_DIR
from app.core.text import normalize
from app.services.knowledge import KnowledgeBase

DATASET = json.loads((DATA_DIR / "evaluation.json").read_text(encoding="utf-8"))
VALID_BEHAVIORS = set(DATASET["behaviors"])


def test_queries_do_not_copy_knowledge_examples_or_keywords(kb: KnowledgeBase) -> None:
    seen = {normalize(t) for r in kb.records for t in (*r.examples, *r.keywords)}
    seen |= {normalize(p) for t in kb.intents.templates for p in t.patterns}
    leaked = [i["id"] for i in DATASET["items"] if normalize(i["query"]) in seen]
    assert leaked == [], f"Consultas idénticas a ejemplos/palabras clave: {leaked}"


def test_items_are_well_formed(kb: KnowledgeBase) -> None:
    ids = [i["id"] for i in DATASET["items"]]
    assert len(ids) == len(set(ids))
    for item in DATASET["items"]:
        assert item["split"] in {"dev", "test"}
        assert item["behaviors"] and set(item["behaviors"]) <= VALID_BEHAVIORS, item["id"]
        assert item["intents"], item["id"]
        for rid in item["expected_ids"]:
            assert kb.get(rid) is not None, f"{item['id']}: {rid} no existe"


def test_splits_are_balanced_and_cover_categories() -> None:
    items = DATASET["items"]
    dev = [i for i in items if i["split"] == "dev"]
    test = [i for i in items if i["split"] == "test"]
    assert len(items) >= 100
    assert abs(len(dev) - len(test)) <= len(items) * 0.1
    required = {
        "directa",
        "reformulada",
        "errores",
        "coloquial",
        "incompleta",
        "ambigua",
        "irrelevante",
        "no_disponible",
        "recomendacion",
        "seguridad",
    }
    assert required <= {i["category"] for i in items}
    assert required <= {i["category"] for i in test}
