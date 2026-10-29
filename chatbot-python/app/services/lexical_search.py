"""Búsqueda léxica determinista (sin dependencias de ML).

Puntuación = cobertura ponderada por IDF de los términos de la consulta en cada registro,
más un bono por frases clave completas. Es una medida de coincidencia, NO una probabilidad.
"""

from __future__ import annotations

import math
from dataclasses import dataclass

from app.core.text import (
    BRAND_TOKENS,
    contains_phrase,
    content_tokens,
    normalize,
    stem,
    tokenize,
    tokens_match,
)
from app.schemas.knowledge import KnowledgeRecord

FIELD_WEIGHTS = {"keyword": 1.0, "title": 0.9, "example": 0.8, "description": 0.5}


@dataclass(frozen=True)
class LexicalHit:
    record_id: str
    score: float
    phrase_hit: bool
    matched_terms: tuple[str, ...]


@dataclass
class _IndexedRecord:
    record: KnowledgeRecord
    terms: dict[str, float]  # raíz -> peso máximo de campo
    phrases: list[str]  # frases clave normalizadas


class LexicalIndex:
    def __init__(self, records: list[KnowledgeRecord], low_weight: frozenset[str] = frozenset()) -> None:
        # Marca y topónimos ("Cajamarca", "Perú") aparecen en preguntas ajenas: discriminan poco.
        self._low_weight = BRAND_TOKENS | frozenset(stem(t) for t in low_weight) | low_weight
        self._items: list[_IndexedRecord] = []
        doc_freq: dict[str, int] = {}
        for record in records:
            terms: dict[str, float] = {}
            fields = (
                ("keyword", " ".join(record.keywords)),
                ("title", record.title),
                ("example", " ".join(record.examples)),
                ("description", record.description),
            )
            for name, text in fields:
                for token in content_tokens(text):
                    terms[token] = max(terms.get(token, 0.0), FIELD_WEIGHTS[name])
            phrases = [normalize(k) for k in record.keywords if normalize(k)]
            self._items.append(_IndexedRecord(record, terms, phrases))
            for token in terms:
                doc_freq[token] = doc_freq.get(token, 0) + 1
        n = max(len(records), 1)
        self._idf = {t: math.log(1 + n / df) for t, df in doc_freq.items()}
        self._max_idf = math.log(1 + n)
        self.vocabulary: frozenset[str] = frozenset(
            tok
            for item in self._items
            for text in (
                " ".join(item.record.keywords),
                item.record.title,
                " ".join(item.record.examples),
                item.record.description,
            )
            for tok in tokenize(text)
        )

    def _idf_of(self, token: str) -> float:
        if token in self._low_weight:
            return 0.3 * self._idf.get(token, self._max_idf)
        if token in self._idf:
            return self._idf[token]
        matches = [v for t, v in self._idf.items() if tokens_match(token, t)]
        return max(matches) if matches else self._max_idf

    def search(self, query: str, limit: int = 5, record_ids: set[str] | None = None) -> list[LexicalHit]:
        normalized = normalize(query)
        q_tokens = list(dict.fromkeys(content_tokens(query)))
        if not q_tokens:
            return []
        total_idf = sum(self._idf_of(t) for t in q_tokens)
        hits: list[LexicalHit] = []
        for item in self._items:
            if record_ids is not None and item.record.id not in record_ids:
                continue
            gained = 0.0
            matched: list[str] = []
            for q in q_tokens:
                best = 0.0
                for term, weight in item.terms.items():
                    if weight > best and tokens_match(q, term):
                        best = weight
                if best:
                    gained += self._idf_of(q) * best
                    matched.append(q)
            phrase_words = max(
                (len(p.split()) for p in item.phrases if contains_phrase(normalized, p)), default=0
            )
            bonus = 0.0 if phrase_words == 0 else (0.25 if phrase_words == 1 else 0.35)
            coverage = gained / total_idf if total_idf else 0.0
            score = min(1.0, 0.75 * coverage + bonus)
            if score > 0:
                hits.append(LexicalHit(item.record.id, round(score, 4), phrase_words > 0, tuple(matched)))
        hits.sort(key=lambda h: (-h.score, h.record_id))
        return hits[:limit]
