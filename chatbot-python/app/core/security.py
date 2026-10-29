"""Autenticación entre servicios y detección de mensajes manipuladores o sensibles.

El motor no ejecuta instrucciones: ningún texto del visitante ni del conocimiento recuperado
se interpreta como orden. Esta detección solo sirve para responder de forma clara y prudente.
"""

from __future__ import annotations

import hmac
import re

from fastapi import Depends, HTTPException, status
from fastapi.security import APIKeyHeader

from app.core.config import Settings, get_settings
from app.core.text import normalize

SERVICE_TOKEN_HEADER = "X-Horus-Service-Token"  # noqa: S105 (nombre de cabecera, no un secreto)

_api_key_header = APIKeyHeader(
    name=SERVICE_TOKEN_HEADER,
    auto_error=False,
    description="Secreto de servicio compartido con Laravel (HORUS_AI_SERVICE_TOKEN).",
)


def require_service_token(
    provided: str | None = Depends(_api_key_header),
    settings: Settings = Depends(get_settings),
) -> None:
    """Falla cerrado: sin token configurado, los endpoints protegidos no atienden."""
    if not settings.service_token_configured:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="El servicio no tiene configurado un secreto de servicio.",
        )
    expected = settings.service_token.get_secret_value().encode()  # type: ignore[union-attr]
    if not provided or not hmac.compare_digest(provided.encode(), expected):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail="Credencial de servicio inválida."
        )


# Patrones sobre texto normalizado (minúsculas, sin tildes).
_INJECTION_PATTERNS: tuple[re.Pattern[str], ...] = tuple(
    re.compile(p)
    for p in (
        r"\b(ignora|ignorar|olvida|olvidar|omite|omitir|salta|saltate)\b.{0,40}\b(instruccion|instrucciones|reglas|indicaciones|restricciones|prompt)\b",
        r"\b(ignore|disregard|forget)\b.{0,40}\b(instructions|rules|prompt)\b",
        r"\b(system prompt|prompt del sistema|prompt de sistema|instrucciones del sistema|tus instrucciones)\b",
        r"\b(revela|revelar|muestrame|muestra|dime|imprime|ense[nñ]ame)\b.{0,40}\b(configuracion|config|prompt|variables de entorno|env|secreto|secretos|clave|claves|token|credencial|credenciales|api key)\b",
        r"\b(api key|apikey|openai_api_key|resend_api_key|service_token|variables de entorno)\b",
        r"\b(modo desarrollador|developer mode|jailbreak|dan mode)\b",
        r"\b(olvida|ignora)\b.{0,10}\b(lo anterior|todo lo anterior|lo que te dijeron)\b",
        r"\b(a partir de ahora|desde ahora)\b.{0,30}\b(eres|seras|actua|responde)\b",
        r"\b(actua como|finge ser|pretend to be|act as)\b",
        r"\b(ejecuta|ejecutar|corre|run)\b.{0,30}\b(comando|comandos|script|codigo|shell|powershell|cmd|bash|sql)\b",
        r"\b(rm rf|drop table|select from|union select|etc passwd)\b",
        r"\bscript\b.{0,20}\balert\b",
    )
)

_SENSITIVE_PATTERNS: tuple[re.Pattern[str], ...] = tuple(
    re.compile(p)
    for p in (
        r"\b(datos|lista|listado|nombres|correos|telefonos|dni|notas)\b.{0,30}\b(estudiantes|alumnos|matriculados|inscritos|participantes|clientes|usuarios)\b",
        r"\b(acceso de administrador|acceso de admin|acceso al panel|cuenta de administrador)\b",
        r"\b(base de datos|postgres|postgresql|tabla de matriculas|panel administrativo|panel de admin)\b",
        r"\b(contrase[nñ]a|password|clave de acceso)\b.{0,30}\b(de|del|admin|administrador|usuario)\b",
        r"\b(quien|quienes)\b.{0,20}\b(se matriculo|se matricularon|esta matriculado|estan matriculados)\b",
    )
)


def detect_prompt_injection(text: str) -> bool:
    normalized = normalize(text)
    return any(p.search(normalized) for p in _INJECTION_PATTERNS)


def detect_sensitive_request(text: str) -> bool:
    normalized = normalize(text)
    return any(p.search(normalized) for p in _SENSITIVE_PATTERNS)
