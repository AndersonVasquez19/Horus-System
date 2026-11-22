"""Datos académicos del catálogo (22/11/2026): inicio, duración, certificación, temario y enlace.

Las frases esperadas son el contrato con Laravel: FuentesCatalogoAdvisor reconstruye exactamente las
mismas (tests/Feature/AdvisorCamposAcademicosTest.php usa estos literales). Si cambian aquí, cambian allá.
"""

from __future__ import annotations

from datetime import UTC, date, datetime

import pytest

from app.schemas.chat import HistoryItem
from app.services.catalog import long_date
from tests.test_phase_c import make_engine

TITULO = "Ofimática para oficinas"
ITEM = {
    "stable_id": "curso:publicacion-7",
    "type": "curso",
    "publication_status": "vigente",
    "title": TITULO,
    "summary": "Aprende Excel y Word para el trabajo diario.",
    "details": "Inscripción por WhatsApp oficial. Temario: Excel básico, tablas dinámicas y Word profesional.",
    "modalities": ["virtual"],
    "duration_text": "8 semanas",
    "schedule_text": "sábados de 9 a 12",
    "start_date": "2026-12-05",
    "certification_text": "Certificado digital a nombre de Horus Group.",
    "url_path": "/educacion/cursos",
    "updated_at": datetime.now(UTC).isoformat(),
}

INICIO_Y_HORARIO = "«Ofimática para oficinas»: inicia el 5 de diciembre de 2026; el horario es sábados de 9 a 12."
SOLO_INICIO = "«Ofimática para oficinas»: inicia el 5 de diciembre de 2026."
CERTIFICACION = "«Ofimática para oficinas»: certificación: Certificado digital a nombre de Horus Group."
DURACION = "«Ofimática para oficinas»: dura 8 semanas."
TEMARIO = "Ofimática para oficinas: Inscripción por WhatsApp oficial. Temario: Excel básico, tablas dinámicas y Word profesional."
RESPUESTA_COMPLETA = (
    "Ofimática para oficinas: Aprende Excel y Word para el trabajo diario. Modalidad: virtual. Duración: 8 semanas. "
    "Inicio: 5 de diciembre de 2026. Horario: sábados de 9 a 12. "
    "Certificación: Certificado digital a nombre de Horus Group."
)


@pytest.fixture(scope="module")
def engine():
    return make_engine([ITEM])


def _historial(*mensajes: str) -> list[HistoryItem]:
    return [HistoryItem(role="user", content=m) for m in mensajes]


@pytest.mark.parametrize(
    ("valor", "texto"),
    [(date(2026, 12, 5), "5 de diciembre de 2026"), (date(2027, 1, 31), "31 de enero de 2027")],
)
def test_fecha_larga_en_espanol(valor: date, texto: str) -> None:
    assert long_date(valor) == texto


def test_la_ficha_completa_incluye_los_datos_academicos(engine) -> None:
    record = engine.kb.get("cat_curso_publicacion_7")
    assert record.detail_answer == RESPUESTA_COMPLETA
    assert record.source.url_path == "/educacion/cursos"


@pytest.mark.parametrize(
    ("historial", "pregunta", "esperada"),
    [
        ((), "¿Cuándo empieza el curso de Ofimática para oficinas?", INICIO_Y_HORARIO),
        (("Quiero información del curso de Ofimática para oficinas",), "¿y cuándo inicia?", INICIO_Y_HORARIO),
        ((), "¿El curso de Ofimática para oficinas da certificado?", CERTIFICACION),
        (("Quiero información del curso de Ofimática para oficinas",), "¿y dan certificado?", CERTIFICACION),
        ((), "¿Cuánto dura el curso de Ofimática para oficinas?", DURACION),
        ((), "¿Cuál es el temario del curso de Ofimática para oficinas?", TEMARIO),
        ((), "¿Qué contenidos tiene el curso de Ofimática para oficinas?", TEMARIO),
        (("Quiero información del curso de Ofimática para oficinas",), "¿y qué temas ve?", TEMARIO),
    ],
)
def test_responde_cada_dato_con_la_frase_que_laravel_valida(engine, historial, pregunta, esperada) -> None:
    reply = engine.chat(pregunta, _historial(*historial))
    assert reply.answer == esperada
    assert not reply.abstained
    assert [(s.id, s.url_path) for s in reply.sources] == [("cat_curso_publicacion_7", "/educacion/cursos")]


def test_solo_fecha_de_inicio_sin_horario() -> None:
    item = {k: v for k, v in ITEM.items() if k != "schedule_text"}
    reply = make_engine([item]).chat("¿Cuándo empieza el curso de Ofimática para oficinas?")
    assert reply.answer == SOLO_INICIO


def test_sin_datos_academicos_sigue_derivando_a_un_asesor() -> None:
    item = {k: v for k, v in ITEM.items() if k not in {"start_date", "schedule_text", "certification_text"}}
    engine = make_engine([item])
    assert engine.chat("¿Cuándo empieza el curso de Ofimática para oficinas?").abstained
    reply = engine.chat("¿El curso de Ofimática para oficinas da certificado?")
    assert "certificación:" not in reply.answer
