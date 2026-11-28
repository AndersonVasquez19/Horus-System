"""Canales desde una sola fuente y respuestas sin callejón (22/11/2026).

- Teléfono, correo y enlaces de WhatsApp salen de `public_contact` en data/knowledge.json (app/services/contact.py).
  Laravel guarda los mismos canales en constantes de validación; ContactoPublicoUnicoTest.php exige que coincidan.
- Una abstención por dato sin verificar, sin otra opción que ofrecer, indica a quién preguntar.
  apps/api/tests/Fixtures/chat-sin-callejones.json guarda esas respuestas para comprobar que Laravel las acepta.
"""

from __future__ import annotations

import json
import re
from pathlib import Path

import pytest

from app.services.chat_engine import AdvisorEngine
from app.services.contact import PublicContact

ROOT = Path(__file__).parents[1]
FIXTURE = ROOT.parent / "api" / "tests" / "Fixtures" / "chat-sin-callejones.json"
# Literales que Laravel valida (HorusAdvisorClient::CANALES_OFICIALES_PUBLICOS).
QUOTE_URL = "https://wa.me/51972021374?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n"
ENROLLMENT_URL = "https://wa.me/51972021374?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula"


def test_los_enlaces_derivados_coinciden_con_los_que_valida_laravel(engine: AdvisorEngine) -> None:
    contact = engine.contact
    assert contact == PublicContact.from_mapping(engine.kb.data.public_contact)
    assert contact.quote_url == QUOTE_URL
    assert contact.enrollment_url == ENROLLMENT_URL
    assert contact.whatsapp_url == "https://wa.me/51972021374"


def test_ningun_modulo_escribe_el_telefono_o_el_correo_a_mano() -> None:
    phone, email = PublicContact.from_mapping(None).phone, PublicContact.from_mapping(None).email
    for path in (ROOT / "app").rglob("*.py"):
        if path.name == "contact.py":
            continue
        text = path.read_text(encoding="utf-8")
        for literal in (phone, email, re.sub(r"\D", "", phone)):
            assert literal not in text, f"{path.relative_to(ROOT)} repite un canal; usa app/services/contact.py"


def test_un_cambio_de_canal_en_el_conocimiento_llega_a_las_respuestas(engine: AdvisorEngine) -> None:
    otro = PublicContact.from_mapping({"phone": "+51 900 000 001", "email": "otro@example.org"})
    original = engine.contact
    try:
        engine.contact = otro
        reply = engine.chat("¿Cómo puedo solicitar una cotización?")
        assert "https://wa.me/51900000001?text=" in reply.answer
        assert "51972021374" not in reply.answer
    finally:
        engine.contact = original


@pytest.mark.parametrize(
    # ISAM salió de esta lista el 27/11/2026: su ficha pasó a verificada con el texto de la web.
    "query", ["¿Cuántos alumnos han pasado por sus capacitaciones?"]
)
def test_una_abstencion_sin_alternativa_indica_a_quien_preguntar(engine: AdvisorEngine, query: str) -> None:
    reply = engine.chat(query)
    assert reply.abstained and reply.intent == "unverified_info"
    assert reply.answer.endswith(engine.contact.advisor_line)


def test_una_abstencion_que_ya_trae_un_canal_no_lo_repite(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Cómo puedo matricularme?")
    assert engine.contact.enrollment_url in reply.answer
    assert engine.contact.email not in reply.answer


def test_las_respuestas_que_consume_laravel_proceden_del_motor_actual(engine: AdvisorEngine) -> None:
    for case in json.loads(FIXTURE.read_text(encoding="utf-8")):
        assert engine.chat(case["query"]).model_dump() == case["reply"], case["query"]
