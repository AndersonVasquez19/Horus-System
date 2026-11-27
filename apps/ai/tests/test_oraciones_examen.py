"""El examen de oraciones largas (data/oraciones_examen.json) es coherente con las fichas.

No exige un porcentaje de aciertos: eso lo informa `scripts/medir_oraciones.py`. Aquí solo se comprueba que el examen
esté bien formado, para que la medición no dependa de un error de escritura.
"""

from __future__ import annotations

import json
from pathlib import Path

from app.core.text import tokenize

RAIZ = Path(__file__).parents[1]
EXAMEN = json.loads((RAIZ / "data" / "oraciones_examen.json").read_text(encoding="utf-8"))
CATALOGO = json.loads((RAIZ.parent / "api" / "tests" / "Fixtures" / "chat-jurado-catalogo.json").read_text(encoding="utf-8"))
ESPECIALES = {"NO_DATO", "FUERA", "capabilities", "cat_listado_formacion"}


def test_cada_caso_espera_fichas_que_existen(kb):
    cursos = {"cat_curso_" + i["stable_id"].split(":")[1].replace("-", "_") for i in CATALOGO["items"]}
    validos = {r.id for r in kb.records if r.status == "verified"} | cursos | ESPECIALES
    for caso in EXAMEN["casos"]:
        assert caso["esperado"] and set(caso["esperado"]) <= validos, caso


def test_son_oraciones_largas_y_no_se_repiten():
    textos = [c["texto"] for c in EXAMEN["casos"]]
    assert len(textos) == len(set(textos)) >= 100
    assert all(len(tokenize(t)) >= 9 for t in textos), [t for t in textos if len(tokenize(t)) < 9]
