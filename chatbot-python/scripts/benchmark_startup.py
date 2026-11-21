"""Compara arranques aislados, sin abrir servidores ni leer perfiles privados.

Uso: .venv/Scripts/python.exe scripts/benchmark_startup.py --runs 3
Cada muestra usa un proceso nuevo y el mismo modelo/conocimiento local. No mide
un reinicio de Windows, FastAPI por HTTP ni la renovación del catálogo Laravel.
"""

from __future__ import annotations

import argparse
import json
import os
import socket
import statistics
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))


def _deny_network(*args: object, **kwargs: object) -> None:
    raise OSError("Red bloqueada durante la medición de arranque.")


def measure(backend: str, cache_dir: Path) -> dict[str, object]:
    # La medición tampoco permite loopback: nunca entra en la aplicación real.
    socket.socket.connect = _deny_network
    socket.create_connection = _deny_network
    os.environ["HF_HUB_OFFLINE"] = "1"
    os.environ["TRANSFORMERS_OFFLINE"] = "1"
    os.environ["HF_HUB_DISABLE_TELEMETRY"] = "1"
    os.environ["HF_HUB_DISABLE_PROGRESS_BARS"] = "1"
    started = time.perf_counter()

    from app.services.knowledge import KnowledgeBase
    from app.services.semantic_search import SemanticIndex, SentenceTransformerEmbedder
    from app.services.vector_cache import PassageVectorCache, model_fingerprint

    model_dir = ROOT / "models/paraphrase-multilingual-MiniLM-L12-v2"
    if backend == "original":
        import torch
        from sentence_transformers import SentenceTransformer

        torch.set_num_threads(2)
        model = SentenceTransformer(str(model_dir), device="cpu", local_files_only=True)

        class OriginalEmbedder:
            def encode(self, texts):
                return model.encode(
                    texts,
                    batch_size=16,
                    normalize_embeddings=True,
                    convert_to_numpy=True,
                    show_progress_bar=False,
                )

        embedder = OriginalEmbedder()
    else:
        embedder = SentenceTransformerEmbedder(model_dir, 2)
    loaded = time.perf_counter()
    kb = KnowledgeBase.load(ROOT / "data/knowledge.json", ROOT / "data/intents.json")
    index = SemanticIndex(kb.search_records)
    index.vector_cache = PassageVectorCache(cache_dir, model_fingerprint(model_dir, "MiniLM-local", ""))
    index.attach(embedder)
    indexed = time.perf_counter()
    hits = index.search("Informacion sobre camaras de seguridad")
    finished = time.perf_counter()
    return {
        "backend": backend,
        "load_seconds": round(loaded - started, 4),
        "index_seconds": round(indexed - loaded, 4),
        "first_query_seconds": round(finished - indexed, 4),
        "total_seconds": round(finished - started, 4),
        "passages": len(index._owners),
        "ready": index.ready,
        "has_results": bool(hits),
        "training_imported": "sentence_transformers.sentence_transformer.trainer" in sys.modules,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runs", type=int, choices=range(1, 6), default=3)
    parser.add_argument("--child", choices=["original", "optimized"], help=argparse.SUPPRESS)
    parser.add_argument("--cache-dir", type=Path, help=argparse.SUPPRESS)
    args = parser.parse_args()
    if args.child:
        if args.cache_dir is None:
            parser.error("La medición hija requiere un directorio temporal de caché.")
        print(json.dumps(measure(args.child, args.cache_dir)))
        return 0
    samples = []
    with tempfile.TemporaryDirectory(prefix="horus-startup-") as temporary:
        for run in range(args.runs):
            # Alterna el primer método para reducir el sesgo de caché del sistema operativo.
            backends = ("original", "optimized") if run % 2 == 0 else ("optimized", "original")
            for backend in backends:
                result = subprocess.run(
                    [
                        sys.executable,
                        str(Path(__file__).resolve()),
                        "--child",
                        backend,
                        "--cache-dir",
                        str(Path(temporary) / backend),
                    ],
                    cwd=ROOT,
                    capture_output=True,
                    text=True,
                    check=False,
                )
                if result.returncode != 0:
                    print(json.dumps({"error": "startup_measurement_failed", "backend": backend}))
                    return 1
                sample = json.loads(result.stdout)
                sample["run"] = run + 1
                samples.append(sample)
                print(json.dumps(sample), flush=True)
    medians = {
        backend: statistics.median(s["total_seconds"] for s in samples if s["backend"] == backend)
        for backend in ("original", "optimized")
    }
    print(
        json.dumps(
            {
                "median_seconds": medians,
                "reduction_percent": round(100 * (1 - medians["optimized"] / medians["original"]), 1),
                "runs_per_backend": args.runs,
                "scope": "new_process_local_model_index_and_first_query_no_network",
            }
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
