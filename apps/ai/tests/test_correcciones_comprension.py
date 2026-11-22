"""Regresiones de comprensión (22/11/2026): ortografía por sonido, cortesía, seguimientos y empates.

Las frases son deliberadamente distintas de data/evaluation.json para medir generalización, no memoria.
"""

from __future__ import annotations

import pytest

from app.core.text import correct_tokens, phonetic_key
from app.schemas.chat import HistoryItem
from app.services.chat_engine import OUT_OF_SCOPE_OPENING, AdvisorEngine
from app.services.understanding import evidence_sentence


def _ids(reply) -> list[str]:
    return [s.id for s in reply.sources]


def _historial(*mensajes: str) -> list[HistoryItem]:
    return [HistoryItem(role="user", content=m) for m in mensajes]


@pytest.mark.parametrize(
    ("escrito", "correcto"),
    [("birtuales", "virtuales"), ("teziz", "tesis"), ("orarios", "horarios"), ("cotisasion", "cotizacion")],
)
def test_clave_fonetica_iguala_errores_por_sonido(escrito: str, correcto: str) -> None:
    assert phonetic_key(escrito) == phonetic_key(correcto)


def test_correccion_por_sonido_no_adivina_entre_palabras_que_suenan_igual() -> None:
    # «casa» y «caza» comparten clave: ninguna de las dos se impone sobre la otra.
    assert correct_tokens(["kasa"], frozenset({"casa", "caza"})) == ["kasa"]


