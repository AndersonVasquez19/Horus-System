from __future__ import annotations

from app.services.chat_engine import AdvisorEngine

WHATSAPP_QUOTE_URL = "https://wa.me/51927582305?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n"
WHATSAPP_MATRICULA_URL = (
    "https://wa.me/51927582305?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula"
)


def test_que_capacitaciones_ofrecen_answers_directly_without_clarification(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Qué capacitaciones ofrecen?")
    assert not reply.needs_clarification, "No debe pedir aclaración entre servicios y capacitaciones"
    assert reply.intent == "training_info"
    assert reply.sources and reply.sources[0].id == "capacitaciones_empresariales"
    assert "capacitaciones empresariales" in reply.answer.lower()
    assert "presencial u online" in reply.answer.lower()
    assert (
        "certificación incluida" in reply.answer.lower() or "certificacion incluida" in reply.answer.lower()
    )
    assert "¿te refieres a" not in reply.answer.lower()


def test_que_modalidades_tienen_answers_modalities(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Qué modalidades tienen?")
    assert not reply.needs_clarification
    assert reply.intent == "training_info"
    assert reply.sources and reply.sources[0].id == "cursos_modalidades"
    assert "presencial" in reply.answer.lower()
    assert "semipresencial" in reply.answer.lower()
    assert "virtual" in reply.answer.lower()


def test_ofrecen_capacitacion_para_empresas(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Ofrecen capacitación para empresas?")
    assert not reply.needs_clarification
    assert reply.intent == "training_info"
    assert reply.sources and reply.sources[0].id == "capacitaciones_empresariales"


def test_que_cursos_tienen_disponibles_abstains_honestly(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Qué cursos tienen disponibles?")
    assert reply.abstained
    assert reply.intent == "unavailable_info"
    assert reply.sources == []
    assert (
        "no publica todavía la lista de cursos vigentes" in reply.answer.lower()
        or "no publica todavia" in reply.answer.lower()
    )


def test_quiero_capacitar_a_mi_personal(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Quiero capacitar a mi personal?")
    assert not reply.needs_clarification
    assert reply.intent == "training_info"
    assert reply.sources and reply.sources[0].id == "capacitaciones_empresariales"


def test_quote_request_uses_whatsapp_and_no_email(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Cómo puedo solicitar una cotización?")
    assert reply.intent == "quote_request"
    assert WHATSAPP_QUOTE_URL in reply.answer
    assert "@" not in reply.answer, "No debe mostrar correo electrónico para cotizaciones"
    assert "horusgroupcajamarca@gmail.com" not in reply.answer


def test_matricula_request_uses_whatsapp_and_no_email(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Cómo puedo matricularme?")
    assert WHATSAPP_MATRICULA_URL in reply.answer
    assert "@" not in reply.answer, "No debe mostrar correo electrónico para matrículas"
    assert "horusgroupcajamarca@gmail.com" not in reply.answer
    assert "asesor" in reply.answer.lower()
    assert "comprobante" in reply.answer.lower()
