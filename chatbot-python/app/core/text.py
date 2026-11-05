"""Normalización de texto en español: minúsculas, sin tildes, tokens y raíces ligeras.

Es deliberadamente simple y determinista: sirve al nivel de reglas y a la búsqueda léxica.
"""

from __future__ import annotations

import re
import unicodedata
from difflib import SequenceMatcher, get_close_matches
from functools import lru_cache

_NON_WORD = re.compile(r"[^a-z0-9ñ\s]+")
_SPACES = re.compile(r"\s+")

STOPWORDS: frozenset[str] = frozenset(
    """
    a al algo alguien alguna algun alguno algunos ante como con contra cual cuales de del desde el ella
    ellos en entre era es esa ese eso esta este esto estos estas fue ha hay la las le les lo los mas me
    mi mis muy nada ni no nos o os otra otro para pero poco por porque que quien se sea ser si sin sobre
    son su sus tambien te tengo ti tu tus un una uno unos unas y ya yo usted ustedes ustedes vosotros
    puedo puede pueden podria podrian quiero quisiera queria gustaria saber decir dime digan hola favor
    gracias porfa porfavor ok vale bueno buena buenas buen hacer hace tiene tienen tienes tener estoy
    esta estan eres soy somos despues ahora mismo queda quedan ver verlas verlo voy va van exactamente
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


def normalize(text: str) -> str:
    text = strip_accents(text.lower())
    text = _NON_WORD.sub(" ", text)
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
    # El prefijo común debe cubrir buena parte de la palabra: "descubrio" no es "descuento".
    return common >= 0.6 * shortest


def correct_tokens(tokens: list[str], vocabulary: frozenset[str]) -> list[str]:
    """Corrige errores ortográficos sencillos contra el vocabulario conocido (difflib, sin dependencias)."""
    corrected: list[str] = []
    vocab_list = sorted(vocabulary)
    for token in tokens:
        if (
            token in vocabulary or token in STOPWORDS or len(token) < 4 or token.isdigit()
        ):  # "cuales" != "cables"
            corrected.append(token)
            continue
        corrected.append(_closest(token, vocab_list))
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


def contains_phrase(normalized_text: str, phrase: str) -> bool:
    """¿Aparece la frase normalizada como secuencia de palabras completas?"""
    phrase = normalize(phrase)
    if not phrase:
        return False
    return re.search(rf"(?<![a-z0-9ñ]){re.escape(phrase)}(?![a-z0-9ñ])", normalized_text) is not None
