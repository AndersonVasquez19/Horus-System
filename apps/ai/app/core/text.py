"""Normalización de texto en español: minúsculas, sin tildes, tokens y raíces ligeras.

Es deliberadamente simple y determinista: sirve al nivel de reglas y a la búsqueda léxica.
"""

from __future__ import annotations

import re
import unicodedata
from difflib import SequenceMatcher, get_close_matches
from functools import lru_cache

_ZERO_WIDTH = re.compile(r"[\u200b-\u200f\u202a-\u202e\ufeff\u00ad]+")
_NON_WORD = re.compile(r"[^a-z0-9ñ\s]+")
_SPACES = re.compile(r"\s+")

# Abreviaturas frecuentes en consultas reales en Perú (expansión determinista antes de tokenizar).
_SLANG_PATTERNS: tuple[tuple[re.Pattern[str], str], ...] = (
    (re.compile(r"\bq\b(?!\s+tal\b)"), "que"),
    *tuple(
        (re.compile(rf"\b{re.escape(k)}\b"), v)
        for k, v in (
            ("wsp", "whatsapp"),
            ("wasap", "whatsapp"),
            ("guasap", "whatsapp"),
            ("xfa", "por favor"),
            ("porfis", "por favor"),
            ("dcto", "descuento"),
            ("dscto", "descuento"),
            ("certi", "certificado"),
            ("ubi", "ubicacion"),
            ("xq", "porque"),
            ("pq", "porque"),
            ("tmb", "tambien"),
            ("tb", "tambien"),
            ("infos", "informacion"),
            ("ke", "que"),
            ("a k ora", "a que hora"),
            ("k ora", "que hora"),
            ("pa", "para"),
            ("aorita", "ahorita"),
            ("ruk", "ruc"),
            ("exel", "excel"),
            ("y wor", "y word"),
            ("feisbuk", "facebook"),
            ("istangram", "instagram"),
            ("tictok", "tiktok"),
            ("kukis", "cookies"),
            ("arko", "arco"),
            ("in jaus", "in house"),
            ("latop", "laptop"),
            ("latops", "laptops"),
            ("debolben", "devuelven"),
            ("aseroia", "asesoria"),
            ("lejal", "legal"),
            ("jaleria", "galeria"),
            ("ahendar", "agendar"),
            ("konsula", "consulta"),
            ("kiero ber", "quiero ver"),
            ("no boy", "no voy"),
            ("kiero", "quiero"),
            ("kual", "cual"),
            ("kuando", "cuando"),
            ("kuanto", "cuanto"),
            ("komo", "como"),
            ("kien", "quien"),
            ("kienes", "quienes"),
            ("keda", "queda"),
            ("kon", "con"),
            ("kurso", "curso"),
            ("kursos", "cursos"),
            ("kuotas", "cuotas"),
            ("kobraron", "cobraron"),
            ("kontesta", "contesta"),
            ("klase", "clase"),
            ("korto", "corto"),
            ("kupos", "cupos"),
            ("kuesta", "cuesta"),
            ("kableado", "cableado"),
            ("konbenio", "convenio"),
            ("korreo", "correo"),
            ("kajamarka", "cajamarca"),
            ("kapasitacion", "capacitacion"),
            ("kapasitaciones", "capacitaciones"),
            ("sertifikado", "certificado"),
            ("sertificado", "certificado"),
            ("birtual", "virtual"),
            ("birtuales", "virtuales"),
            ("presensiales", "presenciales"),
            ("bense", "vence"),
            ("avogado", "abogado"),
            ("sirbe", "sirve"),
            ("avren", "abren"),
            ("sierran", "cierran"),
            ("ofisina", "oficina"),
            ("rekomiendes", "recomiendes"),
            ("nesesito", "necesito"),
            ("balides", "validez"),
            ("internasional", "internacional"),
            ("ablar", "hablar"),
            ("umano", "humano"),
            ("asessor", "asesor"),
            ("atyenden", "atienden"),
            ("travajan", "trabajan"),
            ("enpresa", "empresa"),
            ("micion", "mision"),
            ("bision", "vision"),
            ("orus", "horus"),
            ("jerente", "gerente"),
            ("jeneral", "general"),
            ("empiesa", "empieza"),
            ("redez", "redes"),
            ("rekomiendes", "recomiendes"),
            ("busko", "busco"),
            ("sero", "cero"),
            ("nesesito", "necesito"),
            ("ofimatika", "ofimatica"),
            ("facktura", "factura"),
            ("elektronika", "electronica"),
            ("elektroniko", "electronico"),
            ("tienpo", "tiempo"),
            ("sako", "saco"),
            ("duplikado", "duplicado"),
            ("fisiko", "fisico"),
            ("dijital", "digital"),
            ("provinsias", "provincias"),
            ("praktika", "practica"),
            ("rekupero", "recupero"),
            ("bideollamada", "videollamada"),
            ("eksamenes", "examenes"),
            ("aliansa", "alianza"),
            ("balides", "validez"),
            ("internasional", "internacional"),
            ("lokal", "local"),
            ("hasen", "hacen"),
            ("asta", "hasta"),
        )
    ),
)

