"""Redacción clara: ninguna respuesta cita «lo publicado», la fuente, la web ni rutas del sitio en el texto."""

from __future__ import annotations

import json
import re

import pytest

from app.core.config import PROJECT_ROOT
from app.services.chat_engine import AdvisorEngine
from tests.test_phase_c import make_engine

# Rutas del sitio escritas en el texto (los enlaces salen de las fuentes estructuradas, no del texto).
RUTA = re.compile(r"(?<![\w/:.])/(?:educacion|legales|contactos|quienes-somos|tecnologias)\b")
PROHIBIDO = re.compile(r"\bpublicad[oa]s?\b|\bFuente:|\bSegún la web\b|\bla web (?:no )?publica\b|\blo publicado\b", re.IGNORECASE)

CONSULTAS_EXTRA = [
    "¿Qué cursos tienen?", "Me interesa estudiar Python", "¿Cuánto dura el curso de analisis de datos?", "¿Es presencial?",
    "¿Cuánto cuesta?", "¿Cómo me matriculo?", "¿Qué convenios tienen?", "Vigencia del convenio con CORLAD",
    "¿Qué asesoramiento ofrecen?", "¿Cuál es el horario?", "¿Dónde están ubicados?", "¿Cómo pago?",
    "Se me perdió el certificado", "Quiero cotizar una capacitación para mi personal", "¿Qué servicios tecnológicos ofrecen?",
    "Quiero aprender cocina", "Hola", "¿Qué puedes hacer?", "¿Tienen redes sociales?", "Política de devolución",
]


def _consultas() -> list[str]:
    dataset = json.loads((PROJECT_ROOT / "data/evaluation.json").read_text(encoding="utf-8"))
    return list(dict.fromkeys([i["query"] for i in dataset["items"]] + CONSULTAS_EXTRA))


@pytest.mark.parametrize("modo", ["conocimiento", "catalogo"])
def test_las_respuestas_no_citan_lo_publicado_ni_rutas(kb, modo):
    engine = AdvisorEngine(kb) if modo == "conocimiento" else make_engine()
    problemas = []
    for consulta in _consultas():
        reply = engine.chat(consulta)
        textos = [reply.answer, reply.clarification_question or ""]
        for texto in textos:
            if PROHIBIDO.search(texto) or RUTA.search(texto):
                problemas.append((consulta, texto[:160]))
    assert problemas == [], problemas[:10]
