"""Punto de entrada FastAPI de Horus AI Advisor.

Arranque:  python -m uvicorn app.main:app --host 127.0.0.1 --port 8001
"""

from __future__ import annotations

import asyncio
import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.trustedhost import TrustedHostMiddleware
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app import __version__
from app.api.routes import api, public
from app.core.config import Settings, get_settings
from app.services.chat_engine import AdvisorEngine
from app.services.knowledge import KnowledgeBase, KnowledgeError
from app.services.semantic_search import SemanticIndex

logger = logging.getLogger("horus_ai")

ERROR_CODES = {
    400: "bad_request",
    401: "unauthorized",
    404: "not_found",
    405: "method_not_allowed",
    413: "payload_too_large",
    415: "unsupported_media_type",
    422: "validation_error",
    503: "service_unavailable",
}


def _error(status_code: int, message: str, details: list[dict[str, str]] | None = None) -> JSONResponse:
    body: dict[str, object] = {"code": ERROR_CODES.get(status_code, "error"), "message": message}
    if details:
        body["details"] = details
    return JSONResponse(status_code=status_code, content={"error": body})


class BodySizeLimitMiddleware:
    """Rechaza cuerpos mayores que el límite, aunque no declaren Content-Length."""

    def __init__(self, app: ASGIApp, max_bytes: int, overrides: dict[str, int] | None = None) -> None:
        self.app = app
        self.max_bytes = max_bytes
        self.overrides = overrides or {}

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return
        limit = self.overrides.get(scope.get("path", ""), self.max_bytes)
        for name, value in scope.get("headers", []):
            if name == b"content-length" and value.isdigit() and int(value) > limit:
                await _error(413, "El cuerpo de la petición es demasiado grande.")(scope, receive, send)
                return
        received = 0

        async def limited_receive() -> Message:
            nonlocal received
            message = await receive()
            if message["type"] == "http.request":
                received += len(message.get("body", b""))
                if received > limit:
                    raise _BodyTooLarge
            return message

        try:
            await self.app(scope, limited_receive, send)
        except _BodyTooLarge:
            await _error(413, "El cuerpo de la petición es demasiado grande.")(scope, receive, send)


class _BodyTooLarge(Exception):
    pass


def load_knowledge(settings: Settings) -> tuple[KnowledgeBase | None, str]:
    """Base verificada + catálogo opcional. Si el catálogo falla, se sigue sin él (estado "error")."""
    try:
        base = KnowledgeBase.load(settings.knowledge_path, settings.intents_path)
    except KnowledgeError:
        logger.exception("No se pudo cargar la base de conocimiento.")
        return None, "none"
    if settings.catalog_path is None:
        return base, "none"
    try:
        kb = KnowledgeBase.load(
            settings.knowledge_path,
            settings.intents_path,
            settings.catalog_path,
            settings.catalog_key,
            settings.catalog_allow_unsigned,
        )
    except KnowledgeError as exc:
        logger.error("Catálogo rechazado; se usa solo la base verificada: %s", str(exc).splitlines()[0])
        return base, "error"
    return kb, kb.catalog_status


def build_engine(settings: Settings) -> tuple[AdvisorEngine | None, SemanticIndex | None, str]:
    kb, catalog_status = load_knowledge(settings)
    if kb is None:
        return None, None, catalog_status
    semantic = SemanticIndex(kb.records)
    if settings.semantic_enabled:
        semantic.load_in_background(
            settings.semantic_model_dir,
            settings.ml_threads,
            settings.semantic_query_prefix,
            settings.semantic_passage_prefix,
        )
    return AdvisorEngine(kb, semantic), semantic, catalog_status


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or get_settings()
    logging.basicConfig(
        level=settings.log_level.upper(), format="%(asctime)s %(levelname)s %(name)s %(message)s"
    )

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        engine, semantic, catalog_status = build_engine(settings)
        app.state.engine = engine
        app.state.semantic = semantic
        app.state.catalog_status = catalog_status
        app.state.slots = asyncio.Semaphore(settings.max_concurrent_requests)
        if not settings.service_token_configured:
            logger.warning("HORUS_AI_SERVICE_TOKEN no configurado (>=32 caracteres): /api/v1 responderá 503.")
        yield

    app = FastAPI(
        title="Horus AI Advisor",
        version=__version__,
        description=(
            "Motor informativo y de recomendaciones de Horus Group SRL. Responde solo con conocimiento "
            "público verificado; se abstiene cuando no hay información confirmada. Sin modelos generativos."
        ),
        lifespan=lifespan,
    )
    app.dependency_overrides[get_settings] = lambda: settings
    app.state.engine = None
    app.state.semantic = None
    app.state.catalog_status = "none"
    app.state.slots = asyncio.Semaphore(settings.max_concurrent_requests)

    # Protección frente a DNS rebinding: solo nombres de host locales. Sin CORS: es un servicio interno.
    app.add_middleware(TrustedHostMiddleware, allowed_hosts=["127.0.0.1", "localhost", "testserver"])
    app.add_middleware(
        BodySizeLimitMiddleware,
        max_bytes=settings.max_body_bytes,
        overrides={"/api/v1/catalog/validate": settings.max_catalog_bytes},
    )

    @app.exception_handler(RequestValidationError)
    async def _validation(_: Request, exc: RequestValidationError) -> JSONResponse:
        # No se devuelve la entrada recibida ("input") para no reflejar contenido del visitante.
        details = [
            {
                "field": ".".join(str(p) for p in err.get("loc", ()) if p != "body"),
                "message": str(err.get("msg", "")),
            }
            for err in exc.errors()
        ]
        return _error(422, "La petición no es válida.", details[:10])

    @app.exception_handler(HTTPException)
    async def _http(_: Request, exc: HTTPException) -> JSONResponse:
        message = exc.detail if isinstance(exc.detail, str) else "Error en la petición."
        response = _error(exc.status_code, message)
        if exc.status_code == 401:
            response.headers["WWW-Authenticate"] = "ApiKey"
        return response

    @app.exception_handler(Exception)
    async def _unexpected(_: Request, exc: Exception) -> JSONResponse:
        logger.error("Error no controlado: %s", type(exc).__name__)
        return _error(500, "Error interno del servicio.")

    app.include_router(public)
    app.include_router(api)
    return app


app = create_app()
