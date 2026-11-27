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
    "ya veo", "ya entendi", "esta bien", "muy bien", "de acuerdo", "super", "bacan", "chevere", "ok chevere", "ah ok",
    "ok ok",
})
_ACK_WORDS = frozenset({
    "ok", "okay", "okey", "vale", "listo", "perfecto", "entiendo", "entendido", "genial", "excelente", "buenisimo", "super",
    "bacan", "chevere", "ya", "ah", "muy", "bien",
})
_YES = frozenset({
    "si", "sii", "claro", "dale", "por favor", "si por favor", "claro que si", "cuentame", "adelante", "quiero",
    "quiero saber mas", "si quiero", "si dale", "si claro", "seguro", "porfa", "si porfa", "cuentame mas", "dime",
    "dime mas", "dame mas", "mas informacion", "mas detalles", "continua", "sigue",
    # Confirmar la opción de una aclaración («¿Tu consulta es sobre…?»).
    "si eso", "eso", "si es eso", "eso mismo", "exacto", "asi es", "correcto", "si sobre eso", "si de eso",
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
    "como estas", "que tal", "q tal", "como te va", "como andas", "como te encuentras", "todo bien", "bien y tu", "muy bien y tu",
    "bien gracias y tu", "y tu", "y tu como estas", "como has estado", "que tal estas", "como vas",
})
# Piden ayuda sin decir todavía sobre qué: se invita a contar la consulta (sin adivinar un tema).
_HELP = frozenset({
    "me puedes ayudar", "me pueden ayudar", "puedes ayudarme", "pueden ayudarme", "me ayudas", "me ayudan", "ayudame",
    "ayudenme", "ayuda", "ayuda por favor", "necesito ayuda", "necesito tu ayuda", "tengo una duda", "tengo una pregunta",
    "tengo una consulta", "una pregunta", "una consulta", "una duda", "quisiera hacer una consulta", "quiero hacer una consulta",
    "quiero hacer una pregunta", "puedo hacerte una pregunta", "te puedo hacer una pregunta", "consulta", "pregunta",
    "info", "informes", "mas info", "quiero info", "una info", "menu", "ver menu", "opciones", "ver opciones",
    # Pedir «información» sin tema respondía con la ficha del primer curso que la mencionaba.
    "quiero informacion", "quisiera informacion", "necesito informacion", "deseo informacion",
    "dame informacion", "informacion porfa", "informacion por favor", "info porfa", "info por favor",
    "queria hacer una consulta", "queria hacer unas consultas", "quisiera hacer unas consultas", "quiero hacer unas consultas",
    "tengo unas consultas", "tengo unas preguntas", "tengo unas dudas", "unas consultas", "unas preguntas",
})
# «Hola, ¿qué tal? Quería hacer unas consultas»: el saludo delante no cambia que todavía no dijo sobre qué.
_GREETING_PREFIX = re.compile(r"^(?:hola|buenas|buenos dias|buenas tardes|buenas noches|buen dia)(?: (?:que tal|como estas))? ")
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
        return "ack" if base.strip() == "mmm" else None
    if is_rude(message):
        return "rude"
    if _english(clean.split()):
        return "english"
    if _GREETING_PREFIX.sub("", clean) in _HELP and len(clean.split()) <= _MAX_TOKENS + 3:
        return "help"
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
    if clean in _ACK or (len(clean.split()) <= 4 and set(clean.split()) <= _ACK_WORDS):
        return "ack"  # también combinadas: «perfecto, entendido», «ok listo», «ya, genial»
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
    r"gemini|copilot|un modelo|modelo de lenguaje|robot|bot|chatbot|maquina|programa|real|una persona|humano|humana|"
    r"inteligente)\b"
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
    r"|\bdesde hace cuanto\b|\bhace cuanto tiempo\b.{0,25}\b(funcionan|existen|estan|trabajan|operan)\b"
)


_NAME_QUESTION = re.compile(r"\b(como te llamas|cual es tu nombre|tu nombre|como te dicen|quien eres tu|con quien hablo)\b")


def is_identity_question(message: str) -> bool:
    """«¿Eres una IA?», «¿qué modelo usas?», «¿quién te creó?», «¿cómo te llamas?»: preguntas sobre el asistente."""
    normalized = normalize(message)
    return bool(_IDENTITY.search(normalized) or _NAME_QUESTION.search(normalized))


