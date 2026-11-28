"""Clasificador de respaldo: lleva una pregunta parafraseada a la ficha que trata (27/11/2026).

- Se entrena fuera de línea con scikit-learn (`scripts/entrenar_clasificador.py`): TF-IDF de trozos de letras y de
  palabras + SVM lineal. El resultado se exporta a `data/clasificador.json` (vocabulario, idf y pesos).
- Aquí solo se hace la inferencia, en Python puro: producción no necesita scikit-learn, NumPy ni más memoria.
- Solo propone el id de una ficha de `knowledge.json`. Nunca genera texto: el motor responde con el texto exacto de
  esa ficha, y Laravel lo sigue validando igual que cualquier otra respuesta.
- El motor solo lo consulta cuando iba a pedir una aclaración, y exige confianza mínima y distancia con la segunda
  opción (ver `AdvisorEngine._classifier_fallback`).
"""

from __future__ import annotations

import base64
import json
import math
import re
import sys
from array import array
from collections import Counter
from dataclasses import dataclass
from pathlib import Path

from app.core.text import normalize

OTHER = "__otro__"
_WORD = re.compile(r"(?u)\b\w\w+\b")  # token_pattern por defecto de scikit-learn


@dataclass(frozen=True)
class Prediction:
    label: str
    confidence: float
    margin: float


def _char_wb(text: str, low: int, high: int) -> list[str]:
    """Igual que `analyzer="char_wb"` de scikit-learn."""
    grams: list[str] = []
    for word in text.split():
        w = f" {word} "
        for n in range(low, high + 1):
            offset = 0
            grams.append(w[offset:offset + n])
            while offset + n < len(w):
                offset += 1
                grams.append(w[offset:offset + n])
            if offset == 0:
                break
    return grams


def _words(text: str, low: int, high: int) -> list[str]:
    tokens = _WORD.findall(text)
    grams: list[str] = []
    for n in range(low, high + 1):
        grams += [" ".join(tokens[i:i + n]) for i in range(len(tokens) - n + 1)]
    return grams


class _Vectorizer:
    def __init__(self, raw: dict[str, object]) -> None:
        self.analyzer = str(raw["analyzer"])
        low, high = raw["ngram_range"]  # type: ignore[misc]
        self.low, self.high = int(low), int(high)
        self.vocabulary: dict[str, int] = dict(raw["vocabulary"])  # type: ignore[arg-type]
        self.idf: list[float] = list(raw["idf"])  # type: ignore[arg-type]

    def transform(self, text: str) -> dict[int, float]:
        grams = _char_wb(text, self.low, self.high) if self.analyzer == "char_wb" else _words(text, self.low, self.high)
        counts = Counter(g for g in grams if g in self.vocabulary)
        vector = {self.vocabulary[g]: (1.0 + math.log(c)) * self.idf[self.vocabulary[g]] for g, c in counts.items()}
        norm = math.sqrt(sum(v * v for v in vector.values()))
        return {i: v / norm for i, v in vector.items()} if norm else {}


class IntentClassifier:
    def __init__(self, raw: dict[str, object]) -> None:
        self.version = str(raw["version"])
        self.classes: list[str] = list(raw["classes"])  # type: ignore[arg-type]
        self.temperature = float(raw.get("temperature", 3.0))  # type: ignore[arg-type]
        self.vectorizers = [_Vectorizer(v) for v in raw["vectorizers"]]  # type: ignore[union-attr]
        self.intercept: list[float] = list(raw["intercept"])  # type: ignore[arg-type]
        # Pesos por bloque de vectorizador: matriz [rasgo][clase] en float32 little-endian, codificada en base64.
        self.weights: list[array[float]] = []
        for block, vectorizer in zip(raw["weights"], self.vectorizers, strict=True):  # type: ignore[call-overload]
            matrix = array("f")
            matrix.frombytes(base64.b64decode(block))
            if sys.byteorder != "little":
                matrix.byteswap()
            if len(matrix) != len(vectorizer.idf) * len(self.classes):
                raise ValueError("data/clasificador.json está incompleto: vuelve a entrenarlo")
            self.weights.append(matrix)

    @classmethod
    def load(cls, path: Path) -> IntentClassifier:
        return cls(json.loads(path.read_text(encoding="utf-8")))

    def scores(self, message: str) -> list[float]:
        text = normalize(message)
        totals = list(self.intercept)
        n = len(self.classes)
        for vectorizer, matrix in zip(self.vectorizers, self.weights, strict=True):
            for feature, value in vectorizer.transform(text).items():
                row = matrix[feature * n:(feature + 1) * n]
                for cls_index in range(n):
                    totals[cls_index] += value * row[cls_index]
        return totals

    def predict(self, message: str) -> Prediction:
        scores = self.scores(message)
        top = max(scores)
        exps = [math.exp((s - top) * self.temperature) for s in scores]
        total = sum(exps)
        probs = sorted(((e / total, i) for i, e in enumerate(exps)), reverse=True)
        (p1, i1), (p2, _) = probs[0], probs[1]
        return Prediction(label=self.classes[i1], confidence=p1, margin=p1 - p2)