STOPWORDS: frozenset[str] = frozenset(
    """
    a al algo alguien alguna algun alguno algunos ante como con contra cual cuales de del desde el ella
    ellos en entre era es esa ese eso esta este esto estos estas fue ha hay la las le les lo los mas me
    mi mis muy nada ni no nos o os otra otro para pero poco por porque que quien se sea ser si sin sobre
    son su sus tambien te tengo ti tu tus un una uno unos unas y ya yo usted ustedes ustedes vosotros
    puedo puede pueden podria podrian quiero quisiera queria gustaria saber decir dime digan hola favor
    gracias porfa porfavor ok vale bueno buena buenas buen hacer hace tiene tienen tienes tener estoy
    esta estan eres soy somos despues ahora ahorita mismo queda quedan ver verlas verlo voy va van exactamente
    aqui
    """.split()
)

# El nombre de la marca aparece en casi cualquier pregunta: discrimina poco entre registros.
BRAND_TOKENS: frozenset[str] = frozenset({"horus", "horu", "group", "srl"})  # incluye la raíz


def strip_accents(text: str) -> str:
    # Conserva la ñ: es distintiva en español.
    text = text.replace("ñ", "\x00").replace("Ñ", "\x01")
    decomposed = unicodedata.normalize("NFKD", text)
    without = "".join(ch for ch in decomposed if not unicodedata.combining(ch))
    return without.replace("\x00", "ñ").replace("\x01", "ñ")


def strip_invisible(text: str) -> str:
    """Elimina caracteres invisibles zero-width y controles bidireccionales (defensa contra ofuscación)."""
    return _ZERO_WIDTH.sub("", text)


def normalize(text: str) -> str:
    text = strip_accents(strip_invisible(text).lower())
    text = _NON_WORD.sub(" ", text)
    text = _SPACES.sub(" ", text).strip()
    for pattern, replacement in _SLANG_PATTERNS:
        text = pattern.sub(replacement, text)
    return _SPACES.sub(" ", text).strip()


def tokenize(text: str) -> list[str]:
    return normalize(text).split()


@lru_cache(maxsize=8192)
def stem(token: str) -> str:
    """Raíz ligera: plurales y algunos sufijos frecuentes. No pretende ser un stemmer completo."""
    if len(token) <= 3:
        return token
    for suffix in ("amente", "mente"):
        if token.endswith(suffix) and len(token) - len(suffix) >= 4:
            token = token[: -len(suffix)]
            break
    if token.endswith("ciones"):
        return token[:-2]  # capacitaciones -> capacitacion
    if token.endswith("es") and len(token) > 4 and token[-3] in "dlrnzj":
        token = token[:-2]  # redes -> red, profesionales -> profesional
    elif token.endswith("s") and len(token) > 4:
        token = token[:-1]  # cursos -> curso, camaras -> camara
    return token


def content_tokens(text: str) -> list[str]:
    return [stem(t) for t in tokenize(text) if t not in STOPWORDS and len(t) > 1]


def tokens_match(query_token: str, doc_token: str) -> bool:
    """Coincidencia exacta o por prefijo largo común (computadoras ~ computadora, contactarlos ~ contacto)."""
    if query_token == doc_token:
        return True
    shortest = min(len(query_token), len(doc_token))
    if shortest < 5 or query_token[:5] != doc_token[:5]:
        return False
    common = 5
    while common < shortest and query_token[common] == doc_token[common]:
        common += 1
    # El prefijo común debe cubrir buena parte de la palabra: "descubrio" no es "descuento" ni "contacto" es "contabilidad".
    return common >= 0.75 * shortest


_DOUBLED = re.compile(r"(.)\1+")
_SOFT_C = re.compile(r"c([ei])")
_SOFT_G = re.compile(r"g([ei])")


