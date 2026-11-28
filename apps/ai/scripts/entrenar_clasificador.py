"""Entrena el clasificador de respaldo y lo exporta a data/clasificador.json (27/11/2026).

Uso (entorno local con requirements-semantic.lock, que trae scikit-learn):
    python scripts/entrenar_clasificador.py            # entrena, valida y escribe
    python scripts/entrenar_clasificador.py --solo-medir

Datos de entrenamiento: ejemplos y títulos de cada ficha, la parte 'dev' de evaluation.json y los bancos
oraciones_desarrollo, oraciones_visitante y oraciones_pruebas_870 (preguntas sueltas y primer turno de cada
conversación). NUNCA se entrena con oraciones_examen, oraciones_examen_sellado ni la parte 'test' de evaluation.json:
esos conjuntos miden.
"""

from __future__ import annotations

import argparse
import base64
import json
import sys
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.core.text import normalize  # noqa: E402
from app.services.intent_classifier import OTHER, IntentClassifier  # noqa: E402

DATA = ROOT / "data"
OUT = DATA / "clasificador.json"
TRAIN_BANKS = ("oraciones_desarrollo", "oraciones_visitante", "oraciones_pruebas_870")
HELD_OUT = ("oraciones_examen", "oraciones_examen_sellado")
CHAR_RANGE, WORD_RANGE, C = (2, 5), (1, 2), 0.5


def _labels() -> set[str]:
    kb = json.loads((DATA / "knowledge.json").read_text(encoding="utf-8"))
    # Una ficha reemplazada por el catálogo de Laravel no es una respuesta válida por sí sola.
    return {r["id"] for r in kb["records"] if not r.get("superseded_by_catalog")}


def _label(expected: list[str], ids: set[str]) -> str | None:
    found = next((e for e in expected if e in ids), None)
    if found is None and "FUERA" in expected:
        return OTHER
    return found


def training_set() -> list[tuple[str, str]]:
    ids = _labels()
    kb = json.loads((DATA / "knowledge.json").read_text(encoding="utf-8"))
    rows = [(t, r["id"]) for r in kb["records"] if r["id"] in ids for t in [r["title"], *r["examples"]]]
    for item in json.loads((DATA / "evaluation.json").read_text(encoding="utf-8"))["items"]:
        if item["split"] != "dev":
            continue
        label = next((e for e in item["expected_ids"] if e in ids), None)
        if label is None and "out_of_scope" in item.get("behaviors", []):
            label = OTHER
        if label:
            rows.append((item["query"], label))
    for bank in TRAIN_BANKS:
        assert bank not in HELD_OUT
        raw = json.loads((DATA / f"{bank}.json").read_text(encoding="utf-8"))
        cases = [(c["texto"], c["esperado"]) for c in raw["casos"]]
        cases += [(conv[0]["texto"], conv[0]["esperado"]) for conv in raw.get("conversaciones", []) if conv]
        rows += [(t, label) for t, e in cases if (label := _label(e, ids))]
    # Una frase que también está en un examen («¿cómo me inscribo?») no se usa para entrenar: el examen sigue limpio.
    held_out = held_out_texts()
    return [(t, label) for t, label in rows if normalize(t) not in held_out]


def held_out_texts() -> set[str]:
    texts: set[str] = set()
    for bank in HELD_OUT:
        raw = json.loads((DATA / f"{bank}.json").read_text(encoding="utf-8"))
        texts.update(normalize(c["texto"]) for c in raw["casos"])
        texts.update(normalize(t["texto"]) for conv in raw.get("conversaciones", []) for t in conv)
    texts.update(normalize(i["query"]) for i in json.loads((DATA / "evaluation.json").read_text(encoding="utf-8"))["items"]
                 if i["split"] == "test")
    return texts


def train(rows: list[tuple[str, str]]):  # type: ignore[no-untyped-def]
    from sklearn.feature_extraction.text import TfidfVectorizer
    from sklearn.pipeline import make_union
    from sklearn.svm import LinearSVC

    union = make_union(
        TfidfVectorizer(analyzer="char_wb", ngram_range=CHAR_RANGE, min_df=2, sublinear_tf=True, lowercase=False),
        TfidfVectorizer(analyzer="word", ngram_range=WORD_RANGE, min_df=1, sublinear_tf=True, lowercase=False),
    )
    texts = [normalize(t) for t, _ in rows]
    model = LinearSVC(C=C, random_state=0).fit(union.fit_transform(texts), [label for _, label in rows])
    return union, model


def export(union, model) -> dict[str, object]:  # type: ignore[no-untyped-def]
    vectorizers, weights, offset = [], [], 0
    for _, vec in union.transformer_list:
        size = len(vec.vocabulary_)
        vectorizers.append({
            "analyzer": vec.analyzer, "ngram_range": list(vec.ngram_range),
            "vocabulary": {k: int(v) for k, v in sorted(vec.vocabulary_.items(), key=lambda kv: kv[1])},
            "idf": [round(float(x), 6) for x in vec.idf_],
        })
        # Matriz [rasgo][clase] en float32, en base64: ~2 MB en memoria en vez de ~40 MB de listas de Python.
        coef = model.coef_[:, offset:offset + size].T.astype("<f4")
        weights.append(base64.b64encode(coef.tobytes()).decode("ascii"))
        offset += size
    return {
        "version": date.today().isoformat(),
        "description": "Clasificador de respaldo (scripts/entrenar_clasificador.py). Solo propone ids de fichas.",
        "classes": [str(c) for c in model.classes_],
        "temperature": 3.0,
        "vectorizers": vectorizers,
        "intercept": [round(float(x), 6) for x in model.intercept_],
        "weights": weights,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--solo-medir", action="store_true")
    args = parser.parse_args()
    rows = training_set()
    union, model = train(rows)
    raw = export(union, model)
    clf = IntentClassifier(raw)
    # La inferencia en Python puro debe coincidir con scikit-learn.
    sample = [normalize(t) for t, _ in rows[::7]]
    expected = model.decision_function(union.transform(sample))
    worst = max(abs(a - b) for row, text in zip(expected, sample, strict=True) for a, b in zip(row, clf.scores(text), strict=True))
    print(f"muestras {len(rows)} · clases {len(raw['classes'])} · diferencia máxima con scikit-learn {worst:.5f}")
    if worst > 1e-2:
        raise SystemExit("La inferencia en Python puro no coincide con scikit-learn")
    if not args.solo_medir:
        OUT.write_text(json.dumps(raw, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
        print(f"escrito {OUT.relative_to(ROOT)} ({OUT.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    main()
