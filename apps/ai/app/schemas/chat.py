"""Contratos de la API. Todos rechazan campos inesperados (extra="forbid")."""

from __future__ import annotations

from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

from app.core.config import get_settings

SESSION_ID_PATTERN = r"^[A-Za-z0-9_\-]{1,64}$"

MatchLevel = Literal["alta", "media", "baja", "ninguna"]
Level = Literal["principiante", "intermedio", "avanzado", "desconocido"]
Modality = Literal["presencial", "semipresencial", "virtual", "cualquiera"]
Audience = Literal["persona", "empresa", "desconocido"]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


def _check_message(value: str) -> str:
    limit = get_settings().max_message_chars
    if not value:
        raise ValueError("El mensaje no puede estar vacío.")
    if len(value) > limit:
        raise ValueError(f"El mensaje supera el máximo de {limit} caracteres.")
    return value


class HistoryItem(StrictModel):
    role: Literal["user", "assistant"]
    content: str = Field(min_length=1, max_length=4000)

    @field_validator("content")
    @classmethod
    def _limit(cls, value: str) -> str:
        return _check_message(value)


Key = Annotated[str, Field(pattern=r"^[a-z0-9_]{1,40}$")]
RecordId = Annotated[str, Field(pattern=r"^[a-z][a-z0-9_:.\-]{0,127}$")]


class ConversationState(StrictModel):
    """Estado estructurado de la conversación que el motor devuelve y el cliente reenvía en el turno siguiente.

    Solo vocabulario cerrado (claves de tema, nivel, modalidad, identificadores de registros publicados) y
    contadores: nunca texto del usuario. El motor descarta lo que no reconoce. Las edades cuentan los turnos
    transcurridos desde que el dato apareció; a los MAX_CONTEXT_TURNS turnos el dato caduca (como el historial).
    """

    topics: list[Key] = Field(default_factory=list, max_length=8)
    objectives: list[Key] = Field(default_factory=list, max_length=8)
    level: Key = "desconocido"
    modality: Key = "cualquiera"
    audience: Key = "desconocido"
    goal: Literal["learn", "service", "unknown"] = "unknown"
    profile_age: int | None = Field(default=None, ge=0, le=2)
    cue_age: int | None = Field(default=None, ge=0, le=2)
    focus_ids: list[RecordId] = Field(default_factory=list, max_length=2)
    focus_age: int | None = Field(default=None, ge=0, le=2)
    out_of_scope: bool = False
    quote: bool = False


class ChatRequest(StrictModel):
    message: str = Field(max_length=4000, examples=["Quiero aprender redes desde cero"])
    session_id: str | None = Field(default=None, pattern=SESSION_ID_PATTERN, examples=["demo-local"])
    history: list[HistoryItem] = Field(default_factory=list, max_length=30)
    state: ConversationState | None = Field(
        default=None, description="Estado devuelto en la respuesta anterior. Si llega historial, se ignora."
    )

    @field_validator("message")
    @classmethod
    def _message(cls, value: str) -> str:
        return _check_message(value)

    @field_validator("history")
    @classmethod
    def _history(cls, value: list[HistoryItem]) -> list[HistoryItem]:
        limit = get_settings().max_history_items
        if len(value) > limit:
            raise ValueError(f"El historial supera el máximo de {limit} mensajes.")
        return value


class RecommendRequest(StrictModel):
    interests: list[str] = Field(default_factory=list, max_length=10)
    goal: str | None = Field(default=None, max_length=500)
    level: Level = "desconocido"
    modality: Modality = "cualquiera"
    audience: Audience = "desconocido"

    @field_validator("interests")
    @classmethod
    def _interests(cls, value: list[str]) -> list[str]:
        cleaned = [v.strip() for v in value if v and v.strip()]
        if any(len(v) > 80 for v in cleaned):
            raise ValueError("Cada interés admite hasta 80 caracteres.")
        return cleaned


class SourceRef(BaseModel):
    id: str
    title: str
    url_path: str | None = None


