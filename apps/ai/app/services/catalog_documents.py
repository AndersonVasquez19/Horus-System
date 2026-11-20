"""Documentos públicos inmutables y fragmentos con procedencia del snapshot firmado."""

from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass
from datetime import UTC, date, datetime

from app.schemas.catalog import CatalogItem
from app.schemas.knowledge import KnowledgeRecord

CHUNK_CHARS = 600


def split_content(content: str) -> tuple[str, ...]:
    """Conserva un texto pequeño unido; limita párrafos grandes sin perder palabras."""
    normalized = " ".join(content.split())
    if len(normalized) <= CHUNK_CHARS:
        return (normalized,) if normalized else ()
    chunks = []
    for paragraph in re.split(r"\n\s*\n", content):
        text = " ".join(paragraph.split())
        while len(text) > CHUNK_CHARS:
            end = text.rfind(" ", 0, CHUNK_CHARS + 1)
            end = end if end > 0 else CHUNK_CHARS
            chunks.append(text[:end])
            text = text[end:].strip()
        if text:
            chunks.append(text)
    packed: list[str] = []
    for chunk in chunks:
        if packed and len(packed[-1]) + 1 + len(chunk) <= CHUNK_CHARS:
            packed[-1] += " " + chunk
        else:
            packed.append(chunk)
    return tuple(packed)


@dataclass(frozen=True)
class PublicFragment:
    id: str
    source_id: str
    stable_id: str
    version: str
    ordinal: int
    text: str
    answer: str


@dataclass(frozen=True)
class PublicDocument:
    stable_id: str
    source_id: str
    content_type: str
    title: str
    description: str
    content: str
    category: str
    modalities: tuple[str, ...]
    publication_status: str
    version: str
    updated_at: str
    valid_from: date | None
    valid_until: date | None
    fragments: tuple[PublicFragment, ...]

    def current(self) -> bool:
        today = datetime.now(UTC).date()
        return (
            self.publication_status == "vigente"
            and (self.valid_from is None or self.valid_from <= today)
            and (self.valid_until is None or today <= self.valid_until)
        )


def document_for(item: CatalogItem, record: KnowledgeRecord, version: str) -> PublicDocument:
    content = item.details or item.summary
    fragments = []
    for ordinal, text in enumerate(split_content(content)):
        digest = hashlib.sha256(f"{record.id}|{version}|{ordinal}|{text}".encode()).hexdigest()[:40]
        answer = f"{item.title}: {text} Fuente: catálogo publicado por Horus Group actualizado el {item.updated_at.date().isoformat()})."
        fragments.append(
            PublicFragment("frag_" + digest, record.id, item.stable_id, version, ordinal, text, answer)
        )
    return PublicDocument(
        item.stable_id,
        record.id,
        item.type,
        item.title,
        item.summary,
        content,
        record.category,
        tuple(item.modalities),
        item.publication_status,
        version,
        item.updated_at.isoformat(),
        item.valid_from,
        item.valid_until,
        tuple(fragments),
    )
