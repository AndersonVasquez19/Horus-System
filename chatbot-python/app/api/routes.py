"""Endpoints HTTP. /health y /ready son públicos y mínimos; /api/v1/* exige el secreto de servicio."""

from __future__ import annotations

import asyncio
import logging
import time
from typing import Any

from fastapi import APIRouter, Body, Depends, HTTPException, Request, status
from fastapi.concurrency import run_in_threadpool

from app import __version__
from app.core.config import Settings, get_settings
from app.core.security import require_service_token
from app.schemas.chat import (
    CatalogValidationResponse,
    ChatRequest,
    ChatResponse,
    ErrorResponse,
    HealthResponse,
    InfoResponse,
    ReadyResponse,
    RecommendRequest,
    RecommendResponse,
)
from app.services.catalog import validate_payload
from app.services.chat_engine import AdvisorEngine

logger = logging.getLogger("horus_ai.api")

public = APIRouter()
api = APIRouter(
    prefix="/api/v1",
    dependencies=[Depends(require_service_token)],
    responses={
        401: {"model": ErrorResponse},
        413: {"model": ErrorResponse},
        422: {"model": ErrorResponse},
        503: {"model": ErrorResponse},
    },
)


def get_engine(request: Request) -> AdvisorEngine:
    engine: AdvisorEngine | None = getattr(request.app.state, "engine", None)
    if engine is None:
        raise HTTPException(
            status.HTTP_503_SERVICE_UNAVAILABLE, "La base de conocimiento no está disponible."
        )
    return engine


async def _run_limited(request: Request, settings: Settings, fn, *args):  # type: ignore[no-untyped-def]
    """Ejecuta fuera del bucle de eventos con límite de concurrencia y tiempo máximo."""
    slots: asyncio.Semaphore = request.app.state.slots
    try:
        await asyncio.wait_for(slots.acquire(), timeout=settings.request_timeout_seconds)
    except TimeoutError as exc:
        raise HTTPException(
            status.HTTP_503_SERVICE_UNAVAILABLE, "Servicio ocupado, intenta de nuevo."
        ) from exc
    try:
        return await asyncio.wait_for(run_in_threadpool(fn, *args), timeout=settings.request_timeout_seconds)
    except TimeoutError as exc:
        raise HTTPException(
            status.HTTP_503_SERVICE_UNAVAILABLE, "La consulta superó el tiempo máximo."
        ) from exc
    finally:
        slots.release()


@public.get("/health", response_model=HealthResponse, tags=["estado"])
def health() -> HealthResponse:
    return HealthResponse(status="ok")


@public.get("/ready", response_model=ReadyResponse, tags=["estado"])
def ready(request: Request, settings: Settings = Depends(get_settings)) -> ReadyResponse:
    engine: AdvisorEngine | None = getattr(request.app.state, "engine", None)
    semantic = request.app.state.semantic
    is_ready = engine is not None and settings.service_token_configured
    return ReadyResponse(
        status="ready" if is_ready else "not_ready",
        knowledge="loaded" if engine is not None else "unavailable",
        semantic=semantic.status if semantic is not None else "disabled",
        auth="configured" if settings.service_token_configured else "missing",
        catalog=getattr(request.app.state, "catalog_status", "none"),
    )


@api.get("/info", response_model=InfoResponse, tags=["asesor"])
def info(
    engine: AdvisorEngine = Depends(get_engine), settings: Settings = Depends(get_settings)
) -> InfoResponse:
    return InfoResponse(
        name=settings.app_name,
        version=__version__,
        knowledge_version=engine.kb.version,
        verified_records=len(engine.kb.verified()),
        capabilities=[
            "respuestas_informativas_verificadas",
            "recomendaciones_explicables",
            "aclaracion_y_abstencion",
            "contexto_conversacional_limitado",
            "busqueda_semantica" if engine.mode == "hybrid" else "busqueda_lexica",
        ],
        search_mode=engine.mode,
        catalog_version=engine.kb.catalog.catalog.catalog_version if engine.kb.catalog else None,
        limits={
            "max_message_chars": settings.max_message_chars,
            "max_history_items": settings.max_history_items,
            "max_body_bytes": settings.max_body_bytes,
            "request_timeout_seconds": settings.request_timeout_seconds,
        },
    )


@api.post("/chat", response_model=ChatResponse, tags=["asesor"])
async def chat(
    payload: ChatRequest,
    request: Request,
    engine: AdvisorEngine = Depends(get_engine),
    settings: Settings = Depends(get_settings),
) -> ChatResponse:
    started = time.perf_counter()
    reply = await _run_limited(request, settings, engine.chat, payload.message, payload.history)
    # Solo metadatos: nunca se registra el texto del visitante.
    logger.info(
        "chat intent=%s match=%s clarify=%s abstained=%s mode=%s ms=%.1f",
        reply.intent,
        reply.match_level,
        reply.needs_clarification,
        reply.abstained,
        reply.mode,
        (time.perf_counter() - started) * 1000,
    )
    return ChatResponse(**reply.model_dump(), session_id=payload.session_id)


@api.post("/recommend", response_model=RecommendResponse, tags=["asesor"])
async def recommend(
    payload: RecommendRequest,
    request: Request,
    engine: AdvisorEngine = Depends(get_engine),
    settings: Settings = Depends(get_settings),
) -> RecommendResponse:
    reply = await _run_limited(request, settings, engine.recommend, payload)
    logger.info(
        "recommend intent=%s match=%s options=%d", reply.intent, reply.match_level, len(reply.recommendations)
    )
    return RecommendResponse(**reply.model_dump())


@api.post("/catalog/validate", response_model=CatalogValidationResponse, tags=["catálogo"])
def validate_catalog(
    payload: dict[str, Any] = Body(..., description="Catálogo público en formato schema_version 1.0"),
    engine: AdvisorEngine = Depends(get_engine),
    settings: Settings = Depends(get_settings),
) -> CatalogValidationResponse:
    """Valida un catálogo SIN cargarlo: estructura, estados, firma y ausencia de datos privados.

    Pensado para que Laravel compruebe su exportación antes de publicarla. Nunca persiste nada.
    """
    report = validate_payload(payload, settings.catalog_key, engine.kb.data.public_contact)
    logger.info("catalog-validate valid=%s signature=%s", report["valid"], report["signature"])
    return CatalogValidationResponse(**report)
