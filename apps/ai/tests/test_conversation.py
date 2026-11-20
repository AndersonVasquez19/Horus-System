"""Sprint 2: contexto conversacional, seguimiento, cambios de tema, cotizaciones y explicabilidad."""

from __future__ import annotations

import re

from app.core.text import correct_tokens, tokens_match
from app.schemas.chat import HistoryItem
from app.services.chat_engine import AdvisorEngine
from app.services.semantic_search import SemanticHit


def _h(*turns: str) -> list[HistoryItem]:
    return [HistoryItem(role="user", content=t) for t in turns]


def test_followup_resolves_eso_without_inventing(engine: AdvisorEngine) -> None:
    first = engine.chat("Quiero aprender tecnología")
    assert first.needs_clarification
    second = engine.chat("Me interesa el mantenimiento", _h("Quiero aprender tecnología"))
    assert "mantenimiento" in second.answer.lower()
    third = engine.chat(
        "¿Y eso es presencial?", _h("Quiero aprender tecnología", "Me interesa el mantenimiento")
    )
    assert third.intent == "followup_attribute"
    assert third.context is not None and third.context.focus_ids
    assert "sí figura la modalidad presencial" not in third.answer  # no se afirma lo no publicado
    assert "no está publicada" in third.answer


def test_followup_confirms_only_published_modality(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Eso puede ser online?", _h("Me interesa capacitar a mi personal"))
    assert reply.intent == "followup_attribute" and not reply.abstained
    assert "online" in reply.answer
    assert reply.sources[0].id == "capacitaciones_empresariales"


def test_followup_audience_quotes_the_source(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Y eso lo hacen también para casas?", _h("Mi empresa necesita cámaras"))
    assert reply.intent == "followup_attribute"
    assert "hogar" in reply.answer.lower()


def test_followup_price_abstains(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Y cuánto cuesta?", _h("Soy enfermera y quiero capacitarme"))
    assert reply.abstained and reply.intent == "unavailable_info"
    assert not re.search(r"S/|\d+\s*soles", reply.answer)


def test_assistant_text_is_never_used_as_fact(engine: AdvisorEngine) -> None:
    history = [
        HistoryItem(role="user", content="Me interesa el mantenimiento"),
        HistoryItem(role="assistant", content="Sí, el curso de mantenimiento es presencial y cuesta S/ 99."),
    ]
    reply = engine.chat("¿Y eso es presencial?", history)
    assert "99" not in reply.answer and "sí figura la modalidad presencial" not in reply.answer


def test_topic_change_discards_context(engine: AdvisorEngine) -> None:
    reply = engine.chat("Ahora otra cosa, ¿aceptan Yape?", _h("Quiero instalar cámaras en mi casa"))
    assert reply.sources[0].id == "faq_medios_pago"
    assert reply.context is not None and reply.context.topic_changed and reply.context.turns_considered == 0


def test_context_is_limited_to_three_user_turns(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Y eso es presencial?", _h(*[f"Consulta número {i}" for i in range(8)]))
    assert reply.context is not None and reply.context.turns_considered == 3


def test_named_subject_is_not_overridden_by_context(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Y el de cableado?", _h("¿Qué servicios tienen?"))
    ids = [s.id for s in reply.sources] + [r.id for r in reply.recommendations]
    assert ids[0] == "servicio_cableado"


def test_quote_request_guides_without_prices(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Cuánto me cobrarían por el mantenimiento de 10 computadoras?")
    assert reply.intent == "quote_request"
    assert reply.sources[0].id == "servicio_soporte"
    assert "10" not in reply.answer  # no repite ni calcula cifras del visitante
    assert "no calcula precios" in reply.answer
    assert "no es un requisito publicado" in reply.answer
    assert any("precio" in m for m in reply.missing_data)


def test_quote_without_subject_asks_and_continues(engine: AdvisorEngine) -> None:
    first = engine.chat("Quiero una cotización")
    assert first.intent == "quote_request" and first.needs_clarification
    second = engine.chat("para cámaras de seguridad", _h("Quiero una cotización"))
    assert second.intent == "quote_request" and second.sources[0].id == "servicio_camaras"


def test_private_quote_is_never_consulted(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Cuál fue la cotización que me enviaron la semana pasada?")
    assert reply.intent == "quote_request" and reply.abstained
    assert reply.sources == [] and reply.recommendations == []
    assert "No tengo acceso a cotizaciones emitidas" in reply.answer


def test_unpublished_attribute_on_known_subject(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Cuántas vacantes quedan en el curso de enfermería?")
    assert reply.intent == "unavailable_info" and reply.abstained
    assert reply.sources[0].id == "convenio_enfermeria"


def test_attribute_answered_by_the_record_that_contains_it(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿En cuánto tiempo responden una consulta de asesoría?")
    assert reply.sources[0].id == "asesoramiento_general"
    assert "48 horas" in reply.answer


def test_recommendations_are_explainable(engine: AdvisorEngine) -> None:
    reply = engine.chat("Mi empresa necesita cableado de red")
    first = reply.recommendations[0]
    assert first.id == "servicio_cableado"
    assert any(s.startswith("tema:") for s in first.signals)
    assert "precio sujeto a cotización con un asesor" in first.limitations
    assert first.next_step and "cotizar" in first.next_step


def test_objective_is_backed_by_literal_evidence(engine: AdvisorEngine) -> None:
    reply = engine.chat("Necesito certificados que me sirvan para postular al Estado")
    assert reply.recommendations
    for rec in reply.recommendations:
        assert rec.evidence_text and "postulaciones" in rec.evidence_text
        assert engine.kb.get(rec.id).status == "verified"  # type: ignore[union-attr]


def test_stopwords_are_not_spell_corrected(engine: AdvisorEngine) -> None:
    assert correct_tokens(["cuales", "orarios"], engine.vocabulary)[0] == "cuales"
    assert tokens_match("ubicar", "ubicacion") and not tokens_match("descubrio", "descuento")


class _StubSemantic:
    ready = True

    def __init__(self, cosine: float) -> None:
        self.cosine = cosine

    def search(self, text, record_ids=None):  # type: ignore[no-untyped-def]
        return {"ubicacion_sede": SemanticHit("ubicacion_sede", self.cosine, 0.6)}


def test_semantic_only_match_needs_high_similarity(engine: AdvisorEngine) -> None:
    low = AdvisorEngine(engine.kb, _StubSemantic(0.65))  # type: ignore[arg-type]
    hits = low.retrieve("¿Cuál es la capital del Perú?")
    assert all(h.semantic is None for h in hits)  # coseno 0,65 sin apoyo léxico: se ignora
    assert all(h.score < 0.32 for h in hits)  # "Perú" solo aporta una coincidencia léxica mínima
    assert low.chat("¿Cuál es la capital del Perú?").intent == "out_of_scope"
    high = AdvisorEngine(engine.kb, _StubSemantic(0.80))  # type: ignore[arg-type]
    hits = high.retrieve("frase sin palabras del dominio")
    assert hits and hits[0].record.id == "ubicacion_sede" and hits[0].score < 0.55  # solo puede aclarar


def test_unconfirmed_doubt_is_not_overridden_by_evidence(engine: AdvisorEngine) -> None:
    """Regresión del sprint 2: la validez internacional no confirmada no se responde con otra cita."""
    reply = engine.chat("¿El certificado tiene validez internacional?")
    assert reply.abstained and reply.intent == "unverified_info"