# Muletillas del habla peruana que no cambian la consulta («hola pe», «oe causa qué cursos hay»). «a causa de» se respeta.
_FILLER = re.compile(r"(?<![\w])(?:pe|pes|oe|oye|manito|bro|broder|causita|(?<!a )causa)(?![\w])", re.IGNORECASE)


def strip_filler(message: str) -> str:
    """Quita muletillas sueltas; si no queda nada, devuelve el mensaje tal cual."""
    cleaned = re.sub(r"\s{2,}", " ", _FILLER.sub(" ", message)).strip(" ,")
    return cleaned if content_words(cleaned) else message


def content_words(text: str) -> list[str]:
    return [t for t in tokenize(normalize(text)) if t]


# Reclamos: un problema con un pago, un certificado o una clase. Se deriva al Libro de Reclamaciones y a un asesor.
_COMPLAINT = re.compile(
    r"\b(me cobraron|cobraron de mas|cobraron doble|me cobro de mas|cobro doble|cobro indebido|me estafaron|estafa|"
    r"no me llego|no me ha llegado|nunca me llego|todavia no me llega|aun no me llega|no me entregaron|no me dieron mi|"
    r"(el|la) (profesor|profesora|docente|instructor|instructora) no (vino|llego|asistio|se presento)|"
    r"no hubo clase|cancelaron la clase|mala atencion|pesimo servicio|quiero quejarme|quiero poner una queja|"
    r"quiero hacer un reclamo|quiero reclamar|tengo una queja|tengo un reclamo|"
    r"nadie me (?:ha )?(?:respondido|responde|contesta|atiende)|no me (?:han )?(?:respondido|agregado|contestado)|"
    r"(?:todavia|aun) no me (?:agregan|añaden|anaden|responden|contestan))\b"
)


# Horario de atención de la oficina o del WhatsApp (no el de un curso): «¿en qué horario responden por WhatsApp?»,
# «¿hasta qué hora atienden?». Sin esto, «horario» se tomaba como el horario de los cursos (no publicado).
_OFFICE_HOURS = re.compile(
    r"\b(?:horario|hora|horas)\b.{0,30}\b(?:atienden|atencion|responden|contestan|abren|cierran)\b"
    r"|\b(?:atienden|abren|cierran|responden|contestan)\b.{0,25}\b(?:horario|hora|sabados?|domingos?|feriados?|almuerzo)\b"
)


def asks_office_hours(message: str) -> bool:
    normalized = normalize(message)
    # «¿Dónde están y a qué hora atienden?» sigue la búsqueda, que une ubicación y horario.
    return (_OFFICE_HOURS.search(normalized) is not None
            and re.search(r"\b(?:curso|cursos|clase|clases|taller|capacitacion|donde|direccion|ubicad\w*)\b", normalized) is None)


def is_complaint(message: str) -> bool:
    return bool(_COMPLAINT.search(normalize(message)))


# Pago fraccionado: la información oficial no dice si se acepta; se responde con honestidad.
_INSTALLMENTS = re.compile(r"\b(cuotas?|financiamiento|financiar|fraccionad[oa]|en partes|pagar por partes|a plazos)\b")


def asks_installments(message: str) -> bool:
    return bool(_INSTALLMENTS.search(normalize(message)))


# Ofertas que Horus Group no publica (idiomas, posgrados, títulos universitarios): «No encontré un curso sobre ese tema».
_NOT_OFFERED = re.compile(
    r"\b(ingles|idiomas?|frances|portugues|quechua|maestrias?|doctorados?|posgrados?|postgrados?|"
    r"titulos? universitarios?|carreras? universitarias?|licenciaturas?|bachillerato)\b"
)


def not_offered_topic(message: str) -> bool:
    """«¿Tienen inglés?», «maestría?»; no «asesoría para mi tesis de maestría» (eso es asesoramiento)."""
    normalized = normalize(message)
    if re.search(r"(tesis|asesor|investigacion|convenio|tradu)", normalized):
        return False
    return bool(_NOT_OFFERED.search(normalized))


