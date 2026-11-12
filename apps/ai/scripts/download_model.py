"""Descarga EXPLÍCITA y única del modelo de embeddings a chatbot-python/models/.

Es el único paso que accede a Internet (Hugging Face Hub). El servicio nunca descarga en
tiempo de ejecución: carga el modelo desde disco con HF_HUB_OFFLINE=1.

Uso (PowerShell, desde chatbot-python/):
    .venv\\Scripts\\python.exe scripts\\download_model.py
    .venv\\Scripts\\python.exe scripts\\download_model.py --model intfloat/multilingual-e5-small
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_MODEL = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"

# Solo los archivos necesarios para sentence-transformers en PyTorch (se omiten ONNX, OpenVINO, TF).
ALLOW = [
    "*.json",
    "*.txt",
    "model.safetensors",
    "sentencepiece.bpe.model",
    "tokenizer.model",
    "1_Pooling/*",
    "2_Normalize/*",
]


def main() -> int:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument("--model", default=DEFAULT_MODEL, help="Repositorio de Hugging Face")
    args = parser.parse_args()
    try:
        from huggingface_hub import snapshot_download
    except ImportError:
        print("Falta huggingface_hub: instala el extra semántico (ver README §10).", file=sys.stderr)
        return 1
    target = ROOT / "models" / args.model.split("/")[-1]
    target.mkdir(parents=True, exist_ok=True)
    path = snapshot_download(repo_id=args.model, local_dir=target, allow_patterns=ALLOW)
    size_mb = sum(f.stat().st_size for f in Path(path).rglob("*") if f.is_file()) / 1_048_576
    print(f"Modelo descargado en {target.relative_to(ROOT)} ({size_mb:.0f} MB).")
    print(f"Configura HORUS_AI_SEMANTIC_ENABLED=true y HORUS_AI_SEMANTIC_MODEL_DIR=models/{target.name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
