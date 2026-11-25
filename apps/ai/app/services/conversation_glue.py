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
import unicodedata
import zlib
from typing import Literal

from app.core.text import normalize, tokenize

Kind = Literal[
    "ack", "yes", "no", "repeat", "confused", "laugh", "howareyou", "sorry", "empty", "help", "rude", "time", "english",
]
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
_HOWAREYOU = frozenset({
    "como estas", "que tal", "como te va", "como andas", "como te encuentras", "todo bien", "bien y tu", "muy bien y tu",
    "bien gracias y tu", "y tu", "y tu como estas", "como has estado", "que tal estas", "como vas",
})
# Piden ayuda sin decir todavía sobre qué: se invita a contar la consulta (sin adivinar un tema).
_HELP = frozenset({
    "me puedes ayudar", "me pueden ayudar", "puedes ayudarme", "pueden ayudarme", "me ayudas", "me ayudan", "ayudame",
    "ayudenme", "ayuda", "ayuda por favor", "necesito ayuda", "necesito tu ayuda", "tengo una duda", "tengo una pregunta",
    "tengo una consulta", "una pregunta", "una consulta", "una duda", "quisiera hacer una consulta", "quiero hacer una consulta",
    "quiero hacer una pregunta", "puedo hacerte una pregunta", "te puedo hacer una pregunta", "consulta", "pregunta",
})
_TIME = frozenset({
    "que hora es", "que dia es", "que dia es hoy", "que fecha es", "que fecha es hoy", "en que fecha estamos",
    "que dia estamos", "sabes que hora es", "me dices la hora", "dime la hora",
})
# Groserías e insultos frecuentes (léxico cerrado; se responde con calma y se reconduce, sin repetir la palabra).
_RUDE_WORDS = frozenset({
    "idiota", "idiotas", "imbecil", "imbeciles", "estupido", "estupida", "tonto", "tonta", "inutil", "inutiles", "basura",
    "mierda", "carajo", "huevon", "cojudo", "cojuda", "pendejo", "pendeja", "maldito", "maldita", "porqueria", "csm", "ctm",
    "conchatumadre", "puta", "puto", "webon", "burro", "burra", "baboso", "babosa", "mediocre", "pesimo", "pesima",
    "malisimo", "malisima", "apestas", "chafa",
})
_RUDE_PHRASES = ("bot tan malo", "no sirves", "no sirves para nada", "eres malo", "eres mala", "que malo eres", "no entiendes nada")
# Inglés: palabras que no son español. Basta un saludo suelto o una frase mayoritariamente inglesa.
_ENGLISH_WORDS = frozenset({
    "hello", "hi", "hey", "what", "how", "do", "does", "you", "your", "the", "is", "are", "can", "i", "my", "offer",
    "courses", "course", "much", "it", "cost", "speak", "english", "please", "thanks", "thank", "where", "when", "which",
    "have", "any", "about", "want", "need", "help", "price", "good", "morning", "afternoon", "evening", "classes", "who",
    "information", "info", "location", "located", "schedule", "enroll", "certificate", "there", "this", "that", "tell", "me",
})
_ENGLISH_ONLY = frozenset({"hello", "hi", "thanks", "thank", "please", "english", "help", "what", "how", "where", "you", "your",
                           "does", "the", "offer", "speak", "much", "good", "morning", "afternoon", "evening", "who", "which"})
_SPANISH_HINTS = frozenset({"que", "como", "donde", "cuando", "cuanto", "curso", "cursos", "el", "la", "los", "las", "de", "y",
                            "quiero", "tienen", "hola", "por", "para", "un", "una", "es", "son"})
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


def _english(tokens: list[str]) -> bool:
    if not tokens or len(tokens) > 12 or set(tokens) & _SPANISH_HINTS:
        return False
    if len(tokens) == 1:
        return tokens[0] in {"hello", "hi", "thanks", "english"}
    english = sum(1 for t in tokens if t in _ENGLISH_WORDS)
    return english / len(tokens) >= 0.6 and bool(set(tokens) & _ENGLISH_ONLY)


def is_rude(message: str) -> bool:
    """Insulto o grosería en un mensaje breve (hasta 10 palabras): se responde con calma y se reconduce."""
    clean = _clean(message)
    tokens = clean.split()
    if not tokens or len(tokens) > 10:
        return False
    return bool(set(tokens) & _RUDE_WORDS) or any(f" {p} " in f" {clean} " for p in _RUDE_PHRASES)


def classify(message: str) -> Kind | None:
    """Tipo de mensaje de pegamento, o None si es una consulta normal."""
    base = normalize(message)
    if not base.strip():
        return "empty"  # solo emojis o signos
    clean = _clean(message)
    if not clean:
        return None
    if is_rude(message):
        return "rude"
    if _english(clean.split()):
        return "english"
    if len(clean.split()) > _MAX_TOKENS:
        return None
    if clean in _HELP:
        return "help"
    if clean in _TIME:
        return "time"
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