# La consulta nombra un curso concreto: «el curso de cocina», «el taller de oratoria».
_NAMED_COURSE = re.compile(
    r"\b(?:curso|taller|diplomado|seminario|capacitacion|programa)s? (?:de|del|sobre) (?:(?:la|el|las|los) )?([a-z0-9]+)"
)
# Palabras que siguen a esas frases sin nombrar un tema.
_NOT_A_SUBJECT = frozenset(
    "curso cursos taller talleres capacitacion capacitaciones programa clases este ese esto eso aquel mismo actual "
    "algun alguno ninguno todos otro otros nuevo ustedes horus group linea persona grupo forma manera modalidad virtual "
    "presencial semipresencial hibrido distancia online verano invierno fin fines semana noche manana tarde sabado "
    "sabados domingo domingos hoy ahora mes ano que ahi alli".split()
)


def named_course_subjects(message: str) -> list[str]:
    "Primera palabra de cada tema que la consulta nombra como curso («curso de X», «taller de X»)."
    return [word for word in _NAMED_COURSE.findall(normalize(message)) if word not in _NOT_A_SUBJECT]


INSTALLMENTS_REPLY = (
    "No tengo confirmado si el pago puede hacerse en cuotas. Un asesor te lo confirma por WhatsApp junto con los medios "
    "de pago disponibles."
)

# «inscripción» o «matrícula» solas: se entiende como «¿cuáles son los pasos para matricularme?».
ENROLLMENT_WORDS = frozenset({"inscripcion", "inscripciones", "matricula", "matriculas", "inscribirse", "matricularse"})
ENROLLMENT_QUERY = "¿Cuáles son los pasos para matricularme?"

# «¿Cómo puedo matricularme?» (botón del widget) sin curso nombrado: es una pregunta por el procedimiento, que tiene los
# pasos verificados. «Quiero matricularme» o «cómo me inscribo en Python» siguen yendo al WhatsApp de matrícula.
_HOW_TO_ENROLL = re.compile(
    r"^(?:y )?como (?:puedo |me |hago para |hago |se |debo |podria )?"
    r"(?:matricular(?:me|se)?|matriculo|inscribir(?:me|se)?|inscribo)(?: (?:en|a) (?:un|el|algun) curso)?$"
)


# «¿Cuáles son los pasos para inscribirme en el curso X?», «¿cómo sería el proceso para matricularme?»: el procedimiento.
_ENROLL_STEPS = re.compile(
    r"\b(?:pasos|proceso|procedimiento|tramite)\b.{0,80}\b(?:matricul\w*|inscri\w*)\b"
    r"|\b(?:matricul\w*|inscri\w*)\b.{0,80}\b(?:pasos|proceso|procedimiento)\b"
)


def asks_how_to_enroll(message: str) -> bool:
    normalized = normalize(message)
    return _HOW_TO_ENROLL.match(normalized) is not None or _ENROLL_STEPS.search(normalized) is not None

# Comparar cursos: «compáralos», «¿cuál es más corto?», «¿cuál es mejor, redes u ofimática?».
_COMPARE = re.compile(
    r"\b(comparalos|comparalas|compararlos|comparame|comparar|compara|comparacion|diferencias?|en que se diferencian|"
    r"cual es (?:el |la )?mas (?:corto|corta|largo|larga)|el mas (?:corto|largo)|mas cort[oa]s?|mas larg[oa]s?|"
    r"cual (?:es|seria) (?:el )?mejor|cual conviene mas|cual me conviene mas)\b"
)


def comparison_kind(message: str) -> str | None:
    """'duration' para «más corto/largo», 'general' para comparar o «mejor», None si no compara."""
    normalized = normalize(message)
    if not _COMPARE.search(normalized):
        return None
    return "duration" if re.search(r"\b(cort[oa]s?|larg[oa]s?)\b", normalized) else "general"


def asks_longest(message: str) -> bool:
    """«¿cuál es más largo?»: ordenar de mayor a menor duración (por defecto, de menor a mayor)."""
    return re.search(r"\blarg[oa]s?\b", normalize(message)) is not None


_DURATION = re.compile(r"(\d+(?:[.,]\d+)?)\s*(dias?|semanas?|mes(?:es)?|anos?|años?)\b")
_DAYS_PER_UNIT = {"dia": 1, "semana": 7, "mes": 30, "ano": 365, "año": 365}


