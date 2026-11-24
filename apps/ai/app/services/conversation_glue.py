"""Pegamento conversacional: mensajes cortos que solo tienen sentido dentro de una conversación.

«ok», «sí», «no, gracias», «el otro», «el primero», «más barato», «no entendí», «repite», «jaja»… Hoy caían en una
aclaración genérica o en «No tengo información confirmada». Este módulo solo CLASIFICA el mensaje (reglas
cerradas sobre el texto normalizado, sin librerías ni modelos) y ofrece frases de cortesía con variantes.

Lo que decide el motor con esta clasificación (chat_engine._glue):
  · sin contexto: una frase amable que reconduce, sin inventar nada;
  · con un curso en foco: se reutiliza el flujo de seguimiento ya verificado (detalles, precio, horario) o se elige
    la opción pedida; nunca se afirma un dato que la ficha publicada no tenga;
  · las frases de cortesía no llevan cifras, enlaces ni nombres: Laravel las acepta como cortesía sin fuentes.

Las variantes se eligen con un hash del mensaje normalizado: la misma entrada da siempre la misma respuesta
(pruebas estables) y entradas distintas no suenan a una plantilla única.
"""

from __future__ import annotations

import re
import zlib
from typing import Literal

from app.core.text import normalize, tokenize

Kind = Literal["ack", "yes", "no", "repeat", "confused", "laugh", "howareyou", "sorry", "empty"]
Pick = Literal["other", "first", "second", "last"]
Comparative = Literal["price", "duration", "schedule"]

_MAX_TOKENS = 6

_ACK = frozenset({
    "ok", "okay", "okey", "vale", "listo", "perfecto", "entiendo", "entendido", "genial", "excelente", "buenisimo",
    "ya veo", "ya entendi", "esta bien", "muy bien", "de acuerdo", "super", "bacan", "chevere", "ah ok", "ok ok",
})
_YES = frozenset({
    "si", "sii", "claro", "dale", "por favor", "si por favor", "claro que si", "cuentame", "adelante", "quiero",
    "quiero saber mas", "si quiero", "si dale", "si claro", "seguro", "porfa", "si porfa", "cuentame mas", "dime",
    "dime mas", "dame mas", "mas informacion", "mas detalles", "continua", "sigue",
})
_NO = frozenset({
    "no", "nop", "no gracias", "asi esta bien", "nada mas", "eso es todo", "por ahora no", "no por ahora",
    "no por el momento", "eso seria todo", "ninguna", "ninguno", "no nada mas",
})
_REPEAT = frozenset({"repite", "repitelo", "repiteme", "otra vez", "de nuevo", "puedes repetir", "repetir"})
_CONFUSED = frozenset({
    "explicame mejor", "explicalo mejor", "no entendi", "no comprendi", "no entiendo", "como asi",
    "que quieres decir", "no te entendi", "explica mejor", "puedes explicar mejor", "mas claro", "dilo de otra forma",
    "no entendi nada", "no comprendo", "no me quedo claro",
})
_HOWAREYOU = frozenset({"como estas", "que tal", "como te va", "como andas", "como te encuentras", "todo bien"})
_SORRY = frozenset({"perdon", "disculpa", "disculpame", "disculpe", "lo siento", "perdona"})
_LAUGH = re.compile(r"^(?:j+[aeiou]+)+j*$|^(?:ha)+h?$|^(?:xd)+$|^lol$|^jaja\w*$|^jeje\w*$")

_PICKS: dict[str, Pick] = {
    "el otro": "other", "la otra": "other", "el otro curso": "other", "y el otro": "other", "y la otra": "other",
    "el primero": "first", "la primera": "first", "el primer curso": "first", "el de arriba": "first",
    "el segundo": "second", "la segunda": "second", "el segundo curso": "second", "el de abajo": "second",
    "el ultimo": "last", "la ultima": "last", "el ultimo curso": "last",
}
_COMPARATIVES: dict[str, Comparative] = {
    "mas barato": "price", "mas baratos": "price", "mas economico": "price", "mas economicos": "price",
    "algo mas barato": "price", "uno mas barato": "price", "el mas barato": "price", "el mas economico": "price",
    "mas corto": "duration", "mas cortos": "duration", "el mas corto": "duration", "mas rapido": "duration",
    "mas temprano": "schedule", "mas tarde": "schedule", "otro horario": "schedule",
}