def phonetic_key(token: str) -> str:
    """Clave por sonido en español: b/v, s/z/ce/ci, j/ge/gi, h muda, c/qu/k, ll/y, np/nb y letras dobles.

    «birtuales» ~ «virtuales», «teziz» ~ «tesis», «orarios» ~ «horarios», «cotisasion» ~ «cotizacion».
    """
    key = token.replace("ch", "\x02").replace("h", "")
    key = _SOFT_C.sub(r"s\1", key)
    key = _SOFT_G.sub(r"j\1", key)
    key = (
        key.replace("qu", "k")
        .replace("ck", "k")
        .replace("ks", "x")
        .replace("np", "mp")
        .replace("nb", "mb")
        .replace("c", "k")
        .replace("z", "s")
        .replace("v", "b")
        .replace("ll", "y")
    )
    return _DOUBLED.sub(r"\1", key.replace("\x02", "ch"))


@lru_cache(maxsize=4)
def _sorted_vocabulary(vocabulary: frozenset[str]) -> list[str]:
    return sorted(vocabulary)


@lru_cache(maxsize=4)
def _phonetic_index(vocabulary: frozenset[str]) -> dict[str, str]:
    """Clave fonética -> palabra. Las claves que comparten palabras distintas se descartan (sin adivinar)."""
    index: dict[str, str] = {}
    ambiguous: set[str] = set()
    for word in vocabulary:
        if len(word) < 4:
            continue
        key = phonetic_key(word)
        if key in index and index[key] != word:
            ambiguous.add(key)
        index.setdefault(key, word)
    for key in ambiguous:
        del index[key]
    return index


# Palabras correctas que el corrector confundía con otra del vocabulario (auditoría del 25/11/2026): «corto» → «costo»
# convertía «¿cuál es más corto?» en una pregunta de precios; «cobraron» → «cobran», un reclamo en una cotización.
NEVER_CORRECT = frozenset({
    "corto", "corta", "cortos", "cortas", "largo", "larga", "largos", "largas", "compara", "comparar", "comparame",
    "comparalos", "compararlos", "comparacion", "comparalas", "cobraron", "cobro", "cobraste", "cobrado", "cobrada",
    "mejor", "peor", "examen", "examenes", "quejarme", "cuotas", "cuota", "financiamiento", "gratuitos",
    "tarea", "tareas",  # «mi tarea de matemáticas» no es «tarjeta»
    "pasado", "pasada",  # «el año pasado» no es «pagado»
})


def correct_tokens(tokens: list[str], vocabulary: frozenset[str]) -> list[str]:
    """Corrige errores ortográficos sencillos contra el vocabulario conocido (difflib, sin dependencias)."""
    corrected: list[str] = []
    for token in tokens:
        if (
            token in vocabulary or token in STOPWORDS or token in NEVER_CORRECT or len(token) < 4 or token.isdigit()
        ):  # "cuales" != "cables"
            corrected.append(token)
            continue
        same_sound = _phonetic_index(vocabulary).get(phonetic_key(token))
        corrected.append(same_sound or _closest(token, _sorted_vocabulary(vocabulary)))
    return corrected


def _closest(token: str, vocab_list: list[str]) -> str:
    # Un cambio de la primera letra exige más parecido: evita "prepara" -> "reparar".
    for candidate in get_close_matches(token, vocab_list, n=3, cutoff=0.8):
        if (
            candidate[0] == token[0]
            or SequenceMatcher(None, token, candidate).ratio() >= 0.9
            or SequenceMatcher(None, stem(token), stem(candidate)).ratio() >= 0.9  # "orarios" ~ "horario"
        ):
            return candidate
    return token


@lru_cache(maxsize=16384)
def _phrase_pattern(phrase: str) -> re.Pattern[str] | None:
    """Patrón compilado una sola vez por frase: antes se normalizaba y compilaba en cada llamada (92 % del tiempo del
    motor, 25/11/2026). Las frases vienen de intents.json y de constantes del código: un conjunto cerrado y pequeño."""
    normalized = normalize(phrase)
    if not normalized:
        return None
    return re.compile(rf"(?<![a-z0-9ñ]){re.escape(normalized)}(?![a-z0-9ñ])")


def contains_phrase(normalized_text: str, phrase: str) -> bool:
    """¿Aparece la frase normalizada como secuencia de palabras completas?"""
    pattern = _phrase_pattern(phrase)
    return pattern is not None and pattern.search(normalized_text) is not None
