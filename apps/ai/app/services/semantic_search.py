"""Búsqueda semántica opcional con un modelo de embeddings preentrenado (solo inferencia, CPU).

- El modelo se carga UNA vez por proceso, en un hilo de fondo, solo desde disco local.
- Nunca se descarga nada en tiempo de ejecución (HF_HUB_OFFLINE=1). La descarga es un paso
  explícito: scripts/download_model.py.
- Si falta la dependencia o el modelo, el servicio sigue en modo léxico.
- La similitud coseno NO es una probabilidad de acierto; se normaliza con umbrales calibrados.
"""

from __future__ import annotations

import logging
import os
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Literal, Protocol

from app.schemas.knowledge import KnowledgeRecord
from app.services.vector_cache import PassageVectorCache

logger = logging.getLogger("horus_ai.semantic")

SemanticStatus = Literal["disabled", "loading", "loaded", "unavailable"]

# Normalización de la similitud coseno a [0, 1]. Calibrados con data/evaluation.json (split "dev").
COSINE_FLOOR = 0.45
COSINE_CEIL = 0.85


class Embedder(Protocol):
    def encode(self, texts: list[str]) -> Any: ...


@dataclass(frozen=True)
class SemanticHit:
    record_id: str
    cosine: float
    score: float  # cosine normalizado a [0, 1]


def normalize_cosine(cosine: float) -> float:
    return max(0.0, min(1.0, (cosine - COSINE_FLOOR) / (COSINE_CEIL - COSINE_FLOOR)))


class SentenceTransformerEmbedder:
    """Adaptador sobre sentence-transformers. Importa torch solo al construirse."""

    def __init__(
        self, model_dir: Path, threads: int, query_prefix: str = "", passage_prefix: str = ""
    ) -> None:
        os.environ.setdefault("HF_HUB_OFFLINE", "1")
        os.environ.setdefault("TRANSFORMERS_OFFLINE", "1")
        os.environ.setdefault("HF_HUB_DISABLE_TELEMETRY", "1")
        import torch
        from sentence_transformers import SentenceTransformer

        torch.set_num_threads(threads)
        self._model = SentenceTransformer(str(model_dir), device="cpu", local_files_only=True)
        self.query_prefix = query_prefix
        self.passage_prefix = passage_prefix

    def encode(self, texts: list[str]) -> Any:
        return self._model.encode(
            texts, batch_size=16, normalize_embeddings=True, convert_to_numpy=True, show_progress_bar=False
        )


class SemanticIndex:
    def __init__(self, records: list[KnowledgeRecord]) -> None:
        self._records = records
        self._lock = threading.Lock()
        self._status: SemanticStatus = "disabled"
        self._embedder: Embedder | None = None
        self._matrix: Any = None
        self._owners: list[str] = []
        self._query_prefix = ""
        self._passage_prefix = ""
        self._encode_slots = threading.BoundedSemaphore(2)
        self._loader_thread: threading.Thread | None = None
        self._passage_vectors: dict[str, Any] = {}
        self.vector_cache: PassageVectorCache | None = None  # opt-in; ver vector_cache.py

    @property
    def status(self) -> SemanticStatus:
        return self._status

    @property
    def ready(self) -> bool:
        return self._status == "loaded"

    @staticmethod
    def passages_for(record: KnowledgeRecord) -> list[str]:
        passages = [f"{record.title}. {record.description}"]
        passages.extend(record.examples)
        passages.extend(k for k in record.keywords if len(k.split()) >= 2)
        return passages

    def attach(self, embedder: Embedder, query_prefix: str = "", passage_prefix: str = "", cached: dict[str, Any] | None = None) -> None:
        """Construye el índice con un embedder ya creado (también lo usan las pruebas)."""
        texts: list[str] = []
        owners: list[str] = []
        for record in self._records:
            for passage in self.passages_for(record):
                texts.append(passage_prefix + passage)
                owners.append(record.id)
        import numpy as np

        previous = dict(cached or {})
        unique = list(dict.fromkeys(texts))
        if self.vector_cache is not None and any(text not in previous for text in unique):
            previous.update(self.vector_cache.load([t for t in unique if t not in previous]))
        missing = [text for text in unique if text not in previous]
        encoded = embedder.encode(missing) if missing else []
        vectors = {text: previous[text] for text in unique if text in previous}
        vectors.update(zip(missing, encoded, strict=True))
        if self.vector_cache is not None and (missing or not self.vector_cache.path.is_file()):
            self.vector_cache.save(vectors, unique)
        matrix = np.stack([vectors[text] for text in texts]) if texts else np.empty((0, 0))
        with self._lock:
            self._embedder = embedder
            self._matrix = matrix
            self._owners = owners
            self._query_prefix = query_prefix
            self._passage_prefix = passage_prefix
            self._status = "loaded"
            self._passage_vectors = vectors

    def rebuilt(self, records: list[KnowledgeRecord], wait_seconds: float = 30.0) -> SemanticIndex:
        """Prepara un índice candidato con los mismos pesos locales antes de publicarlo."""
        loader = self._loader_thread
        if self.status == "loading" and loader is not None:
            loader.join(wait_seconds)
        if self.status == "loading":
            raise TimeoutError("El modelo semántico aún está cargando.")

        replacement = SemanticIndex(records)
        replacement.vector_cache = self.vector_cache
        with self._lock:
            embedder = self._embedder
            status = self._status
            query_prefix = self._query_prefix
            passage_prefix = self._passage_prefix
            vectors = dict(self._passage_vectors)
        if status == "loaded" and embedder is not None:
            replacement.attach(embedder, query_prefix, passage_prefix, vectors)
        else:
            replacement._status = status
        return replacement

    def load_in_background(
        self, model_dir: Path, threads: int, query_prefix: str = "", passage_prefix: str = ""
    ) -> threading.Thread | None:
        if not model_dir.is_dir():
            logger.warning("Modelo semántico no encontrado en disco; se usa modo léxico.")
            self._status = "unavailable"
            return None
        self._status = "loading"

        def _run() -> None:
            try:
                embedder = SentenceTransformerEmbedder(model_dir, threads, query_prefix, passage_prefix)
                self.attach(embedder, query_prefix, passage_prefix)
                logger.info("Modelo semántico cargado (%d pasajes).", len(self._owners))
            except Exception:  # dependencia ausente, pesos corruptos, memoria...
                logger.exception("No se pudo cargar el modelo semántico; se usa modo léxico.")
                self._status = "unavailable"

        thread = threading.Thread(target=_run, name="semantic-loader", daemon=True)
        self._loader_thread = thread
        thread.start()
        return thread

    def search(self, query: str, record_ids: set[str] | None = None) -> dict[str, SemanticHit]:
        """Devuelve la mejor similitud por registro. Vacío si el índice no está listo u ocupado."""
        if not self.ready or self._embedder is None or not self._owners:
            return {}
        if not self._encode_slots.acquire(timeout=2):
            return {}  # saturado: el motor sigue con la parte léxica
        try:
            import numpy as np

            vector = self._embedder.encode([self._query_prefix + query])[0]
            sims = self._matrix @ vector
        finally:
            self._encode_slots.release()
        best: dict[str, float] = {}
        for owner, sim in zip(self._owners, np.asarray(sims).tolist(), strict=True):
            if record_ids is not None and owner not in record_ids:
                continue
            if sim > best.get(owner, -1.0):
                best[owner] = sim
        return {rid: SemanticHit(rid, round(c, 4), round(normalize_cosine(c), 4)) for rid, c in best.items()}
