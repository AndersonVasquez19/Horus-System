"""Carga y validación de la base de conocimiento y de las reglas.

Solo los registros con status="verified" pueden presentarse como hechos. Los "provisional" y
"unavailable" existen para reconocer la pregunta y responder con prudencia (abstención).
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from pydantic import BaseModel, ConfigDict, ValidationError

from app.schemas.knowledge import KnowledgeFile, KnowledgeRecord
from app.services.catalog import CatalogError, CatalogLoad, load_catalog


class KnowledgeError(RuntimeError):
    """El archivo de conocimiento no existe o no supera la validación."""


_EMAIL = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")
_PHONE = re.compile(r"\+?\d[\d\s\-]{7,}\d")
_ISO_DATE = re.compile(r"\b\d{4}-\d{2}-\d{2}(T[\d:.+\-]+)?\b")
_PRIVATE_MARKERS = re.compile(
    r"(contrase(n|ñ)a|password|api[_ ]?key|token|secret|dni\s*\d|matriculad[oa]s?\s+[A-ZÁÉÍÓÚ]|"
    r"sk-[A-Za-z0-9]{8,}|re_[A-Za-z0-9]{8,}|cotizaci[oó]n\s*(n[°º.o]|#|nro)\s*\d|cliente:|estudiante:)",
    re.IGNORECASE,
)
DEFAULT_PUBLIC_CONTACT = {"phone": "+51 927 582 305", "email": "horusgroupcajamarca@gmail.com"}
# Identificadores numéricos públicos admitidos (RUC de la empresa, publicado en el pie de página).
_ALLOWED_NUMBERS = {"20611010977"}


def find_privacy_violations(data: KnowledgeFile) -> list[str]:
    """Detecta correos, teléfonos o marcadores que no sean los canales públicos autorizados."""
    return find_record_privacy_violations(data.records, data.public_contact)


def find_record_privacy_violations(
    records: list[KnowledgeRecord], public_contact: dict[str, str] | None = None
) -> list[str]:
    contact = public_contact or DEFAULT_PUBLIC_CONTACT
    allowed_emails = {contact.get("email", "").lower()}
    allowed_phones = {re.sub(r"\D", "", contact.get("phone", ""))}
    problems: list[str] = []
    for record in records:
        text = " ".join(
            [
                record.title,
                record.description,
                record.answer,
                record.notes or "",
                *record.requirements,
                record.duration or "",
                record.schedule or "",
                record.public_price or "",
            ]
        )
        for email in _EMAIL.findall(text):
            if email.lower() not in allowed_emails:
                problems.append(f"{record.id}: correo no autorizado")
        for phone in _PHONE.findall(_ISO_DATE.sub(" ", text)):  # una fecha no es un teléfono
            digits = re.sub(r"\D", "", phone)
            if digits not in allowed_phones and digits not in _ALLOWED_NUMBERS:
                problems.append(f"{record.id}: número no autorizado")
        if _PRIVATE_MARKERS.search(text):
            problems.append(f"{record.id}: contiene un marcador de dato privado o secreto")
    return problems


def load_knowledge_file(path: Path) -> KnowledgeFile:
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise KnowledgeError(f"No existe el archivo de conocimiento: {path.name}") from exc
    except json.JSONDecodeError as exc:
        raise KnowledgeError(f"{path.name} no es JSON válido (línea {exc.lineno}).") from exc
    try:
        data = KnowledgeFile.model_validate(raw)
    except ValidationError as exc:
        raise KnowledgeError(
            f"{path.name} no supera la validación: {exc.error_count()} errores.\n{exc}"
        ) from exc
    problems = find_privacy_violations(data)
    if problems:
        raise KnowledgeError("Posibles datos privados en el conocimiento: " + "; ".join(problems))
    return data


class TemplateRule(BaseModel):
    model_config = ConfigDict(extra="forbid")
    name: str
    standalone: bool
    patterns: list[str]
    response: str


class HandoffRule(BaseModel):
    model_config = ConfigDict(extra="forbid")
    patterns: list[str]
    record_id: str


class RecommendationCues(BaseModel):
    model_config = ConfigDict(extra="forbid")
    learn: list[str]
    service: list[str]


class QuoteRules(BaseModel):
    model_config = ConfigDict(extra="forbid")
    patterns: list[str]
    private_patterns: list[str]
    checklist_label: str
    checklist: dict[str, list[str]]


class ObjectiveRule(BaseModel):
    model_config = ConfigDict(extra="forbid")
    label: str
    patterns: list[str]
    evidence_terms: list[str]


class IntentsFile(BaseModel):
    model_config = ConfigDict(extra="forbid")
    version: str
    description: str
    templates: list[TemplateRule]
    human_handoff: HandoffRule
    recommendation_cues: RecommendationCues
    topic_lexicon: dict[str, list[str]]
    generic_topics: list[str]
    level_lexicon: dict[str, list[str]]
    modality_lexicon: dict[str, list[str]]
    audience_lexicon: dict[str, list[str]]
    general_guidance: dict[str, str]
    attribute_lexicon: dict[str, list[str]]
    unavailable_attributes: dict[str, str]
    attribute_evidence_terms: dict[str, list[str]]
    reference_markers: list[str]
    topic_change_markers: list[str]
    quote: QuoteRules
    objective_lexicon: dict[str, ObjectiveRule]
    low_weight_tokens: list[str]


def load_intents_file(path: Path) -> IntentsFile:
    try:
        raw: Any = json.loads(path.read_text(encoding="utf-8"))
        return IntentsFile.model_validate(raw)
    except FileNotFoundError as exc:
        raise KnowledgeError(f"No existe el archivo de reglas: {path.name}") from exc
    except (json.JSONDecodeError, ValidationError) as exc:
        raise KnowledgeError(f"{path.name} no es válido: {exc}") from exc


@dataclass
class KnowledgeBase:
    data: KnowledgeFile
    intents: IntentsFile
    catalog: CatalogLoad | None = None
    by_id: dict[str, KnowledgeRecord] = field(init=False)
    _records: list[KnowledgeRecord] = field(init=False)

    def __post_init__(self) -> None:
        records = list(self.data.records)
        if self.catalog is not None:
            current_types = self.catalog.vigente_types
            # Un registro "no publicado aún" deja de valer cuando el catálogo publica ese tipo.
            records = [r for r in records if not set(r.superseded_by_catalog) & current_types]
            clash = {r.id for r in records} & {r.id for r in self.catalog.records}
            if clash:
                raise KnowledgeError(f"El catálogo repite identificadores del conocimiento: {sorted(clash)}")
            problems = find_record_privacy_violations(self.catalog.records, self.data.public_contact)
            if problems:
                raise KnowledgeError("Posibles datos privados en el catálogo: " + "; ".join(problems))
            records += self.catalog.records
        self._records = records
        self.by_id = {r.id: r for r in records}
        for attribute, record_id in self.intents.unavailable_attributes.items():
            target = self.by_id.get(record_id) or self.data_record(record_id)
            if target is not None and target.status != "unavailable":
                raise KnowledgeError(f"El atributo {attribute} debe apuntar a un registro 'unavailable'.")
        if self.intents.human_handoff.record_id not in self.by_id:
            raise KnowledgeError("La regla de derivación apunta a un registro inexistente.")
        handoff = self.by_id[self.intents.human_handoff.record_id]
        if handoff.status != "verified":
            raise KnowledgeError("La regla de derivación debe apuntar a un registro verificado.")

    @property
    def version(self) -> str:
        return self.data.version

    @property
    def records(self) -> list[KnowledgeRecord]:
        return self._records

    def data_record(self, record_id: str) -> KnowledgeRecord | None:
        return next((r for r in self.data.records if r.id == record_id), None)

    def verified(self) -> list[KnowledgeRecord]:
        return [r for r in self._records if r.status == "verified"]

    def recommendable(self) -> list[KnowledgeRecord]:
        return [r for r in self._records if r.recommendable and r.status == "verified"]

    def get(self, record_id: str) -> KnowledgeRecord | None:
        return self.by_id.get(record_id)

    def unavailable_record(self, attribute: str) -> KnowledgeRecord | None:
        record_id = self.intents.unavailable_attributes.get(attribute)
        return (self.by_id.get(record_id) or self.data_record(record_id)) if record_id else None

    @property
    def catalog_status(self) -> str:
        if self.catalog is None:
            return "none"
        return "stale" if self.catalog.summary.get("stale") else "loaded"

    @classmethod
    def load(
        cls,
        knowledge_path: Path,
        intents_path: Path,
        catalog_path: Path | None = None,
        signing_key: str | None = None,
        allow_unsigned_catalog: bool = False,
    ) -> KnowledgeBase:
        catalog = None
        if catalog_path is not None:
            try:
                catalog = load_catalog(catalog_path, signing_key, allow_unsigned_catalog)
            except CatalogError as exc:
                raise KnowledgeError(str(exc)) from exc
        return cls(load_knowledge_file(knowledge_path), load_intents_file(intents_path), catalog)
