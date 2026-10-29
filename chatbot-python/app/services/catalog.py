"""Carga, verificación y traducción del catálogo público (contrato en app/schemas/catalog.py).

Flujo previsto (futuro): Laravel exporta un JSON firmado con HMAC-SHA256 -> se deja en disco
(HORUS_AI_CATALOG_PATH) -> este servicio lo valida al arrancar y lo combina con data/knowledge.json.
No hay conexión a PostgreSQL ni a Laravel: solo se lee un archivo.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import re
from collections import Counter
from dataclasses import dataclass, field
from datetime import UTC, date, datetime
from pathlib import Path
from typing import Any

from pydantic import ValidationError

from app.schemas.catalog import CatalogItem, PublicCatalog
from app.schemas.knowledge import KnowledgeRecord, Source

CATEGORY = {
    "curso": "formacion",
    "capacitacion": "formacion",
    "servicio": "servicio",
    "asesoria": "asesoramiento",
    "modalidad": "formacion",
    "requisito": "formacion",
    "canal_consulta": "contacto",
    "info_comercial": "empresa",
}
KIND = {
    "curso": "course",
    "capacitacion": "training_program",
    "servicio": "service",
    "asesoria": "advisory",
    "modalidad": "course_modality",
    "requisito": "info",
    "canal_consulta": "info",
    "info_comercial": "info",
}
RECOMMENDABLE_TYPES = {"curso", "capacitacion", "servicio", "asesoria"}
SIGNED_FIELDS = ("schema_version", "catalog_version", "source_system", "generated_at", "expires_at", "items")
MODALITY_LABEL = {
    "online": "online",
    "virtual": "virtual",
    "presencial": "presencial",
    "semipresencial": "semipresencial",
}


class CatalogError(RuntimeError):
    """El catálogo no existe, no es válido o su firma no coincide."""


def canonical_bytes(raw: dict[str, Any]) -> bytes:
    """Serialización estable de los campos firmados (Laravel debe reproducirla igual)."""
    payload = {k: raw[k] for k in SIGNED_FIELDS if k in raw}
    return json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def sign(raw: dict[str, Any], key: str) -> str:
    return "hmac-sha256:" + hmac.new(key.encode(), canonical_bytes(raw), hashlib.sha256).hexdigest()


def signature_status(raw: dict[str, Any], key: str | None) -> str:
    provided = raw.get("signature")
    if key is None:
        return "not_checked"
    if not isinstance(provided, str):
        return "missing"
    return "valid" if hmac.compare_digest(provided, sign(raw, key)) else "invalid"


def record_id_for(stable_id: str) -> str:
    return ("cat_" + re.sub(r"[^a-z0-9]+", "_", stable_id))[:64].rstrip("_")


@dataclass
class CatalogLoad:
    catalog: PublicCatalog
    records: list[KnowledgeRecord]
    summary: dict[str, Any] = field(default_factory=dict)

    @property
    def vigente_types(self) -> set[str]:
        return {r.stable_id.split(":")[0] for r in self.records if r.status == "verified" and r.stable_id}


def _window_ok(item: CatalogItem, today: date) -> bool:
    return (item.valid_from is None or item.valid_from <= today) and (
        item.valid_until is None or today <= item.valid_until
    )


def _answer(item: CatalogItem, catalog: PublicCatalog) -> str:
    parts = [f"{item.title}: {item.summary}"]
    if item.modalities:
        parts.append("Modalidad publicada: " + ", ".join(MODALITY_LABEL[m] for m in item.modalities) + ".")
    if item.duration_text:
        parts.append(f"Duración publicada: {item.duration_text}.")
    if item.schedule_text:
        parts.append(f"Horario publicado: {item.schedule_text}.")
    if item.requirements:
        parts.append("Requisitos publicados: " + "; ".join(item.requirements) + ".")
    if item.public_price is not None:
        p = item.public_price
        parts.append(f"Precio público: {p.currency} {p.amount} ({p.conditions}).")
    if item.inquiry:
        parts.append(f"Consultas: {item.inquiry}.")
    parts.append(
        f"Fuente: catálogo publicado por Horus Group (versión {catalog.catalog_version}, "
        f"actualizado el {item.updated_at.date().isoformat()})."
    )
    return " ".join(parts)[:1500]


def to_records(catalog: PublicCatalog, now: datetime) -> tuple[list[KnowledgeRecord], dict[str, Any]]:
    today = now.astimezone(UTC).date()
    stale = catalog.is_expired(now)
    records: list[KnowledgeRecord] = []
    outcome: Counter[str] = Counter()
    for item in catalog.items:
        if item.publication_status == "no_publicado":
            outcome["descartado_no_publicado"] += 1
            continue  # nunca se menciona
        status: str
        answer = _answer(item, catalog)
        if item.publication_status == "vigente" and _window_ok(item, today) and not stale:
            status = "verified"
            outcome["vigente"] += 1
        elif item.publication_status == "vigente" and stale:
            status = "provisional"
            answer = (
                f"La información de «{item.title}» está pendiente de revalidación; confírmala con un asesor."
            )
            outcome["vigente_pero_catalogo_caducado"] += 1
        elif item.publication_status == "vigente":
            status = "unavailable"
            answer = f"«{item.title}» no está vigente en este momento según el catálogo publicado. Consulta alternativas con un asesor."
            outcome["fuera_de_ventana"] += 1
        elif item.publication_status == "retirado":
            status = "unavailable"
            answer = f"«{item.title}» figura como retirado: ya no está vigente. Un asesor puede orientarte sobre alternativas."
            outcome["retirado"] += 1
        else:
            status = "provisional"
            answer = f"No puedo confirmar la información de «{item.title}»; consúltala con un asesor."
            outcome["desconocido"] += 1
        records.append(
            KnowledgeRecord(
                id=record_id_for(item.stable_id),
                category=CATEGORY[item.type],
                kind=KIND[item.type],
                title=item.title,
                description=(item.summary + (" " + item.details if item.details else ""))[:1500],
                answer=answer,
                status=status,
                source=Source(
                    kind="catalogo_laravel",
                    url_path=item.url_path,
                    file=f"catalogo:{catalog.catalog_version}",
                    checked_on=item.updated_at.date().isoformat(),
                ),
                keywords=list(dict.fromkeys([*item.keywords, item.title])),
                topics=list(item.topics),
                audience=list(item.audience),
                modalities=list(item.modalities),
                levels=list(item.levels),
                recommendable=status == "verified" and item.type in RECOMMENDABLE_TYPES and bool(item.topics),
                requirements=list(item.requirements) if status == "verified" else [],
                duration=item.duration_text if status == "verified" else None,
                schedule=item.schedule_text if status == "verified" else None,
                public_price=(
                    f"{item.public_price.currency} {item.public_price.amount} ({item.public_price.conditions})"
                    if status == "verified" and item.public_price
                    else None
                ),
                updated_at=item.updated_at.isoformat(),
                stable_id=item.stable_id,
            )
        )
    listing = _listing_record(catalog, records)
    if listing is not None:
        records.append(listing)
    summary = {
        "catalog_version": catalog.catalog_version,
        "stale": stale,
        "items": len(catalog.items),
        "outcome": dict(outcome),
        "by_type": dict(Counter(i.type for i in catalog.items)),
    }
    return records, summary


def _listing_record(catalog: PublicCatalog, records: list[KnowledgeRecord]) -> KnowledgeRecord | None:
    """Registro sintético con la lista de cursos y capacitaciones vigentes (solo títulos verificados)."""
    current = [r for r in records if r.status == "verified" and r.kind in {"course", "training_program"}]
    if not current:
        return None
    titles = "; ".join(r.title for r in current[:15])
    return KnowledgeRecord(
        id="cat_listado_formacion",
        category="formacion",
        kind="info",
        title="Cursos y capacitaciones vigentes",
        description=f"Cursos y capacitaciones vigentes según el catálogo publicado: {titles}.",
        answer=(
            f"Según el catálogo publicado por Horus Group (versión {catalog.catalog_version}), están vigentes: "
            f"{titles}. Pregúntame por cualquiera para ver su modalidad o requisitos publicados."
        )[:1500],
        status="verified",
        source=Source(
            kind="catalogo_laravel",
            url_path="/educacion/cursos",
            file=f"catalogo:{catalog.catalog_version}",
            checked_on=catalog.generated_at.date().isoformat(),
        ),
        keywords=[
            "que cursos hay",
            "que cursos tienen",
            "cursos disponibles",
            "cursos vigentes",
            "lista de cursos",
            "cursos abiertos",
            "que capacitaciones hay",
            "catalogo de cursos",
        ],
        topics=["cursos", "formacion"],
    )


def parse_catalog(raw: Any, signing_key: str | None, allow_unsigned: bool) -> PublicCatalog:
    if not isinstance(raw, dict):
        raise CatalogError("El catálogo debe ser un objeto JSON.")
    status = signature_status(raw, signing_key)
    if status in {"missing", "invalid"}:
        raise CatalogError(f"Firma del catálogo {'ausente' if status == 'missing' else 'inválida'}.")
    if status == "not_checked" and not allow_unsigned:
        raise CatalogError("No hay clave de firma configurada (HORUS_AI_CATALOG_SIGNING_KEY).")
    try:
        return PublicCatalog.model_validate(raw)
    except ValidationError as exc:
        raise CatalogError(f"Catálogo inválido: {exc.error_count()} errores.\n{exc}") from exc


def load_catalog(
    path: Path, signing_key: str | None, allow_unsigned: bool = False, now: datetime | None = None
) -> CatalogLoad:
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise CatalogError(f"No existe el catálogo: {path.name}") from exc
    except json.JSONDecodeError as exc:
        raise CatalogError(f"{path.name} no es JSON válido (línea {exc.lineno}).") from exc
    catalog = parse_catalog(raw, signing_key, allow_unsigned)
    records, summary = to_records(catalog, now or datetime.now(UTC))
    return CatalogLoad(catalog, records, summary)


def validate_payload(
    raw: Any,
    signing_key: str | None,
    public_contact: dict[str, str] | None = None,
    now: datetime | None = None,
) -> dict[str, Any]:
    """Validación en seco (no carga nada). Devuelve un informe sin eco del contenido."""
    report: dict[str, Any] = {"valid": False, "errors": [], "signature": "not_checked", "summary": None}
    if isinstance(raw, dict):
        report["signature"] = signature_status(raw, signing_key)
    try:
        catalog = PublicCatalog.model_validate(raw)
    except ValidationError as exc:
        report["errors"] = [
            {"field": ".".join(str(p) for p in e.get("loc", ())), "message": str(e.get("msg", ""))}
            for e in exc.errors()[:20]
        ]
        return report
    from app.services.knowledge import find_record_privacy_violations

    records, summary = to_records(catalog, now or datetime.now(UTC))
    privacy = find_record_privacy_violations(records, public_contact)
    report["errors"] = [{"field": "items", "message": m} for m in privacy]
    report["summary"] = summary
    report["valid"] = not privacy and report["signature"] in {"valid", "not_checked"}
    return report
