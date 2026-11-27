"""Oraciones largas: el conjunto de desarrollo no puede volver a dar respuestas equivocadas.

El examen (data/oraciones_examen.json) no se usa aquí: solo se mide con scripts/medir_oraciones.py, para que siga
siendo una medición honesta de oraciones que el motor no vio al ajustarse.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest

from app.services import conversation_glue as glue

sys.path.insert(0, str(Path(__file__).parents[1] / "scripts"))
from medir_oraciones import medir  # noqa: E402

DESARROLLO = json.loads((Path(__file__).parents[1] / "data" / "oraciones_desarrollo.json").read_text(encoding="utf-8"))


def test_el_conjunto_de_desarrollo_no_tiene_respuestas_equivocadas():
    filas, total = medir(DESARROLLO["casos"], con_filtro=False)
    equivocadas = [(f["texto"], f["fuentes"]) for f in filas if f["resultado"] == "equivocada"]
    assert equivocadas == []
    assert total["acierta"] >= 52, total  # medición del 26/11/2026; si baja, algo se rompió


@pytest.mark.parametrize("regla, mensaje", [
    (glue.asks_discarded_service, "quiero aprender a instalar cámaras en un curso"),
    (glue.asks_discarded_service, "me interesa el mantenimiento de computadoras"),
    (glue.asks_certificate_validity, "¿el certificado tiene validez internacional?"),
    (glue.asks_certificate_validity, "perdí mi certificado, ¿me dan un duplicado?"),
    (glue.asks_missed_class, "¿qué días son las clases prácticas?"),
    (glue.asks_privacy, "¿qué datos necesito para matricularme?"),
    (glue.asks_how_to_enroll, "¿cuánto cuesta el curso de redes?"),
])
def test_las_reglas_de_oraciones_largas_no_capturan_otras_preguntas(regla, mensaje):
    assert not regla(mensaje)


@pytest.mark.parametrize("mensaje", [
    "Hacen el cableado de red de mi oficina nueva, cuánto me cobrarían",
    "tambien reparan computadoras porque una de mis maquinas esta muy lenta",
])
def test_los_servicios_retirados_no_se_ofrecen_ni_se_nombran(engine, mensaje):
    respuesta = engine.chat(mensaje)
    assert respuesta.abstained and not respuesta.sources
    assert not any(p in respuesta.answer.lower() for p in ("cámara", "camara", "cableado", "soporte técnico", "reparación"))
