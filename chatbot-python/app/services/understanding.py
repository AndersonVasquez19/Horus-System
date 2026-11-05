"""Análisis explícito del mensaje: qué se pregunta (atributos), si remite a algo anterior, si cambia
de tema y si es una solicitud comercial. Todo con léxicos de data/intents.json; sin modelos.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

from app.core.text import contains_phrase, normalize, strip_accents, tokenize
from app.schemas.knowledge import KnowledgeRecord
from app.services.knowledge import KnowledgeBase

MODALITY_WORDS = {
    "presencial": ("presencial", "en persona"),
    "semipresencial": ("semipresencial", "hibrido", "mixto"),
    "virtual": ("virtual", "online", "en linea", "a distancia", "remoto", "desde casa"),
}
AUDIENCE_WORDS = {
    "persona": (
        "para casas",
        "para casa",
        "para hogares",
        "para mi casa",
        "para personas",
        "para particulares",
    ),
    "empresa": ("para empresas", "para negocios", "para instituciones", "para mi empresa"),
}


@dataclass(frozen=True)
class Understanding:
    attributes: tuple[str, ...]
    referential: bool
    topic_change: bool
    quote: bool
    private_quote: bool
    asked_modalities: tuple[str, ...]
    asked_audiences: tuple[str, ...]
    short: bool

    @property
    def is_question_about_attribute(self) -> bool:
        return bool(self.attributes)


def _any(normalized: str, phrases: list[str] | tuple[str, ...]) -> bool:
    return any(contains_phrase(normalized, p) for p in phrases)


def understand(text: str, kb: KnowledgeBase) -> Understanding:
    rules = kb.intents
    normalized = normalize(text)
    tokens = tokenize(text)
    attributes = tuple(name for name, phrases in rules.attribute_lexicon.items() if _any(normalized, phrases))
    starts_with_y = bool(re.match(r"^(y|e)\s", normalized))
    referential = _any(normalized, rules.reference_markers) or (starts_with_y and len(tokens) <= 9)
    private_quote = _any(normalized, rules.quote.private_patterns)
    return Understanding(
        attributes=attributes,
        referential=referential,
        topic_change=_any(normalized, rules.topic_change_markers),
        quote=private_quote or _any(normalized, rules.quote.patterns),
        private_quote=private_quote,
        asked_modalities=tuple(m for m, words in MODALITY_WORDS.items() if _any(normalized, words)),
        asked_audiences=tuple(a for a, words in AUDIENCE_WORDS.items() if _any(normalized, words)),
        short=len(tokens) <= 10,
    )


def evidence_sentence(record: KnowledgeRecord, terms: list[str]) -> str | None:
    """Primera frase del registro verificado que contiene alguno de los términos (extracción literal)."""
    norm_terms = [normalize(t) for t in terms if normalize(t)]
    for text in (record.answer, record.description):
        for sentence in re.split(r"(?<=[.;])\s+", text):
            sentence = sentence.strip()
            folded = strip_accents(sentence.lower())  # misma longitud que el original
            found = [folded.find(t) for t in norm_terms if t in folded]
            if not found:
                continue
            if len(sentence) <= 300:
                return sentence
            start = max(0, min(found) - 140)  # recorte centrado en el término, no truncado al final
            end = min(len(sentence), start + 300)
            return ("…" if start else "") + sentence[start:end].strip() + ("…" if end < len(sentence) else "")
    return None


def record_covers_attribute(record: KnowledgeRecord, attribute: str, kb: KnowledgeBase) -> bool:
    """¿El texto verificado del registro trata ese atributo? (p. ej. 'fechas' en la FAQ de evaluaciones)."""
    text = normalize(" ".join([record.answer, *record.keywords]))
    return _any(text, kb.intents.attribute_lexicon.get(attribute, []))
