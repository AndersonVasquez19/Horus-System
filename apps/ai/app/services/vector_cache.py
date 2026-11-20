"""Caché en disco de vectores de PASAJES PÚBLICOS para no recodificarlos al reiniciar.

- Opt-in: solo existe si HORUS_AI_SEMANTIC_CACHE_DIR está configurado. Sin él, el índice se comporta como antes.
- Contenido: vectores de pasajes del índice (fichas publicadas y conocimiento verificado), con la clave SHA-256
  del texto. Nunca guarda consultas de visitantes ni texto plano.
- La huella del modelo (nombre, prefijo y metadatos de sus archivos) forma parte del nombre del archivo: otro
  modelo u otros pesos generan otro archivo y no reutilizan vectores incompatibles.
- Escritura atómica (temporal + os.replace) y solo de los pasajes vigentes: no crece con contenido retirado.
- Cualquier error de lectura/escritura se ignora y se recodifica: la caché nunca impide servir ni sincronizar.
"""

from __future__ import annotations

import hashlib
import logging
import os
import tempfile
from pathlib import Path
from typing import Any

logger = logging.getLogger("horus_ai.semantic")

_MODEL_METADATA = (
    "config.json",
    "modules.json",
    "config_sentence_transformers.json",
    "sentence_bert_config.json",
)
_MODEL_WEIGHTS = ("model.safetensors", "pytorch_model.bin")


def model_fingerprint(model_dir: Path, model_name: str, passage_prefix: str) -> str:
    digest = hashlib.sha256(f"{model_name}\x00{passage_prefix}".encode())
    for name in _MODEL_METADATA:
        path = model_dir / name
        if path.is_file():
            digest.update(name.encode() + b"\x00" + path.read_bytes())
    for name in _MODEL_WEIGHTS:
        path = model_dir / name
        if path.is_file():
            stat = path.stat()
            digest.update(f"{name}\x00{stat.st_size}\x00{stat.st_mtime_ns}".encode())
    return digest.hexdigest()


def _key(text: str) -> bytes:
    return hashlib.sha256(text.encode("utf-8")).hexdigest().encode("ascii")


class PassageVectorCache:
    def __init__(self, directory: Path, fingerprint: str) -> None:
        self.path = directory / f"passage-vectors-{fingerprint[:24]}.npz"

    def load(self, texts: list[str]) -> dict[str, Any]:
        """Vectores guardados para los textos pedidos; vacío si el archivo falta o no supera la validación."""
        if not self.path.is_file():
            return {}
        try:
            import numpy as np

            with np.load(self.path, allow_pickle=False) as data:
                keys = data["keys"]
                vectors = data["vectors"]
            if (
                keys.ndim != 1
                or vectors.ndim != 2
                or len(keys) != len(vectors)
                or vectors.dtype != np.float32
                or not np.isfinite(vectors).all()
            ):
                raise ValueError("forma")
            stored = {bytes(k): v for k, v in zip(keys.tolist(), vectors, strict=True)}
        except Exception:
            logger.warning("vector-cache outcome=ignored")
            return {}
        return {text: stored[_key(text)] for text in dict.fromkeys(texts) if _key(text) in stored}

    def save(self, vectors: dict[str, Any], texts: list[str]) -> None:
        """Guarda únicamente los pasajes vigentes, de forma atómica."""
        try:
            import numpy as np

            unique = [t for t in dict.fromkeys(texts) if t in vectors]
            if not unique:
                return
            keys = np.array([_key(t) for t in unique], dtype="S64")
            matrix = np.stack([np.asarray(vectors[t], dtype=np.float32) for t in unique])
            self.path.parent.mkdir(parents=True, exist_ok=True)
            fd, tmp = tempfile.mkstemp(prefix=".passage-vectors-", suffix=".npz", dir=self.path.parent)
            try:
                with os.fdopen(fd, "wb") as handle:
                    np.savez(handle, keys=keys, vectors=matrix)
                os.replace(tmp, self.path)
            finally:
                if os.path.exists(tmp):
                    os.unlink(tmp)
        except Exception:
            logger.warning("vector-cache outcome=write_failed")
