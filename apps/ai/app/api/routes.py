"""Endpoints HTTP. /health y /ready son públicos y mínimos; /api/v1/* exige el secreto de servicio."""

from __future__ import annotations

import asyncio
import logging
import time
from datetime import UTC, datetime
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
from app.services.catalog_sync import CatalogSyncRejected, build_catalog_candidate, persist_catalog_atomically
from app.services.chat_engine import AdvisorEngine

logger = logging.getLogger("horus_ai.api")


def _catalog_expired(engine: AdvisorEngine | None) -> bool:
    catalog = engine.kb.catalog.catalog if engine is not None and engine.kb.catalog is not None else None
    return catalog is not None and catalog.is_expired(datetime.now(UTC))

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
    if _catalog_expired(engine):
        request.app.state.catalog_status = "stale"
    if (
        engine is None
        or getattr(request.app.state, "catalog_pending", False)
        or getattr(request.app.state, "catalog_status", "none") == "stale"
        or getattr(request.app.state, "catalog_sync_failed", False)
    ):
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


@public.get("/ready", response_model=ReadyResponse, response_model_exclude_none=True, tags=["estado"])
def ready(request: Request, settings: Settings = Depends(get_settings)) -> ReadyResponse:
    engine: AdvisorEngine | None = getattr(request.app.state, "engine", None)
    if _catalog_expired(engine):
        request.app.state.catalog_status = "stale"
    semantic = request.app.state.semantic
    catalog_status = getattr(request.app.state, "catalog_status", "none")
    pending = getattr(request.app.state, "catalog_pending", False)
    is_ready = engine is not None and settings.service_token_configured and not pending and catalog_status not in {"stale", "error"}
    return ReadyResponse(
        status="ready" if is_ready else "not_ready",
        knowledge="loaded" if engine is not None else "unavailable",
        semantic=semantic.status if semantic is not None else "disabled",
        auth="configured" if settings.service_token_configured else "missing",
        catalog="stale" if pending else catalog_status,
        catalog_version=getattr(request.app.state, "catalog_version", None),
        catalog_pending_version=getattr(request.app.state, "catalog_pending_version", None),
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
        selection_model=engine.gemini.model if engine.gemini is not None else None,
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
    generation = request.app.state.catalog_generation
    started = time.perf_counter()
    reply = await _run_limited(request, settings, engine.chat, payload.message, payload.history)
    if request.app.state.catalog_pending or request.app.state.catalog_generation != generation:
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "El conocimiento se está actualizando.")
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
    generation = request.app.state.catalog_generation
    reply = await _run_limited(request, settings, engine.recommend, payload)
    if request.app.state.catalog_pending or request.app.state.catalog_generation != generation:
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "El conocimiento se está actualizando.")
    logger.info(
        "recommend intent=%s match=%s options=%d", reply.intent, reply.match_level, len(reply.recommendations)
    )
    return RecommendResponse(**reply.model_dump())


@api.post("/catalog/sync", tags=["catálogo"])
async def sync_catalog(
    request: Request,
    payload: dict[str, Any] = Body(..., description="Snapshot público firmado por Laravel"),
    settings: Settings = Depends(get_settings),
) -> dict[str, Any]:
    """Valida y aplica un snapshot completo sin reiniciar ni exponer acceso a PostgreSQL."""
    app = request.app
    if settings.catalog_path is None or settings.catalog_key is None:
        app.state.catalog_status = "error"
        app.state.catalog_sync_failed = True
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "La sincronización no está configurada.")
    engine: AdvisorEngine | None = getattr(app.state, "engine", None)
    if engine is None:
        app.state.catalog_status = "error"
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "El conocimiento base no está disponible.")

    app.state.catalog_sync_waiters += 1
    app.state.catalog_pending = True
    sync_lock: asyncio.Lock = app.state.catalog_sync_lock
    lock_acquired = False
    slots: asyncio.Semaphore = app.state.slots
    acquired = 0
    try:
        try:
            await asyncio.wait_for(sync_lock.acquire(), timeout=settings.request_timeout_seconds)
            lock_acquired = True
            pending_version = payload.get("catalog_version")
            app.state.catalog_pending_version = pending_version if (
                isinstance(pending_version, str) and len(pending_version) == 40
                and all(character in "0123456789abcdef" for character in pending_version)
            ) else None
        except TimeoutError as exc:
            raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "La sincronizaci\u00f3n est\u00e1 ocupada.") from exc

        engine: AdvisorEngine | None = getattr(app.state, "engine", None)
        if engine is None:
            app.state.catalog_status = "error"
            app.state.catalog_sync_failed = True
            raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "El conocimiento base no est\u00e1 disponible.")

        # Drena las solicitudes ya iniciadas; las nuevas quedan bloqueadas por get_engine.
        for _ in range(settings.max_concurrent_requests):
            await slots.acquire()
            acquired += 1
        semantic = getattr(app.state, "semantic", None)
        try:
            candidate, candidate_semantic, version, _ = await asyncio.to_thread(
                build_catalog_candidate, payload, settings, engine, semantic
            )
            if candidate is not engine:
                await asyncio.to_thread(persist_catalog_atomically, settings.catalog_path, payload)
            app.state.engine = candidate
            app.state.semantic = candidate_semantic
            app.state.catalog_version = version
            app.state.catalog_status = candidate.kb.catalog_status
            app.state.catalog_generation += 1
            app.state.catalog_sync_failed = False
            logger.info("catalog-sync outcome=applied version=%s", version)
            return {"accepted": True, "applied": candidate is not engine, "catalog": app.state.catalog_status, "catalog_version": version}
        except CatalogSyncRejected as exc:
            app.state.catalog_status = "error"
            app.state.catalog_sync_failed = True
            logger.warning("catalog-sync outcome=rejected code=%s", exc.status_code)
            raise HTTPException(exc.status_code, str(exc)) from None
        except Exception as exc:
            app.state.catalog_status = "error"
            app.state.catalog_sync_failed = True
            logger.error("catalog-sync outcome=failed error=%s", type(exc).__name__)
            raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "No se pudo aplicar el catálogo.") from None
    finally:
        if lock_acquired:
            app.state.catalog_pending_version = None
            sync_lock.release()
        app.state.catalog_sync_waiters = max(0, app.state.catalog_sync_waiters - 1)
        app.state.catalog_pending = app.state.catalog_sync_waiters > 0
        for _ in range(acquired):
            slots.release()


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