def duration_days(text: str | None) -> float | None:
    """Duración publicada en días aproximados («2 semanas» → 14) para ordenar; None si no se puede comparar.

    Las horas no se convierten: «40 horas» no dice cuántas semanas dura el curso, así que no se ordena con ellas.
    """
    if not text:
        return None
    match = _DURATION.search(normalize(text))
    if match is None:
        return None
    unit = match.group(2)
    base = "mes" if unit.startswith("mes") else unit.rstrip("s")
    return float(match.group(1).replace(",", ".")) * _DAYS_PER_UNIT[base]


_OPEN_RECOMMEND = re.compile(
    r"^(?:y )?(?:cual|que curso|que)(?: me)? (?:recomiendas|recomiendan|sugieres|aconsejas|conviene)$"
    r"|^(?:cual|que) (?:elijo|escojo|tomo)$|^por cual empiezo$|^cual es mejor para mi$|^que me recomiendas$"
    r"|^(?:no se|no estoy segur[oa] de) (?:cual|que curso) (?:elegir|escoger|tomar|llevar)$"
)


def is_open_recommendation(message: str) -> bool:
    """«¿Cuál me recomiendas?» sin tema: se ayuda a elegir entre los cursos publicados."""
    return bool(_OPEN_RECOMMEND.match(" ".join(content_words(message))))


# Lo mismo dentro de una oración larga: «Soy secretaria y quiero mejorar en mi trabajo, ¿qué curso me recomiendan?»,
# «No sé cuál curso elegir, ¿me ayudas a decidir…?». Quien llama comprueba que no nombre un curso ni un tema.
_OPEN_RECOMMEND_IN_SENTENCE = re.compile(
    r"\b(?:que|cual|cuales) (?:curso|cursos) (?:me )?(?:recomiendas|recomiendan|recomienda|sugieres|sugieren|conviene)\b"
    r"|\bno se (?:cual|que) curso\b|\b(?:ayudas|ayudan|ayudar|ayuden) a (?:decidir|elegir|escoger)\b"
    r"|\bcual me (?:recomiendas|recomiendan|conviene)\b"
)


def is_open_recommendation_in_sentence(message: str) -> bool:
    return _OPEN_RECOMMEND_IN_SENTENCE.search(normalize(message)) is not None


_MODALITY_NEGATION = re.compile(r"\bno (?:quiero|me interesa|busco|deseo|me sirve)\b.{0,12}\b(virtual|online|presencial|semipresencial)\b")


def rejected_modality(message: str) -> str | None:
    match = _MODALITY_NEGATION.search(normalize(message))
    if match is None:
        return None
    return "virtual" if match.group(1) == "online" else match.group(1)


def is_unpublished_fact(message: str) -> bool:
    """Fundación, historia, significado del nombre, docentes: datos que la información oficial no incluye."""
    normalized = normalize(message)
    if re.search(r"\b(quienes son|quien es|como se llaman?|quienes dictan|quien dicta|que)\b.{0,15}"
                 r"\b(docentes?|profesor(?:es|a|as)?|instructor(?:es|a|as)?|capacitador(?:es)?|ponentes?)\b", normalized):
        return True
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


_PREFERENCE = r"(?:quiero|me interesa|me gusta|elijo|escojo|prefiero|me quedo con)"
_THIS_ONE = re.compile(
    rf"^(?:(?:si|ya|ok) )?(?:{_PREFERENCE} (?:ese|esa|este|esta)(?: curso)?|(?:ese|esa|este|esta)(?: curso)? (?:quiero|me interesa|me gusta)"
    r"|ese|esa|ese curso|ese mismo)$"
)
_NUMBERED = re.compile(
    rf"^(?:{_PREFERENCE} )?(?:(?:la |el )?(?:opcion|numero|curso)(?: numero)? |(?:el|la) )?([1-9])$"
)
_SWITCH = re.compile(
    r"^(?:no )?(?:mejor|quiero mejor|prefiero|cambio a) (?:(?:el|la) )?(?:(?:curso|capacitacion) )?(?:(?:de|del) )?(.+)$"
)


def points_at_this(message: str) -> bool:
    """«quiero ese», «me interesa ese», «ese curso»: señala algo ya mencionado sin nombrarlo."""
    return _THIS_ONE.match(" ".join(content_words(message))) is not None


