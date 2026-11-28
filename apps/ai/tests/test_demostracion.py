"""Lista de ensayo de la sustentación (27/11/2026): ninguna pregunta de la demostración puede dejar de acertar.

Las preguntas con datos personales (BLOQUEO_OK) las detiene el filtro de Laravel antes del motor; se comprueban con
`scripts/medir_oraciones.py --archivo data/oraciones_demostracion.json --validar-laravel --filtro` (52 de 52).
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

from app.services.chat_engine import AdvisorEngine

sys.path.insert(0, str(Path(__file__).parents[1] / "scripts"))
from medir_oraciones import medir, medir_conversaciones, motor  # noqa: E402

BANCO = json.loads((Path(__file__).parents[1] / "data" / "oraciones_demostracion.json").read_text(encoding="utf-8"))


def test_todas_las_preguntas_de_la_demostracion_aciertan_en_el_motor() -> None:
    casos = [c for c in BANCO["casos"] if "BLOQUEO_OK" not in c["esperado"]]
    filas, _ = medir(casos, con_filtro=False)
    fallan = [(f["texto"], f["resultado"]) for f in filas if f["resultado"] != "acierta"]
    assert not fallan, fallan
    filas, _ = medir_conversaciones(BANCO["conversaciones"], con_filtro=False)
    fallan = [(f["texto"], f["resultado"]) for f in filas if f["resultado"] != "acierta"]
    assert not fallan, fallan


def test_pedir_asesoria_para_la_empresa_responde_la_ficha_y_no_una_aclaracion() -> None:
    engine: AdvisorEngine = motor()
    reply = engine.chat("Necesito asesoría para mi empresa")
    assert [s.id for s in reply.sources] == ["asesoramiento_empresarial"]
    # Educativa, empresarial y legal empatan: responde la ficha general, no la primera en orden alfabético.
    assert [s.id for s in engine.chat("necesito asesoria").sources] == ["asesoramiento_general"]
    # Ninguna ficha publicada menciona la formalización: se pide una aclaración en vez de suponer el servicio.
    assert engine.chat("Quiero formalizar mi negocio, ¿me pueden asesorar?").intent == "clarification"