class Recommendation(BaseModel):
    id: str
    title: str
    kind: str
    reason: str
    evidence: list[str] = Field(
        description="Identificadores de registros verificados que respaldan la opción."
    )
    match_level: MatchLevel = Field(
        description="Grado de coincidencia con lo pedido. No es una probabilidad."
    )
    url_path: str | None = None
    evidence_text: str | None = Field(
        default=None, description="Frase literal del registro que respalda el motivo."
    )
    signals: list[str] = Field(default_factory=list, description="Criterios que influyeron en la selección.")
    limitations: list[str] = Field(default_factory=list, description="Lo que no está publicado o no cubre.")
    needs_clarification: bool = False
    clarification_question: str | None = None
    next_step: str | None = Field(
        default=None, description="Siguiente paso sugerido (p. ej. pedir cotización)."
    )


class ConversationContext(BaseModel):
    """Contexto explícito y limitado que el motor dedujo del historial. No se almacena."""

    turns_considered: int = Field(description="Mensajes del usuario usados (máximo 3).")
    topics: list[str] = Field(default_factory=list)
    level: str | None = None
    modality: str | None = None
    focus_ids: list[str] = Field(default_factory=list, description="Opciones a las que remite «eso».")
    topic_changed: bool = False
    referential: bool = False


# Botones de seguimiento que el motor propone tras una respuesta: vocabulario cerrado (sin texto libre). La interfaz
# traduce cada clave a su etiqueta y a una consulta sin título: el curso en foco lo lleva el estado del servidor.
Suggestion = Literal["schedule", "modality", "enrollment", "details", "compare", "price"]


class AdvisorReply(BaseModel):
    """Respuesta común de /chat y /recommend."""

    answer: str
    intent: str
    match_level: MatchLevel = Field(description="Calidad de la coincidencia. No es probabilidad de acierto.")
    needs_clarification: bool = False
    clarification_question: str | None = None
    abstained: bool = Field(default=False, description="True si el motor no pudo afirmar nada confirmado.")
    recommendations: list[Recommendation] = Field(default_factory=list)
    missing_data: list[str] = Field(default_factory=list)
    general_guidance: str | None = Field(
        default=None, description="Orientación general NO atribuida a Horus Group."
    )
    sources: list[SourceRef] = Field(default_factory=list)
    mode: Literal["lexical", "hybrid"] = "lexical"
    context: ConversationContext | None = None
    state: ConversationState | None = Field(
        default=None, description="Estado para reenviar en el turno siguiente (sin texto del usuario)."
    )
    suggestions: list[Suggestion] = Field(
        default_factory=list, max_length=4,
        description="Siguientes pasos útiles (claves cerradas) según el curso en foco y lo ya respondido.",
    )


class ChatResponse(AdvisorReply):
    session_id: str | None = None


class RecommendResponse(AdvisorReply):
    pass


class HealthResponse(BaseModel):
    status: Literal["ok"]


class ReadyResponse(BaseModel):
    status: Literal["ready", "not_ready"]
    knowledge: Literal["loaded", "unavailable"]
    semantic: Literal["loaded", "loading", "disabled", "unavailable"]
    auth: Literal["configured", "missing"]
    catalog: Literal["none", "loaded", "stale", "error"] = "none"
    catalog_version: str | None = None

    catalog_pending_version: str | None = Field(default=None, pattern=r"^[a-f0-9]{40}$")


class InfoResponse(BaseModel):
    name: str
    version: str
    knowledge_version: str
    verified_records: int
    capabilities: list[str]
    search_mode: Literal["lexical", "hybrid"]
    limits: dict[str, float]
    generative_model: Literal["none"] = "none"
    selection_model: str | None = None
    catalog_version: str | None = None


class CatalogValidationResponse(BaseModel):
    """Resultado de validar un catálogo en seco. No se almacena ni se carga nada."""

    valid: bool
    signature: Literal["valid", "invalid", "missing", "not_checked"]
    errors: list[dict[str, str]] = Field(default_factory=list)
    summary: dict[str, object] | None = None
    loaded: Literal[False] = False


class ErrorBody(BaseModel):
    code: str
    message: str
    details: list[dict[str, str]] | None = None


class ErrorResponse(BaseModel):
    error: ErrorBody
