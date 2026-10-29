"""Configuración del servicio (variables de entorno con prefijo HORUS_AI_)."""

from __future__ import annotations

import ipaddress
from functools import lru_cache
from pathlib import Path

from pydantic import Field, SecretStr, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DATA_DIR = PROJECT_ROOT / "data"

MIN_SERVICE_TOKEN_LENGTH = 32


def _is_loopback(host: str) -> bool:
    if host == "localhost":
        return True
    try:
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        return False


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="HORUS_AI_",
        env_file=PROJECT_ROOT / ".env",
        env_file_encoding="utf-8",
        # Variables ajenas (OPENAI_API_KEY, RESEND_API_KEY, DB_*) se ignoran: este servicio no las usa.
        extra="ignore",
    )

    app_name: str = "Horus AI Advisor"
    host: str = "127.0.0.1"
    port: int = Field(default=8001, ge=1, le=65535)
    allow_non_loopback: bool = False

    service_token: SecretStr | None = None

    knowledge_path: Path = DATA_DIR / "knowledge.json"
    intents_path: Path = DATA_DIR / "intents.json"

    # Catálogo público exportado por Laravel (futuro). Desactivado por defecto.
    catalog_path: Path | None = None
    catalog_signing_key: SecretStr | None = None
    catalog_allow_unsigned: bool = False  # solo para pruebas locales con datos ficticios
    max_catalog_bytes: int = Field(default=1_048_576, ge=4096, le=8_388_608)

    semantic_enabled: bool = False
    semantic_model_name: str = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
    semantic_model_dir: Path = PROJECT_ROOT / "models" / "paraphrase-multilingual-MiniLM-L12-v2"
    semantic_query_prefix: str = ""
    semantic_passage_prefix: str = ""
    ml_threads: int = Field(default=2, ge=1, le=16)

    max_message_chars: int = Field(default=2000, ge=1, le=4000)
    max_history_items: int = Field(default=10, ge=0, le=30)
    max_body_bytes: int = Field(default=32768, ge=1024, le=262144)
    request_timeout_seconds: float = Field(default=5.0, gt=0, le=30)
    max_concurrent_requests: int = Field(default=4, ge=1, le=64)

    log_level: str = "INFO"

    @field_validator("catalog_path", "catalog_signing_key", mode="before")
    @classmethod
    def _empty_is_none(cls, value: object) -> object:
        return (
            None if isinstance(value, str) and not value.strip() else value
        )  # "HORUS_AI_CATALOG_PATH=" = sin catálogo

    @field_validator("semantic_model_dir", "knowledge_path", "intents_path", "catalog_path", mode="after")
    @classmethod
    def _resolve_relative(cls, value: Path | None) -> Path | None:
        if value is None:
            return None
        return value if value.is_absolute() else (PROJECT_ROOT / value).resolve()

    @property
    def catalog_key(self) -> str | None:
        key = self.catalog_signing_key.get_secret_value() if self.catalog_signing_key else ""
        return key if len(key) >= MIN_SERVICE_TOKEN_LENGTH else None

    @model_validator(mode="after")
    def _enforce_loopback(self) -> Settings:
        if not self.allow_non_loopback and not _is_loopback(self.host):
            raise ValueError("HORUS_AI_HOST debe ser una dirección loopback (127.0.0.1) en esta fase.")
        return self

    @property
    def service_token_configured(self) -> bool:
        token = self.service_token.get_secret_value() if self.service_token else ""
        return len(token) >= MIN_SERVICE_TOKEN_LENGTH


@lru_cache
def get_settings() -> Settings:
    return Settings()