def test_modalidad_virtual_con_b(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿dan clases birtuales?")
    assert "cursos_modalidades" in _ids(reply)
    assert not reply.abstained
    # Mal escrito y bien escrito llevan a la misma respuesta.
    assert reply.answer == engine.chat("¿dan clases virtuales?").answer


def test_tesis_con_z(engine: AdvisorEngine) -> None:
    reply = engine.chat("necesito ayuda con mi teziz")
    assert "asesoramiento_educativo" in _ids(reply)


@pytest.mark.parametrize("mensaje", ["mil gracias por la información", "gracias por toda la ayuda"])
def test_agradecimiento_con_cortesia_es_plantilla(engine: AdvisorEngine, mensaje: str) -> None:
    assert engine.chat(mensaje).intent == "thanks"


def test_agradecimiento_con_pregunta_sigue_a_la_busqueda(engine: AdvisorEngine) -> None:
    reply = engine.chat("gracias, ¿y qué convenios tienen?")
    assert reply.intent != "thanks"
    assert "convenios_resumen" in _ids(reply)


def test_seguimiento_que_nombra_otro_registro_institucional(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿y en qué horario atienden?", _historial("¿Cuál es su dirección?"))
    assert _ids(reply) == ["horario_atencion"]


def test_seguimiento_de_certificado_tras_lista_de_convenios(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿y entregan certificado?", _historial("¿Con qué instituciones tienen convenio?"))
    assert _ids(reply) == ["cursos_certificados"]


def test_seguimiento_sobre_programa_conserva_el_foco(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿y dan certificado?", _historial("Quiero clases personalizadas"))
    assert _ids(reply) == ["formacion_personalizada"]


def test_pregunta_suelta_de_tiempo_pide_aclaracion(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Para cuándo?")
    assert reply.needs_clarification
    assert reply.sources == []


def test_servicios_responde_a_que_se_dedica_no_precios(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Qué servicios brindan?")
    assert _ids(reply) == ["empresa_descripcion"]
    assert not reply.abstained


def test_empate_lo_decide_la_frase_exacta(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿me dices sus horarios?")
    assert _ids(reply) == ["horario_atencion"]


def test_diplomados_no_es_evidencia_de_certificado(engine: AdvisorEngine) -> None:
    record = engine.kb.get("convenio_administracion")
    oracion = evidence_sentence(record, engine.kb.intents.attribute_evidence_terms["certificado"])
    assert oracion is not None and "certificados" in oracion


def test_certificado_de_un_convenio_cita_la_frase_de_certificados(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿y otorgan certificado?", _historial("Quiero capacitarme en administración"))
    assert _ids(reply) == ["convenio_administracion"]
    assert "certificados" in reply.answer


def test_eso_tras_un_tema_fuera_de_alcance_sigue_fuera_de_alcance(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿y eso también lo hacen en hogares?", _historial("Necesito instalar cámaras en mi negocio"))
    assert reply.answer.startswith(OUT_OF_SCOPE_OPENING)
    assert reply.sources == [] and reply.recommendations == []


def test_pregunta_nueva_con_y_tras_fuera_de_alcance_se_responde(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿y qué cursos tienen?", _historial("Necesito instalar cámaras en mi negocio"))
    assert not reply.answer.startswith(OUT_OF_SCOPE_OPENING)


def test_eso_tras_un_dato_no_publicado_no_es_fuera_de_alcance(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿y eso se puede pagar con Plin?", _historial("¿Cuánto cuesta un curso?"))
    assert "faq_medios_pago" in _ids(reply)


# --- Sinónimos y formas verbales de uso general (frases distintas del dataset de evaluación) ---


@pytest.mark.parametrize(
    ("consulta", "registro"),
    [
        ("¿Dónde los puedo ubicar?", "ubicacion_sede"),
        ("¿A qué rubro pertenece la compañía?", "empresa_descripcion"),
        ("¿puedo pagar todo al contado?", "faq_medios_pago"),
        ("¿Ofrecen algún diplomado?", "convenios_resumen"),
        ("¿Emiten comprobante a nombre de mi negocio?", "faq_comprobantes"),
    ],
)
def test_sinonimos_llevan_al_registro_correcto(engine: AdvisorEngine, consulta: str, registro: str) -> None:
    reply = engine.chat(consulta)
    assert _ids(reply) == [registro]
    assert not reply.abstained and not reply.needs_clarification


def test_contado_no_se_corrige_a_contrato(engine: AdvisorEngine) -> None:
    assert "contrato" not in engine.corrected("pago al contado")


def test_validez_en_el_extranjero_se_abstiene(engine: AdvisorEngine) -> None:
    reply = engine.chat("¿Mi certificado sirve en el extranjero?")
    assert reply.abstained and reply.intent == "unverified_info"


def test_economistas_en_plural_detecta_el_convenio(engine: AdvisorEngine) -> None:
    reply = engine.chat("Busco un seminario para economistas")
    assert [r.id for r in reply.recommendations] == ["convenio_economia"]


def test_principiante_sin_tema_pregunta_el_area(engine: AdvisorEngine) -> None:
    reply = engine.chat("Soy nuevo en esto de estudiar, ¿con qué empiezo?")
    assert reply.needs_clarification and reply.intent == "course_recommendation"


@pytest.mark.parametrize("consulta", ["necesito ayuda", "tengo una duda", "dame información de eso"])
def test_pedido_generico_sin_contexto_pide_aclaracion(engine: AdvisorEngine, consulta: str) -> None:
    reply = engine.chat(consulta)
    assert reply.needs_clarification and reply.sources == []


def test_mas_informacion_sigue_llevando_al_contacto(engine: AdvisorEngine) -> None:
    assert _ids(engine.chat("Quiero más información")) == ["contacto_canales"]


@pytest.mark.parametrize("consulta", ["Mi negocio necesita algo", "Necesitamos apoyo para nuestra empresa"])
def test_la_aclaracion_no_ofrece_servicios_retirados(engine: AdvisorEngine, consulta: str) -> None:
    reply = engine.chat(consulta)
    texto = (reply.answer + " " + (reply.clarification_question or "")).lower()
    for retirado in ("cámaras", "cableado", "mantenimiento de computadoras", "servicio adecuado"):
        assert retirado not in texto, (consulta, texto)


@pytest.mark.parametrize(
    ("consulta", "prohibido"),
    [("¿Venden celulares usados?", "contacto_canales"), ("¿Me ayudas con mi tarea de física?", "faq_medios_pago")],
)
def test_una_palabra_suelta_no_basta_para_responder(engine: AdvisorEngine, consulta: str, prohibido: str) -> None:
    reply = engine.chat(consulta)
    assert prohibido not in _ids(reply)
    assert reply.abstained or reply.needs_clarification