def numbered_choice(message: str) -> int | None:
    """«1», «el 2», «la opción 3»: elige por posición en una lista."""
    match = _NUMBERED.match(" ".join(content_words(message)))
    return int(match.group(1)) if match else None


def switch_target(message: str) -> str | None:
    """«mejor redes», «prefiero el de comunicación»: lo que viene después del cambio de opinión."""
    match = _SWITCH.match(" ".join(content_words(message)))
    return match.group(1) if match else None


_RECORDINGS = re.compile(
    r"\b(se graban?|quedan? grabad[ao]s?|dejan? grabad[ao]s?|grabaciones|en vivo o grabad[ao]s?|(?:son|es) grabad[ao]s?)\b"
)


def asks_recordings(message: str) -> bool:
    """«¿Se graban las clases?» respondía con la dirección de la sede. «Examen del curso grabado» es otra pregunta."""
    normalized = normalize(message)
    return bool(_RECORDINGS.search(normalized)) and not re.search(r"\b(examen(?:es)?|evaluacion(?:es)?)\b", normalized)


# Solo estas palabras: «quiero aprender Excel nivel básico» sigue siendo una búsqueda de curso.
_LEVEL_WORDS = frozenset(
    "nivel basico intermedio avanzado es son de que tiene tienen hay cual el los la curso cursos un del y".split()
)


def asks_level(message: str) -> bool:
    """«¿Nivel básico?»: ninguna ficha publica el nivel; antes devolvía el temario que decía «Excel básico»."""
    words = set(content_words(message))
    return "nivel" in words and words <= _LEVEL_WORDS


# «¿Con quiénes?» después de hablar de convenios pregunta por las instituciones, no por la empresa.
_WITH_WHOM = re.compile(r"^(?:y )?con quien(?:es)?(?: son| trabajan| tienen(?: convenios?)?)?$")


def asks_with_whom(message: str) -> bool:
    return _WITH_WHOM.match(" ".join(content_words(message))) is not None


# Cómo se dictan las clases: plataforma de lo virtual y días de lo presencial.
_CLASS_PLATFORM = re.compile(
    r"\b(?:zoom|google meet|meet)\b"
    r"|\bclases\b.{0,25}\ben vivo\b"
    # Preguntar cómo es una modalidad mixta: la respuesta publicada dice cuáles se ofrecen y que esa no.
    r"|\bmodalidad (?:mixta|hibrida|semipresencial)\b|\bcomo (?:funciona|es) (?:lo )?(?:semipresencial|mixto|hibrido)\b"
    r"|\b(?:que|cual) (?:es )?(?:la )?plataforma\b(?!.*\bpag)"
    r"|\bplataforma (?:usan|utilizan|de (?:las )?clases)\b"
    r"|^(?:y )?(?:como|por donde|por que medio) (?:son|se dictan|se llevan|serian) (?:las )?clases(?: virtuales| online)?$"
    r"|\b(?:por|con) (?:que|cual) (?:aplicacion|programa|app|plataforma)\b"
    r"|\bclases como son\b|\btengo que ir\b.{0,30}\bdesde (?:mi )?casa\b"
)


def asks_class_platform(message: str) -> bool:
    return _CLASS_PLATFORM.search(" ".join(content_words(message))) is not None


_PRACTICE_DAYS = re.compile(
    r"\b(?:que dias|cuando)\b.{0,20}\b(?:clases )?(?:presencial(?:es)?|practicas?|teoricas?|teoria)\b"
)


def asks_class_days(message: str) -> bool:
    """«¿Qué días son las clases presenciales?»: sin curso elegido no hay un horario concreto que dar."""
    normalized = normalize(message)
    return bool(_PRACTICE_DAYS.search(normalized)) and not re.search(r"\b(falt\w*|perdi\w*|recuper\w*|examen\w*)\b", normalized)


# Qué pasa una vez matriculado: grupo de WhatsApp del curso y acceso a las clases. «No me agregaron» es un reclamo.
_AFTER_ENROLLING = re.compile(
    r"\b(?:me )?(?:agregan|a[nñ]aden|unen|incluyen|meten)\b.{0,20}\bgrupo\b"
    r"|\bgrupos? de (?:whatsapp|wsp|wasap)\b"
    r"|\bque (?:pasa|sigue|hago) (?:despues|luego) de (?:matricularme|inscribirme|pagar)\b"
    r"|\bcomo (?:ingreso|entro|accedo|me conecto) (?:a )?(?:las |mis )?clases\b"
    r"|\bpara que me (?:agreguen|anadan|unan|incluyan)\b"
    r"|\b(?:ya )?(?:hice|realice|pague|deposite)\b.{0,40}\bque (?:tengo que|debo) hacer\b"
)


