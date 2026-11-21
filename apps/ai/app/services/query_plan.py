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
    "curso de",
    "cursos de",
    "curso sobre",
    "capacitacion sobre",
    "capacitacion en",
    "taller de",
    "diplomado de",
    "programa de",
    "quiero llevar un curso",
    "me gustaria llevar",
    "tienen cursos",
    "tienen algun curso",
    "dictan cursos",
    "dan cursos",
    "que puedo estudiar",
    "en que puedo capacitarme",
    "opciones de estudio",
    "que opciones tienen",
    "me interesa un curso",
    "como es el curso de",
    "informacion de cursos",
    "info de cursos",
    "recomiendame un curso",
    "recomiendame cursos",
    "me recomiendas un curso",
    "me recomiendas cursos",
    "sugiereme un curso",
    "quiero un curso",
    "necesito un curso",
    "busco cursos",
    "ver cursos",
    "ver los cursos",
    "lista de cursos",
    "dame los cursos",
    "pasame los cursos",
    "mostrar cursos",
    "cursos disponibles",
    "cursos vigentes",
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
    r"\b(que|cual|cuales|son|sus|tienen|tiene|ofrecen|ofrece|hay|disponible|disponibles|publicado|publicados|publicada|publicadas|"
    r"vigente|vigentes|actual|actuales|abierto|abiertos|abierta|abiertas|"
    r"quiero|quisiera|deseo|necesito|busco|llevar|algun|alguna|alguno|un|una|de|del|en|sobre|acerca|para|mi|"
    r"recomiendame|recomiendas|sugiereme|sugieres|dame|pasame|ver|mostrar|muestra|lista|listado|me|info|informacion|"
    r"dictan|dicta|dan|ensenan|ensena|opciones|porfa|porfavor|por|favor|realizan|y|o|con|el|la|los|las)\b"
)
_BROAD_LEARNING = {
    "curso",
    "cursos",
    "capacitacion",
    "capacitaciones",
    "formacion",
    "formaciones",
    "cursito",
    "taller",
    "talleres",
    "programa",
    "programas",
    "diplomado",
    "diplomados",
    "especializacion",
}
_MODALITY_WORDS = {
    "presencial",
    "presenciales",
    "virtual",
    "virtuales",
    "online",
    "semipresencial",
    "semipresenciales",
    "hibrido",
    "hibrida",
}
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
        "realizan",
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
        re.search(
            r"^(que|cual|cuales|tienen|dictan|dan|ofrecen|hay|dame|pasame|muestra|muestrame|ver|recomiendame|me recomiendas|sugiereme|quiero ver|quisiera ver|quiero llevar|opciones de|informacion de|info de|lista de|listado de|(?:(?:el|la|los|las)\s+)?(?:cursos?$|capacitaciones?$|talleres?$|servicios?$))",
            normalized,
        )
    )
    words = set(normalized.split())
    learn = learn or (request and bool(words & _BROAD_LEARNING))
    hire = hire or (request and bool(words & _BROAD_SERVICE))
    # El asistente solo orienta sobre formación: «recomiéndame redes» es una búsqueda de cursos.
    learn = learn or (not hire and bool(re.match(r"(recomiendame|me recomiendas|sugiereme)", normalized)))
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
    if request and specific and set(specific.split()) <= (_AUDIENCE | _MODALITY_WORDS):
        specific = ""
    elif request and specific:
        specific = " ".join(t for t in specific.split() if t not in _MODALITY_WORDS)
    return QueryPlan(
        goal="unknown" if learn == hire else "learn" if learn else "service",
        subject=specific,
        recommend=learn or hire or any(contains_phrase(normalized, p) for p in RELATED),
        browse=request and bool(learn or hire) and not specific,
        ambiguous=(learn and hire) or bool(re.search(r"\b(no|nunca) (quiero|deseo|necesito)\b", normalized)),
        enrollment=enrollment,
    )