_IDENTITY = re.compile(
    r"\b(eres|seras|es usted|usas|utilizas|funcionas con)\b.{0,25}\b(ia|inteligencia artificial|chatgpt|chat gpt|gpt|openai|"
    r"gemini|copilot|un modelo|modelo de lenguaje|robot|bot|chatbot|maquina|programa|real|una persona|humano|humana)\b"
    r"|\b(que|cual)\b.{0,12}\b(modelo|tecnologia|ia|inteligencia artificial)\b.{0,15}\b(usas|utilizas|eres|tienes|te impulsa)\b"
    r"|\bquien(es)?\b.{0,6}\b(te|lo)\b.{0,4}\b(creo|crearon|hizo|hicieron|programo|programaron|desarrollo|desarrollaron|diseno|disenaron|construyo)\b"
    r"|\b(tu|su)\s+(creador|creadores|programador|desarrollador)\b|\bcomo (estas hecho|te hicieron|fuiste creado|fuiste hecho)\b"
)
# Datos institucionales que la web no publica: se dice con honestidad en vez de una respuesta genérica.
_UNPUBLISHED_FACT = re.compile(
    r"\b(en que a[nñ]o|cuando|fecha de)\b.{0,15}\bfund(o|aron|ada|ado|acion)\b|\bfundacion de (horus|la empresa)\b"
    r"|\bdesde (cuando|que a[nñ]o)\b.{0,20}\b(existe|existen|funciona|funcionan|trabajan|operan|esta|estan)\b"
    r"|\bcuantos a[nñ]os\b.{0,15}\b(tiene|tienen|lleva|llevan)\b.{0,20}\b(empresa|horus|funcionando|operando|existiendo|en el mercado)\b"
    r"|\bhistoria de (horus|la empresa)\b"
)


def is_identity_question(message: str) -> bool:
    """«¿Eres una IA?», «¿qué modelo usas?», «¿quién te creó?»: preguntas sobre el propio asistente."""
    return bool(_IDENTITY.search(normalize(message)))


def is_unpublished_fact(message: str) -> bool:
    """Fundación, historia, significado del nombre: datos que la información oficial no incluye."""
    normalized = normalize(message)
    if re.search(r"\b(que significa|significado de|significado del nombre|origen del nombre|por que se llaman?)\b", normalized):
        return bool(re.search(r"\b(horus|nombre|empresa)\b", normalized))
    return bool(_UNPUBLISHED_FACT.search(normalized))


_MATH = re.compile(r"^\s*(cuanto es|cuanto da|cuanto son|calcula|resuelve)?\s*\d+(\s*[-+*/x×÷]\s*\d+)+\s*$")


_CREATIVE = re.compile(
    r"\b(escribeme|escribe|hazme|haz|redacta|redactame|componme|compon|inventa|inventame|cantame|recitame|dime)\b.{0,15}"
    r"\b(poema|poesia|cancion|cuento|historia|rap|verso|versos|chiste|chistes|adivinanza|trabalenguas|ensayo|carta de amor)\b"
)


def is_creative_request(message: str) -> bool:
    """«Escríbeme un poema», «cántame una canción»: pedidos creativos fuera de lo que atiende el asistente."""
    return bool(_CREATIVE.search(normalize(message)))


def is_math(message: str) -> bool:
    """«¿Cuánto es 2+2?»: operación aritmética, fuera de lo que atiende el asistente.

    Se mira el texto sin normalizar: la normalización general descarta los signos «+», «*» o «/».
    """
    plain = "".join(c for c in unicodedata.normalize("NFKD", message.lower()) if not unicodedata.combining(c))
    return bool(_MATH.match(re.sub(r"[¿?¡!=.]", " ", plain)))


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
HELP_REPLY = (
    f"¡Claro que sí! Cuéntame tu consulta. {TOPICS_LINE}",
    f"Por supuesto, dime en qué te ayudo. {TOPICS_LINE}",
    f"Con gusto. ¿Cuál es tu consulta? {TOPICS_LINE}",
)
RUDE_REPLY = (
    "Lamento si algo no salió como esperabas. Estoy aquí para ayudarte con cursos, matrícula y atención de Horus Group; "
    "si prefieres, un asesor puede atenderte por WhatsApp.",
    "Entiendo tu molestia y quiero ayudarte. Dime qué necesitas sobre cursos, asesoramiento o matrícula, "
    "o pide hablar con un asesor y te indico cómo contactarlo.",
)
TIME_REPLY = (
    "No tengo acceso a la hora ni a la fecha actual. Si quieres saber cuándo atiende Horus Group, pregúntame por el horario de atención.",
    "No puedo consultar la hora ni el calendario de hoy. Sí puedo ayudarte con cursos, horarios de atención y contacto de Horus Group.",
)
ENGLISH_REPLY = (
    "Hi! For now I can only answer in Spanish. Puedo ayudarte con cursos y capacitaciones, asesoramiento, convenios, pagos y "
    "contacto de Horus Group. ¿En qué te ayudo?",
)
OFF_TOPIC_REPLY = (
    "No tengo información confirmada sobre eso. Puedo ayudarte con {topics}. ¿Sobre cuál de estos temas necesitas información?",
    "Esa consulta está fuera de lo que puedo responder con información verificada. Te puedo orientar sobre {topics}. ¿Qué te interesa?",
    "Prefiero no responder algo que no figure en la información oficial de Horus Group. Sí puedo ayudarte con {topics}. "
    "¿Por dónde empezamos?",
)
IDENTITY_REPLY = (
    "Soy Horus, el asistente virtual de Horus Group. Soy un programa automático, no una persona: busco tu pregunta en la "
    "información oficial de Horus Group (cursos, capacitaciones, asesoramiento, convenios, pagos y contacto) y te respondo "
    "solo con lo que está verificado. No uso un modelo que invente respuestas: si no tengo un dato, te lo digo y te "
    "derivo a un asesor."
)
UNPUBLISHED_FACT_REPLY = (
    "No tengo ese dato en la información oficial de Horus Group, así que prefiero no darte una respuesta inexacta. "
    "Un asesor puede confirmártelo por WhatsApp o en los canales de contacto."
)
EMPTY_REPLY = (
    "Me llegó un mensaje sin texto. ¿Qué te gustaría saber? " + TOPICS_LINE,
    "No pude leer tu mensaje. Escríbeme tu consulta con palabras y te ayudo. " + TOPICS_LINE,
)