def asks_after_enrolling(message: str) -> bool:
    normalized = normalize(message)
    return bool(_AFTER_ENROLLING.search(normalized)) and not re.search(r"\bno me\b", normalized)


# Datos de estudio que Horus Group no ha publicado: edad, turnos y si hace falta equipo propio.
_UNPUBLISHED_STUDY = re.compile(
    r"\bedad (?:minima|maxima|requerida|limite)\b|\blimite de edad\b|\bque edad (?:se )?(?:necesita|piden|debo|hay que)\b"
    r"|\b(?:desde|a partir de|hasta) (?:que|cuantos) (?:edad|a[nñ]os)\b"
    r"|\b(?:aceptan|admiten|reciben)\b.{0,15}\b(?:ni[nñ][oa]s|menores|adultos mayores)\b"
    r"|\b(?:cursos?|clases|talleres?) para (?:ni[nñ][oa]s|adultos mayores)\b"
    r"|\b(?:necesito|necesita|se necesita|requiere|requieren|se requiere|debo|hay que|es necesari[oa]|tengo que|piden)\b"
    r"(?: (?:tener|llevar|contar|con|una|un|la|mi|propia|propio))* (?:laptop|computadora|pc)\b"
    r"|\bturnos?\b(?!.*\batencion\b)"
)


def asks_unpublished_study_fact(message: str) -> bool:
    return _UNPUBLISHED_STUDY.search(" ".join(content_words(message))) is not None


# Faltar a una clase: qué hacer para recuperar la práctica.
_MISSED_CLASS = re.compile(
    r"\b(?:falte|falto|faltar|faltando|perdi|pierdo|no (?:pude|puedo|podre|voy a poder) (?:ir|asistir|llegar))\b"
    r".{0,40}\b(?:clases?|sesion(?:es)?|practicas?)\b"
)


def asks_missed_class(message: str) -> bool:
    return _MISSED_CLASS.search(normalize(message)) is not None


# Validez o respaldo de los certificados («¿es válido?», «¿sirve para postular?»). «Perdí mi certificado» es otra cosa.
_CERT_VALIDITY = re.compile(
    r"\bcertificad\w*\b.{0,60}\b(?:valid\w*|validez|sirve\w*|respald\w*|reconocid\w*|oficial\w*|postular)\b"
    r"|\b(?:valid\w*|validez|respald\w*)\b.{0,40}\bcertificad\w*\b"
)


def asks_certificate_validity(message: str) -> bool:
    normalized = normalize(message)
    # La validez en otro país no está publicada: esa pregunta sigue abstenida («¿es válido en Europa?»).
    if re.search(r"\b(?:valid\w*|sirve\w*) en (?!el estado|el peru|peru|cajamarca|entidad\w*|instituci\w*|empresas?|el trabajo|mi trabajo)\w+",
                 normalized):
        return False
    return bool(_CERT_VALIDITY.search(normalized)) and not re.search(
        r"\b(?:perdi|perdido|malogr\w*|duplicado|internacional\w*|extranjero|otro pais|otros paises|fuera del pais)\b", normalized)


def asks_public_job_validity(message: str) -> bool:
    return re.search(r"\b(?:postular|postulaciones|estado|entidad(?:es)? public\w*|trabajo)\b", normalize(message)) is not None


# Servicios que Horus Group ya no ofrece por este canal: instalar, reparar o cotizar equipos (no aprenderlo en un curso).
_DISCARDED_SERVICE = re.compile(
    r"\b(?:camaras?|videovigilancia|cableado|cables|soporte tecnico|computadoras?|laptops?|maquinas?|equipos|pc)\b"
)
_SERVICE_ACTION = re.compile(r"\b(?:instal\w*|repar\w*|arregl\w*|hacen|hagan|cobrar\w*|cotiz\w*|mantenimiento|lenta|falla\w*|malogr\w*)\b")
_LEARNING = re.compile(r"\b(?:aprender|aprendo|estudiar|curso|cursos|capacitacion\w*|clases|taller\w*|ensenan|interesa\w*)\b")