def _clean(message: str) -> str:
    """Texto normalizado sin los signos de cortesía que no cambian el sentido («y», «pues», «entonces»)."""
    tokens = [t for t in tokenize(normalize(message)) if t not in {"pues", "entonces", "bueno", "mmm", "eh", "emm"}]
    return " ".join(tokens)


def classify(message: str) -> Kind | None:
    """Tipo de mensaje de pegamento, o None si es una consulta normal."""
    base = normalize(message)
    if not base.strip():
        return "empty"  # solo emojis o signos
    clean = _clean(message)
    if not clean or len(clean.split()) > _MAX_TOKENS:
        return None
    if clean in _REPEAT:
        return "repeat"
    if clean in _CONFUSED:
        return "confused"
    if clean in _ACK:
        return "ack"
    if clean in _YES:
        return "yes"
    if clean in _NO:
        return "no"
    if clean in _HOWAREYOU:
        return "howareyou"
    if clean in _SORRY:
        return "sorry"
    if _LAUGH.match(clean.replace(" ", "")):
        return "laugh"
    return None


def pick_of(message: str) -> Pick | None:
    """«el otro», «el primero», «la segunda», «el último»."""
    return _PICKS.get(_clean(message))


def comparative_of(message: str) -> Comparative | None:
    """«más barato», «más corto», «más temprano»: piden comparar lo que la ficha no publica o sí publica."""
    return _COMPARATIVES.get(_clean(message))


def variant(options: tuple[str, ...], message: str) -> str:
    """Elige una variante de forma determinista según el mensaje."""
    return options[zlib.crc32(normalize(message).encode("utf-8")) % len(options)]


# Respuestas de cortesía: sin cifras, enlaces ni nombres (Laravel las valida como cortesía sin fuentes).
OPEN_QUESTION = "¿Quieres saber el horario, la modalidad o cómo matricularte?"
TOPICS_LINE = "Puedo ayudarte con cursos y capacitaciones, asesoramiento, convenios, pagos y contacto."

ACK_WITH_FOCUS = (
    f"¡Perfecto! {OPEN_QUESTION}",
    "¡Genial! Si quieres, te cuento más sobre el horario, la modalidad o la matrícula. ¿Qué te interesa?",
    f"Entendido. {OPEN_QUESTION}",
)
ACK_NO_FOCUS = (
    f"¡Claro! ¿Qué te gustaría saber? {TOPICS_LINE}",
    f"Con gusto. Cuéntame qué necesitas. {TOPICS_LINE}",
    f"Aquí estoy para ayudarte. {TOPICS_LINE}",
)
NO_REPLY = (
    "Está bien. Si más adelante necesitas algo de Horus Group, aquí estaré.",
    "De acuerdo. Cuando quieras retomar o consultar otra cosa, escríbeme.",
    "Sin problema. Si te surge otra duda, con gusto te ayudo.",
)
CONFUSED_WITH_FOCUS = (
    "Disculpa si no fui claro. ¿Qué parte quieres que te aclare: el contenido, el horario, la modalidad o la matrícula?",
    "Perdona la confusión. Dime qué parte no quedó clara (contenido, horario, modalidad o matrícula) y te la explico mejor.",
)
REPEAT_NO_FOCUS = (
    "Con gusto te lo explico de nuevo. ¿Sobre qué curso o tema quieres que te cuente? "
    + TOPICS_LINE,
    "Disculpa si no fui claro. Dime sobre qué tema necesitas ayuda y lo vemos paso a paso. " + TOPICS_LINE,
)
LAUGH_REPLY = (
    "¡Me alegra! Cuando quieras, te ayudo con cursos, matrícula o cualquier consulta sobre Horus Group.",
    "Con gusto. Si necesitas orientación sobre cursos o matrícula, dime.",
)
HOWAREYOU_REPLY = (
    "¡Muy bien, gracias por preguntar! Soy un asistente automático y estoy listo para ayudarte. " + TOPICS_LINE,
    "Todo en orden por aquí. Soy un asistente virtual; cuéntame qué necesitas. " + TOPICS_LINE,
)
SORRY_REPLY = (
    "No te preocupes, no hay problema. ¿En qué te puedo ayudar?",
    "Tranquilo, está todo bien. Dime en qué te ayudo.",
)
EMPTY_REPLY = (
    "Me llegó un mensaje sin texto. ¿Qué te gustaría saber? " + TOPICS_LINE,
    "No pude leer tu mensaje. Escríbeme tu consulta con palabras y te ayudo. " + TOPICS_LINE,
)
