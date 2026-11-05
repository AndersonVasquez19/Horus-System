"""Plan efímero de la consulta actual; no clasifica personas ni conserva consultas.

Las frases identifican la acción solicitada. El tema queda abierto y lo resuelven
los índices léxico/MiniLM existentes contra contenido publicado, no una lista de cursos.
"""

import re
from dataclasses import dataclass
from typing import Literal

from app.core.text import contains_phrase, normalize

Goal = Literal["learn", "service", "unknown"]

LEARNING = (
    "quiero aprender",
    "necesito aprender",
    "aprender",
    "me interesa estudiar",
    "quiero estudiar",
    "estudiar",
    "formarme en",
    "capacitarme en",
    "busco capacitacion",
    "busco un curso",
    "algun cursito de",
    "un curso de",
    "curso sobre",
    "capacitacion sobre",
    "capacitacion en",
)
HIRING = (
    "necesito contratar",
    "quiero contratar",
    "contratar",
    "servicio de",
    "que instalen",
    "que reparen",
    "que configuren",
    "solucion tecnologica",
)
RELATED = (
    "algo relacionado con",
    "relacionado con",
    "relacionados con",
    "relacionada con",
    "relacionadas con",
    "relacionado a",
    "relacionados a",
    "algo sobre",
    "me interesa",
    "informacion sobre",
)
# Destinatarios genéricos: «capacitaciones para empresas» pide el listado, no una ficha que diga «empresarial».
_AUDIENCE = {
    "empresa",
    "empresas",
    "negocio",
    "negocios",
    "colaboradores",
    "trabajadores",
    "personal",
    "institucion",
    "instituciones",
    "organizacion",
    "organizaciones",
}
_SCAFFOLD = re.compile(
    r"\b(que|cual|cuales|son|sus|tienen|ofrecen|ofrece|hay|disponibles|publicados|publicadas|"
    r"quiero|quisiera|deseo|necesito|busco|algun|alguna|un|una|de|del|en|sobre|para|mi|"
    r"porfa|porfavor|por|favor|realizan|ofrecen|y|o|con|el|la|los|las)\b"
)
_BROAD_LEARNING = {"curso", "cursos", "capacitacion", "capacitaciones", "formacion", "formaciones", "cursito"}
_BROAD_SERVICE = {
    "servicio",
    "servicios",
    "tecnologico",
    "tecnologicos",
    "informatico",
    "informaticos",
    "solucion",
    "soluciones",
    "tecnologica",
}
# «para mi oficina», «en mi trabajo»: el destinatario o el lugar de la necesidad, no el tema que se busca.
_PLACE = re.compile(
    r"\b(?:para|en|de|a)\s+(?:mi|mis|nuestra|nuestro|nuestras|nuestros|la|el|una|un)\s+"
    r"(?:empresa|empresas|negocio|negocios|oficina|oficinas|local|tienda|casa|hogar|institucion|trabajo|chamba|area|equipo de trabajo)\b"
)
QUERY_WORDS = (
    frozenset(" ".join((*LEARNING, *HIRING, *RELATED)).split())
    | _BROAD_LEARNING
    | _BROAD_SERVICE
    | {
        "matricularme",
        "inscribirme",
        "inscribo",
        "matriculo",
    }
)


@dataclass(frozen=True)
class QueryPlan:
    goal: Goal
    subject: str
    recommend: bool
    browse: bool
    ambiguous: bool
    enrollment: bool


def plan_query(text: str) -> QueryPlan:
    normalized = normalize(text)
    learn = any(contains_phrase(normalized, p) for p in LEARNING)
    hire = any(contains_phrase(normalized, p) for p in HIRING)
    # Una mención aislada («mi poema dice curso») no constituye una solicitud.
    request = bool(
        re.search(r"^(que|cual|cuales|tienen|ofrecen|hay|cursos?$|capacitaciones?$|servicios?$)", normalized)
    )
    words = set(normalized.split())
    learn = learn or (request and bool(words & _BROAD_LEARNING))
    hire = hire or (request and bool(words & _BROAD_SERVICE))
    enrollment = bool(re.search(r"\b(matricularme|inscribirme|me inscribo|me matriculo)\b", normalized))
    # No convertir una negación o una pregunta factual sobre inscripciones en una acción.
    enrollment = enrollment and not re.search(r"\b(no|sin|nunca)\b", normalized)
    phrases = (*LEARNING, *HIRING, *RELATED)
    subject = _PLACE.sub(" ", normalized)
    for phrase in sorted(phrases, key=len, reverse=True):
        subject = re.sub(rf"\b{re.escape(phrase)}\b", " ", subject)
    subject = " ".join(_SCAFFOLD.sub(" ", subject).split())
    broad = _BROAD_LEARNING if learn and not hire else _BROAD_SERVICE
    specific = " ".join(t for t in subject.split() if t not in broad)
    if request and specific and set(specific.split()) <= _AUDIENCE:
        specific = ""
    return QueryPlan(
        goal="unknown" if learn == hire else "learn" if learn else "service",
        subject=specific,
        recommend=learn or hire or any(contains_phrase(normalized, p) for p in RELATED),
        browse=request and bool(learn or hire) and not specific,
        ambiguous=(learn and hire) or bool(re.search(r"\b(no|nunca) (quiero|deseo|necesito)\b", normalized)),
        enrollment=enrollment,
    )