def asks_discarded_service(message: str) -> bool:
    normalized = normalize(message)
    return (bool(_DISCARDED_SERVICE.search(normalized)) and bool(_SERVICE_ACTION.search(normalized))
            and not _LEARNING.search(normalized))


# Qué se hace con la información que envía el visitante: la política de privacidad publicada.
_PRIVACY = re.compile(
    r"\bque (?:van a hacer|hacen|haran|hara) con (?:mi|mis|la|las|los|esa|esos) (?:informacion|datos)\b"
    r"|\b(?:protegen|comparten|venden|usan|cuidan) (?:mi|mis|la|las|los) (?:informacion|datos)\b"
)


def asks_privacy(message: str) -> bool:
    return _PRIVACY.search(normalize(message)) is not None


# «¿Con quién hablo para coordinar?» pide un asesor; «¿con quién estoy hablando?, ¿eres un programa?» pregunta por el
# asistente y sigue siendo una pregunta de identidad.
_WHOM_TO_CONTACT = re.compile(r"\bcon quien (?:hablo (?:para|sobre|si|acerca)|me comunico|coordino|puedo coordinar)\b")
_ABOUT_ASSISTANT = re.compile(r"\b(?:eres|estoy hablando|persona real|programa|robot|bot|humano)\b")


def asks_whom_to_contact(message: str) -> bool:
    """También «Soy de una municipalidad y queremos firmar un convenio, ¿con quién hablo?»: tras una consulta de varias
    palabras, «con quién hablo» pide un contacto; solo, sigue preguntando por el asistente."""
    normalized = normalize(message)
    asked = bool(_WHOM_TO_CONTACT.search(normalized) or re.search(r"\w+(?: \w+){3,} con quien hablo\b", normalized))
    return asked and not _ABOUT_ASSISTANT.search(normalized)


# Dónde está la sede, dicho de muchas formas («¿cuál es la dirección?», «¿por qué calle están?», «¿en qué parte de
# Cajamarca están ubicados?»). «En persona» o «oficina» no deben llevar a un curso presencial (prueba del 26/11/2026).
# No cuenta la dirección de correo ni «desde dónde» (estudiar desde otra ciudad) ni dónde enviar o presentar algo.
_LOCATION = re.compile(
    r"\b(?:cual es (?:la|su) direccion|(?:su|la|me pasas? la|me das? la|me dan la) direccion(?! de correo| electronica)"
    r"|direccion (?:de (?:la|su) (?:oficina|sede|local)|exacta)"
    # «¿Dónde queda la plaza de armas?» no pregunta por la sede: tras un artículo, solo oficina, sede o local.
    r"|(?<!desde )donde (?:queda|quedan)(?! (?:la|el|los|las|un|una) (?!oficina|sede|local))"
    r"|(?<!desde )donde (?:estan ubicados|se ubican|esta (?:su|la) (?:oficina|sede|local))"
    r"|(?:por|en) que calle|en que parte (?:de [a-z]+ )?(?:estan|quedan|se ubican)|como llego (?:a|hasta) (?:su|la) (?:sede|oficina|local)"
    r"|tienen (?:oficina|local|sede) fisic[ao])\b"
)


_OTHER_CONTACT_DATA = re.compile(r"\b(?:telefono|whatsapp|correo|email|numero|celular|horario|hora|pago|pagar)\b")


def asks_syllabus(message: str) -> bool:
    """«¿qué incluye?», «el temario»: el contenido publicado del curso, no su ficha resumida."""
    return re.search(r"\b(?:temario|silabo|contenidos?|que incluye|que temas|en que consiste)\b", normalize(message)) is not None


def asks_location(message: str) -> bool:
    """Solo la ubicación: «dirección y teléfono» o «¿dónde están y a qué hora atienden?» siguen el flujo de varias
    preguntas, que une las dos fichas."""
    normalized = normalize(message)
    return _LOCATION.search(normalized) is not None and _OTHER_CONTACT_DATA.search(normalized) is None


LEVEL_REPLY = (
    "No tengo confirmado el nivel de cada curso. Pregúntame por un curso para ver lo que está publicado, "
    "o consúltalo con un asesor."
)


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
