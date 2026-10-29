"""Formato de la base de conocimiento autorizada (data/knowledge.json)."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

Status = Literal["verified", "provisional", "unavailable"]
Category = Literal[
    "empresa", "contacto", "servicio", "formacion", "asesoramiento", "convenio", "faq", "politica"
]
Kind = Literal[
    "info",
    "service",
    "training_program",
    "course_modality",
    "advisory",
    "agreement",
    "faq",
    "policy",
    "course",
]
Topic = Literal[
    "empresa",
    "contacto",
    "ubicacion",
    "horario",
    "redes_sociales",
    "tecnologia",
    "camaras",
    "seguridad",
    "redes",
    "cableado",
    "wifi",
    "soporte",
    "mantenimiento",
    "computadoras",
    "software",
    "ofimatica",
    "formacion",
    "cursos",
    "capacitacion",
    "certificacion",
    "modalidades",
    "empresas",
    "enfermeria",
    "administracion",
    "economia",
    "tesis",
    "investigacion",
    "finanzas",
    "liderazgo",
    "legal",
    "pagos",
    "comprobantes",
    "pedidos",
    "devoluciones",
    "reclamos",
    "evaluaciones",
    "practicas",
    "precios",
    "cupos",
    "fechas",
    "requisitos",
    "promociones",
    "matricula",
]
Level = Literal["principiante", "intermedio", "avanzado"]
ModalityName = Literal["presencial", "semipresencial", "virtual", "online"]
AudienceName = Literal["persona", "empresa"]


class Source(BaseModel):
    model_config = ConfigDict(extra="forbid")

    kind: Literal["web_publica", "chatbot_php", "prueba_ficticia", "catalogo_laravel"]
    url_path: str | None = Field(default=None, pattern=r"^/[a-z0-9\-/#]*$")
    file: str = Field(min_length=3, description="Archivo del repositorio donde se comprobó el dato.")
    checked_on: str = Field(pattern=r"^\d{4}-\d{2}-\d{2}$")


class KnowledgeRecord(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    id: str = Field(pattern=r"^[a-z0-9_]{3,64}$")
    category: Category
    kind: Kind
    title: str = Field(min_length=3, max_length=120)
    description: str = Field(min_length=10, max_length=1500)
    answer: str = Field(min_length=10, max_length=1500, description="Texto que el motor puede mostrar.")
    status: Status
    source: Source
    keywords: list[str] = Field(min_length=1)
    examples: list[str] = Field(default_factory=list, description="Formulaciones para la búsqueda.")
    topics: list[Topic] = Field(default_factory=list)
    related: list[str] = Field(default_factory=list)
    audience: list[AudienceName] = Field(default_factory=list)
    modalities: list[ModalityName] = Field(default_factory=list)
    levels: list[Level] = Field(default_factory=list, description="Vacío = nivel no publicado.")
    recommendable: bool = False
    notes: str | None = None
    # Atributos opcionales. Hoy solo los rellena un catálogo autorizado (ver schemas/catalog.py).
    requirements: list[str] = Field(default_factory=list, max_length=10)
    duration: str | None = Field(default=None, max_length=200)
    schedule: str | None = Field(default=None, max_length=200)
    public_price: str | None = Field(
        default=None, max_length=300, description="Solo precio público autorizado."
    )
    updated_at: str | None = None
    stable_id: str | None = Field(default=None, description="Identificador estable de origen (catálogo).")
    superseded_by_catalog: list[str] = Field(
        default_factory=list,
        description="Tipos de ítem del catálogo que, si existen vigentes, reemplazan este registro.",
    )

    @model_validator(mode="after")
    def _consistency(self) -> KnowledgeRecord:
        if self.recommendable and self.status != "verified":
            raise ValueError(f"{self.id}: solo un registro verificado puede ser recomendable.")
        if self.recommendable and not self.topics:
            raise ValueError(f"{self.id}: un registro recomendable necesita temas (topics).")
        if any(not k.strip() for k in self.keywords):
            raise ValueError(f"{self.id}: palabras clave vacías.")
        return self


class KnowledgeFile(BaseModel):
    model_config = ConfigDict(extra="forbid")

    version: str = Field(min_length=1)
    description: str
    public_contact: dict[str, str]
    records: list[KnowledgeRecord] = Field(min_length=1)

    @model_validator(mode="after")
    def _unique_and_related(self) -> KnowledgeFile:
        ids = [r.id for r in self.records]
        duplicates = {i for i in ids if ids.count(i) > 1}
        if duplicates:
            raise ValueError(f"Identificadores duplicados: {sorted(duplicates)}")
        known = set(ids)
        for record in self.records:
            missing = [r for r in record.related if r not in known]
            if missing:
                raise ValueError(f"{record.id}: relaciones inexistentes {missing}")
        return self
