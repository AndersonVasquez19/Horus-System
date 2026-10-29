"""Contrato del catálogo público que Laravel podrá exportar en el futuro (NO implementado en Laravel).

Solo admite información PÚBLICA y autorizada: cursos, capacitaciones, servicios, asesorías,
modalidades, requisitos, canales de consulta e información comercial publicada. No admite
cotizaciones, clientes, estudiantes, matrículas, cupos internos ni datos de usuarios: cualquier
campo no declarado se rechaza (extra="forbid").

Estados de publicación:
  - vigente       -> se presenta como hecho (si está dentro de su ventana y el catálogo no caducó).
  - retirado      -> se informa que ya no está vigente; nunca se recomienda.
  - no_publicado  -> se descarta por completo: el asistente no lo menciona.
  - desconocido   -> no se afirma (equivale a "provisional").
"""

from __future__ import annotations

from datetime import date, datetime, timedelta
from decimal import Decimal
from typing import Literal

from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, model_validator

from app.schemas.knowledge import AudienceName, Level, ModalityName, Topic

SCHEMA_VERSION = "1.0"
MAX_CATALOG_LIFETIME = timedelta(days=90)

PublicationStatus = Literal["vigente", "retirado", "no_publicado", "desconocido"]
ItemType = Literal[
    "curso",
    "capacitacion",
    "servicio",
    "asesoria",
    "modalidad",
    "requisito",
    "canal_consulta",
    "info_comercial",
]
STABLE_ID_PATTERN = (
    r"^(curso|capacitacion|servicio|asesoria|modalidad|requisito|canal|comercial):[a-z0-9][a-z0-9\-]{1,50}$"
)


class PublicPrice(BaseModel):
    """Precio PÚBLICO autorizado para publicación. Nunca una cotización individual."""

    model_config = ConfigDict(extra="forbid")
    amount: Decimal = Field(ge=0, le=Decimal("99999999.99"), decimal_places=2)
    currency: Literal["PEN", "USD"]
    conditions: str = Field(min_length=3, max_length=300, description="p. ej. 'precio por participante'.")


class CatalogItem(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    stable_id: str = Field(pattern=STABLE_ID_PATTERN, description="Identificador estable, nunca reutilizado.")
    type: ItemType
    publication_status: PublicationStatus
    title: str = Field(min_length=3, max_length=120)
    summary: str = Field(min_length=10, max_length=600)
    details: str | None = Field(default=None, max_length=1500)
    keywords: list[str] = Field(default_factory=list, max_length=20)
    topics: list[Topic] = Field(default_factory=list)
    audience: list[AudienceName] = Field(default_factory=list)
    modalities: list[ModalityName] = Field(default_factory=list)
    levels: list[Level] = Field(default_factory=list)
    requirements: list[str] = Field(default_factory=list, max_length=10)
    duration_text: str | None = Field(default=None, max_length=200)
    schedule_text: str | None = Field(default=None, max_length=200)
    public_price: PublicPrice | None = None
    inquiry: str | None = Field(default=None, max_length=200, description="Canal público de consulta.")
    url_path: str | None = Field(default=None, pattern=r"^/[a-z0-9\-/#]*$")
    updated_at: AwareDatetime
    valid_from: date | None = None
    valid_until: date | None = None

    @model_validator(mode="after")
    def _consistency(self) -> CatalogItem:
        prefix = self.stable_id.split(":", 1)[0]
        expected = {"canal_consulta": "canal", "info_comercial": "comercial"}.get(self.type, self.type)
        if prefix != expected:
            raise ValueError(f"{self.stable_id}: el prefijo no coincide con el tipo {self.type}")
        if self.public_price is not None and self.publication_status != "vigente":
            raise ValueError(f"{self.stable_id}: solo un ítem vigente puede publicar precio")
        if self.valid_from and self.valid_until and self.valid_until < self.valid_from:
            raise ValueError(f"{self.stable_id}: valid_until anterior a valid_from")
        if any(len(r) > 200 or not r.strip() for r in self.requirements):
            raise ValueError(f"{self.stable_id}: requisitos vacíos o demasiado largos")
        return self


class PublicCatalog(BaseModel):
    model_config = ConfigDict(extra="forbid")

    schema_version: Literal["1.0"]
    catalog_version: str = Field(pattern=r"^[0-9A-Za-z.\-]{1,40}$")
    source_system: Literal["horus-laravel"]
    generated_at: AwareDatetime
    expires_at: AwareDatetime
    items: list[CatalogItem] = Field(max_length=500)
    signature: str | None = Field(default=None, pattern=r"^hmac-sha256:[0-9a-f]{64}$")

    @model_validator(mode="after")
    def _lifetime_and_ids(self) -> PublicCatalog:
        if self.expires_at <= self.generated_at:
            raise ValueError("expires_at debe ser posterior a generated_at")
        if self.expires_at - self.generated_at > MAX_CATALOG_LIFETIME:
            raise ValueError("la vigencia del catálogo no puede superar 90 días")
        ids = [i.stable_id for i in self.items]
        dup = sorted({i for i in ids if ids.count(i) > 1})
        if dup:
            raise ValueError(f"stable_id duplicados: {dup}")
        return self

    def is_expired(self, now: datetime) -> bool:
        return now >= self.expires_at
