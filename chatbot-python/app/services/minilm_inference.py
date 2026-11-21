"""Inferencia del MiniLM local sin importar las herramientas de entrenamiento.

Solo admite la composición Transformer + mean pooling conocida. Cualquier otra
configuración se deja al cargador original de sentence-transformers.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

_MODULES = [
    {"idx": 0, "name": "0", "path": "", "type": "sentence_transformers.models.Transformer"},
    {"idx": 1, "name": "1", "path": "1_Pooling", "type": "sentence_transformers.models.Pooling"},
]
_POOLING = {
    "word_embedding_dimension": 384,
    "pooling_mode_cls_token": False,
    "pooling_mode_mean_tokens": True,
    "pooling_mode_max_tokens": False,
    "pooling_mode_mean_sqrt_len_tokens": False,
}


def supports_local_minilm(model_dir: Path) -> bool:
    """Reconoce el pipeline local completo; no ignora prompts ni módulos extra."""
    try:
        modules = json.loads((model_dir / "modules.json").read_text(encoding="utf-8"))
        pooling = json.loads((model_dir / "1_Pooling/config.json").read_text(encoding="utf-8"))
        sentence = json.loads((model_dir / "sentence_bert_config.json").read_text(encoding="utf-8"))
        config = json.loads((model_dir / "config.json").read_text(encoding="utf-8"))
        pipeline = json.loads((model_dir / "config_sentence_transformers.json").read_text(encoding="utf-8"))
        return (
            modules == _MODULES
            and pooling == _POOLING
            and sentence == {"max_seq_length": 128, "do_lower_case": False}
            and isinstance(config, dict)
            and config.get("model_type") == "bert"
            and config.get("architectures") == ["BertModel"]
            and config.get("hidden_size") == 384
            and "auto_map" not in config
            and isinstance(pipeline, dict)
            and set(pipeline) <= {"__version__"}
            and not (model_dir / "adapter_config.json").exists()
        )
    except (OSError, ValueError, TypeError):
        return False


class MiniLMInference:
    """Mismos pesos, tokenizer, límite de 128 tokens y vectores normalizados en CPU."""

    def __init__(self, model_dir: Path) -> None:
        from transformers import AutoModel, AutoTokenizer

        self._tokenizer = AutoTokenizer.from_pretrained(
            str(model_dir), local_files_only=True, trust_remote_code=False, model_max_length=128
        )
        self._transformer = (
            AutoModel.from_pretrained(str(model_dir), local_files_only=True, trust_remote_code=False)
            .to("cpu")
            .eval()
        )

    def encode(self, texts: list[str]) -> Any:
        import numpy as np
        import torch

        if not texts:
            return np.empty((0, 384), dtype=np.float32)
        # Conserva el orden de lotes del cargador original, también para pasajes en caché.
        order = np.argsort([-len(text) for text in texts])
        sorted_texts = [texts[index] for index in order]
        batches = []
        with torch.inference_mode():
            for start in range(0, len(sorted_texts), 16):
                tokens = self._tokenizer(
                    sorted_texts[start : start + 16],
                    padding=True,
                    truncation="longest_first",
                    max_length=128,
                    return_tensors="pt",
                )
                hidden = self._transformer(**tokens)[0]
                mask = tokens["attention_mask"].unsqueeze(-1).expand_as(hidden).to(hidden.dtype)
                mean = (hidden * mask).sum(dim=1) / torch.clamp(mask.sum(dim=1), min=1e-9)
                batches.append(torch.nn.functional.normalize(mean, p=2, dim=1).cpu().numpy())
        return np.concatenate(batches)[np.argsort(order)]
