"""Motor conversacional de Horus AI Advisor.

Orden de decisión:
  0. Seguridad (manipulación, datos sensibles).
  1. Reglas fiables (saludos, derivación a humano).
  2. Solicitudes comerciales: se orienta la cotización; nunca se calcula un precio ni se consultan
     cotizaciones emitidas (eso lo gestiona Laravel con sus permisos).
  3. Seguimiento: «¿y eso es presencial?» se responde sobre la opción anterior, solo con datos publicados.
  4. Atributo no publicado (precio, cupos, fechas, requisitos) sobre un tema concreto: abstención explícita.
  5. Recomendación o búsqueda (léxica + semántica opcional) con aclaración o abstención.

Contexto conversacional: pequeño, explícito y derivado. Se reconstruye en cada petición a partir de
los últimos MAX_CONTEXT_TURNS mensajes del USUARIO, volviendo a ejecutar el motor sobre ellos; el texto
"assistant" enviado por el cliente nunca se usa como fuente. Nada se almacena.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, replace
from datetime import UTC, datetime
from typing import Literal

from app.core.security import detect_prompt_injection, detect_sensitive_request
from app.core.text import contains_phrase, content_tokens, correct_tokens, normalize, tokenize, tokens_match
from app.schemas.chat import (
    AdvisorReply,
    ConversationContext,
    ConversationState,
    HistoryItem,
    MatchLevel,
    Recommendation,
    RecommendRequest,
    SourceRef,
    Suggestion,
)
from app.schemas.knowledge import KnowledgeRecord
from app.services.catalog_documents import PublicFragment
from app.services import conversation_glue as glue
from app.services.contact import PublicContact
from app.services.gemini import GeminiSelector
from app.services.knowledge import KnowledgeBase
from app.services.lexical_search import LexicalIndex
from app.services.query_plan import QUERY_WORDS, plan_query
from app.services.recommendations import (
    AUDIENCE_LABEL,
    MODALITY_LABEL,
    Profile,
    Recommender,
    extract_profile,
    has_profile_slot,
    has_recommendation_cue,
)
from app.services.semantic_search import SemanticIndex
from app.services.understanding import (
    Understanding,
    evidence_sentence,
    record_covers_attribute,
    understand,
)

# Umbrales de decisión (calibrados con el split "dev"; ver README §8).
ANSWER_THRESHOLD = 0.55
CLARIFY_THRESHOLD = 0.32
AMBIGUITY_MARGIN = 0.06
# Bajo ANSWER_THRESHOLD se responde igual si hay frase exacta, puntaje ≥ NEAR_SURE_MIN y margen ≥ CLEAR_MARGIN.
NEAR_SURE_MIN = 0.45
CLEAR_MARGIN = 0.15
SEMANTIC_WEIGHT = 0.5
# Contra falsos positivos semánticos: sin apoyo léxico, la similitud solo cuenta si es alta.
SEMANTIC_MIN_LEXICAL = 0.15
SEMANTIC_ONLY_MIN_COSINE = 0.72
MAX_CONTEXT_TURNS = 5
# Relleno de cortesía (raíces) que no convierte «muchas gracias por todo» en una consulta.
# Pedido de ayuda sin tema («ayuda por favor», «tengo una duda»): se pregunta qué necesita. «Más información»
# a secas no entra aquí: lleva a los canales de contacto (decisión de test_cierre_funcional).
HELP_REQUEST = frozenset({"ayuda", "ayudar", "ayudame", "apoyo", "orientacion", "consulta", "duda", "pregunta",
    "necesito", "necesitaria"})
# Palabras que, junto a «eso» sin conversación previa, no dicen a qué se refiere («quisiera información sobre eso»).
GENERIC_REQUEST = HELP_REQUEST | {"informacion", "info", "dato", "detall"}
# Apertura común de las respuestas que declaran un tema fuera del alcance del asistente.
OUT_OF_SCOPE_OPENING = "Por ahora este asistente solo"
COURTESY_FILLER = frozenset({"mucha", "todo", "toda", "informacion", "info", "ayuda", "atencion", "respuesta", "amable",
    "amigo", "amiga"})
# Palabras de cierre que acompañan un saludo o un agradecimiento sin convertirlo en consulta («listo, gracias»).
CLOSING_FILLER = frozenset({"perfecto", "listo", "genial", "excelente", "ok", "okay", "vale", "entendido", "super", "bueno"})
# Saludos y despedidas alargados («holaaaa», «chauu»): las mismas formas que deja pasar el filtro de Laravel.
ELONGATED_COURTESY = tuple((re.compile(p), word) for p, word in (
    (r"\bhol+a{2,}\b", "hola"), (r"\bholi{2,}\b", "holi"), (r"\bwena+s+\b", "wenas"), (r"\bchau{2,}\b", "chau"),
))
# Palabras de una pregunta que SOLO pide cuánto dura o cuándo empieza («¿y cuánto dura?»). Lista cerrada: cualquier otra
# palabra (soporte, garantía, atender…) puede referirse a otra cosa. «Horario» queda fuera: también es el de atención.
BARE_DATE_WORDS = frozenset((
    "y e pero entonces ahora ok vale oye porfa por favor me quiero quisiera saber puedes podrias decir dime indica "
    "que cual es son la las el su de en cuanto cuanta cuantas cuantos cuando duracion dura duran tiempo horas semanas "
    "meses fecha fechas inicio inicia inician empieza empiezan comienza comienzan"
).split())
# Asuntos que no son un curso: «¿cuánto dura el soporte?» no pregunta por el curso del que se venía hablando.
NOT_A_COURSE_WORDS = frozenset((
    "soporte mantenimiento reparacion instalacion camaras cableado garantia convenio convenios asesoria asesorias "
    "asesoramiento servicio servicios cotizacion tramite"
).split())
COURSE_WORDS = frozenset({"curso", "cursos", "capacitacion", "capacitaciones", "clase", "clases", "taller", "talleres"})
# Texto común de las respuestas de «ese curso no está publicado» (Laravel valida la frase completa).
UNPUBLISHED_COURSE = "un curso o capacitación sobre ese tema"
MODALITY_ASKED = {
    "presencial": "presencial",
    "semipresencial": "semipresencial",
    "virtual": "virtual u online",
}

CATEGORY_INTENTS = {
    "empresa": "company_info",
    "contacto": "contact_info",
    "servicio": "service_info",
    "formacion": "training_info",
    "asesoramiento": "advisory_info",
    "convenio": "agreement_info",
    "faq": "faq",
    "politica": "policy",
}

MODALITY_PLURALS = {"virtual": "virtual", "virtuales": "virtual", "online": "virtual", "presencial": "presencial",
                    "presenciales": "presencial", "semipresencial": "semipresencial", "semipresenciales": "semipresencial"}
def _plural(label: str) -> str:
    """«virtual» → «virtuales», «online» → «online» (palabra invariable)."""
    return f"{label}es" if label.endswith("l") else label


COURSE_ONLY_ATTRIBUTES = frozenset({"fechas", "duracion", "precio", "cupos", "requisitos", "contenido"})
INFO_INTENTS = frozenset(CATEGORY_INTENTS.values()) | {"human_handoff"}
# Separadores entre preguntas de un mismo mensaje: signos de pregunta, punto y coma, o «y / también / además» seguidos
# de una palabra interrogativa o de un verbo de consulta («¿dónde están y cómo pago?»). «limpieza de datos y gráficos»
# no se divide: después de «y» no viene una pregunta.
SEVERAL_QUESTIONS = re.compile(
    r"\s*[?;]+\s*|\s*¿\s*|,\s*(?=(?:qu[eé]|cu[aá]l(?:es)?|c[oó]mo|d[oó]nde|cu[aá]ndo|cu[aá]nt[oa]s?)\b)"
    r"|\s+(?:y|e|adem[aá]s|tambi[eé]n)\s+(?=(?:qu[eé]|cu[aá]l(?:es)?|c[oó]mo|d[oó]nde|cu[aá]ndo|cu[aá]nt[oa]s?|tienen|hacen|"
    r"dan|entregan|aceptan|puedo|ofrecen|hay|emiten|atienden|trabajan|tambi[eé]n|si)\b)",
    re.IGNORECASE,
)

FALLBACK_TOPICS = (
    "cursos y capacitaciones, asesoramiento, convenios, pagos y contacto"
)
QUOTE_INTRO = (
    "Los precios y las cotizaciones los confirma un asesor de Horus Group; yo no puedo calcularlos ni "
    "consultar cotizaciones ya emitidas."
)
# Los canales (teléfono, correo, WhatsApp) salen de `public_contact` en knowledge.json: ver app/services/contact.py.


@dataclass(frozen=True)
class Hit:
    record: KnowledgeRecord
    score: float
    # Valores por defecto: `_browse_courses_reply` crea aciertos sintéticos solo con ficha y puntaje.
    lexical: float = 0.0
    semantic: float | None = None
    phrase_hit: bool = False
    matched: tuple[str, ...] = ()
    fragment: PublicFragment | None = None


@dataclass(frozen=True)
class _Prior:
    """Contexto del turno anterior reconstruido desde un ConversationState ya depurado."""

    profile: Profile | None
    profile_age: int | None
    continued: bool
    cue_age: int | None
    focus: list[KnowledgeRecord]
    focus_age: int | None
    out_of_scope: bool
    quote: bool


def _older(age: int | None) -> int | None:
    """Un turno más; el dato caduca al cumplir MAX_CONTEXT_TURNS turnos (igual que el historial)."""
    return None if age is None or age + 1 >= MAX_CONTEXT_TURNS else age + 1


def _retrieval_level(score: float) -> MatchLevel:
    if score >= 0.75:
        return "alta"
    if score >= ANSWER_THRESHOLD:
        return "media"
    if score >= CLARIFY_THRESHOLD:
        return "baja"
    return "ninguna"


class AdvisorEngine:
    def __init__(self, kb: KnowledgeBase, semantic: SemanticIndex | None = None,
                 gemini: GeminiSelector | None = None) -> None:
        self.kb = kb
        self.gemini = gemini
        self.contact = PublicContact.from_mapping(kb.data.public_contact)
        rules = kb.intents
        self.lexical = LexicalIndex(kb.search_records, frozenset(rules.low_weight_tokens))
        self.semantic = semantic
        rule_texts = [p for t in rules.templates for p in t.patterns]
        rule_texts += [p for ps in rules.topic_lexicon.values() for p in ps]
        rule_texts += [p for ps in rules.attribute_lexicon.values() for p in ps]
        rule_texts += rules.recommendation_cues.learn + rules.recommendation_cues.service
        rule_texts += rules.quote.patterns + rules.reference_markers
        self.vocabulary = self.lexical.vocabulary | QUERY_WORDS | frozenset(t for p in rule_texts for t in tokenize(p))
        self.recommender = Recommender(kb, self._similarity)
        self.generic = set(rules.generic_topics)

    # ------------------------------------------------------------------ utilidades
    @property
    def mode(self) -> Literal["lexical", "hybrid"]:
        return "hybrid" if self.semantic is not None and self.semantic.ready else "lexical"

    def corrected(self, text: str) -> str:
        return " ".join(correct_tokens(tokenize(text), self.vocabulary))

    def retrieve(self, text: str, record_ids: set[str] | None = None, limit: int = 5) -> list[Hit]:
        query = self.corrected(text)
        allowed = None if record_ids is None else record_ids | {
            f.id for f in self.kb.fragments.values() if f.source_id in record_ids
        }
        lexical = {h.record_id: h for h in self.lexical.search(query, limit=max(50, len(self.kb.search_records)), record_ids=allowed)}
        semantic = self.semantic.search(text, allowed) if self.mode == "hybrid" and self.semantic else {}
        hits: list[Hit] = []
        for rid in set(lexical) | set(semantic):
            fragment = self.kb.fragments.get(rid)
            record = self.kb.get(fragment.source_id if fragment else rid)
            if record is None:
                continue
            if record.source.kind == "catalogo_laravel" and record.status == "verified" and self.kb.catalog is not None:
                if self.kb.catalog.catalog.is_expired(datetime.now(UTC)):
                    continue
                document = self.kb.documents_by_source.get(record.id)
                if document is not None and not document.current():
                    continue
            lex = lexical[rid].score if rid in lexical else 0.0
            sem_hit = semantic.get(rid)
            sem = sem_hit.score if sem_hit is not None else None
            if (
                sem_hit is not None
                and lex < SEMANTIC_MIN_LEXICAL
                and sem_hit.cosine < SEMANTIC_ONLY_MIN_COSINE
            ):
                sem = None  # coincidencia solo vectorial y no muy alta: se ignora
            score = lex if sem is None else max(lex, (1 - SEMANTIC_WEIGHT) * lex + SEMANTIC_WEIGHT * sem)
            if score <= 0:
                continue
            lh = lexical.get(rid)
            hits.append(
                Hit(
                    record,
                    round(score, 4),
                    lex,
                    sem,
                    bool(lh and lh.phrase_hit),
                    lh.matched_terms if lh else (),
                    fragment,
                )
            )
        unique: dict[str, Hit] = {}
        for hit in hits:
            previous = unique.get(hit.record.id)
            if previous is None or (hit.score, len(hit.matched), not bool(hit.fragment), -(hit.fragment.ordinal if hit.fragment else 0)) > (
                previous.score, len(previous.matched), not bool(previous.fragment), -(previous.fragment.ordinal if previous.fragment else 0)
            ):
                unique[hit.record.id] = hit
        hits = list(unique.values())
        # A igual puntaje decide la frase exacta, no el orden alfabético del identificador.
        hits.sort(key=lambda h: (-h.score, not h.phrase_hit, h.record.id))
        return hits[:limit]

    def _similarity(self, text: str, record_ids: set[str]) -> dict[str, float]:
        return {h.record.id: h.score for h in self.retrieve(text, record_ids, limit=len(record_ids) or 1)}

    def _reply(self, **kwargs: object) -> AdvisorReply:
        kwargs.setdefault("mode", self.mode)
        return AdvisorReply(**kwargs)  # type: ignore[arg-type]

    @staticmethod
    def _source(record: KnowledgeRecord) -> SourceRef:
        return SourceRef(id=record.id, title=record.title, url_path=record.source.url_path)

    # ------------------------------------------------------------------ niveles 0 y 1
    def _safety(self, text: str) -> AdvisorReply | None:
        if detect_prompt_injection(text):
            return self._reply(
                answer=(
                    "No puedo cambiar mis reglas, ejecutar instrucciones ni revelar configuración interna. "
                    f"Sí puedo ayudarte con información de Horus Group: {FALLBACK_TOPICS}."
                ),
                intent="prompt_injection",
                match_level="ninguna",
                abstained=True,
            )
        if detect_sensitive_request(text):
            return self._reply(
                answer=(
                    "No tengo acceso a datos personales, administrativos ni de estudiantes y no puedo compartirlos. "
                    "Si eres parte de Horus Group, usa los canales internos autorizados."
                ),
                intent="sensitive_request",
                match_level="ninguna",
                abstained=True,
            )
        return None

    def _rules(self, text: str) -> AdvisorReply | None:
        normalized = normalize(text)
        handoff = self.kb.intents.human_handoff
        if any(contains_phrase(normalized, p) for p in handoff.patterns):
            record = self.kb.get(handoff.record_id)
            assert record is not None  # validado al cargar
            return self._reply(
                answer=record.answer,
                intent="human_handoff",
                match_level="alta",
                sources=[self._source(record)],
            )
        for pattern, word in ELONGATED_COURTESY:
            normalized = pattern.sub(word, normalized)
        for template in self.kb.intents.templates:
            found = [p for p in template.patterns if contains_phrase(normalized, p)]
            if not found:
                continue
            if template.standalone:
                rest = normalized
                for p in sorted(found, key=len, reverse=True):
                    rest = rest.replace(normalize(p), " ")
                if set(content_tokens(rest)) - COURTESY_FILLER - CLOSING_FILLER:
                    continue  # "hola, quiero aprender redes" sigue al siguiente nivel
            return self._reply(answer=template.response, intent=template.name, match_level="alta")
        return None

    def _modalities(self) -> AdvisorReply | None:
        """Cómo se dictan las clases (texto publicado de modalidades: Google Meet, grabaciones y días presenciales)."""
        record = self.kb.get("cursos_modalidades")
        if record is None or record.status != "verified" or not record.detail_answer:
            return None
        return self._reply(answer=record.detail_answer, intent=CATEGORY_INTENTS[record.category], match_level="alta",
                           sources=[self._source(record)])

    def _about(self, text: str) -> AdvisorReply | None:
        """Preguntas sobre el propio asistente, operaciones aritméticas y datos institucionales no publicados.

        Van antes de la búsqueda: «¿quién te creó?» no debe responder con los canales de contacto, «¿cuánto es 2+2?» no
        es una consulta de precios y «¿en qué año se fundó?» no tiene registro publicado (se dice con honestidad).
        """
        if glue.is_identity_question(text):
            return self._reply(answer=glue.IDENTITY_REPLY, intent="capabilities", match_level="alta")
        if glue.is_math(text) or glue.is_creative_request(text):
            return self._off_topic(text)
        if glue.is_unpublished_fact(text):
            return self._reply(answer=glue.UNPUBLISHED_FACT_REPLY, intent="unavailable_info", match_level="ninguna",
                               abstained=True)
        if (glue.asks_recordings(text) or glue.asks_class_platform(text)) and (reply := self._modalities()) is not None:
            return reply
        if glue.asks_level(text):
            return self._reply(answer=glue.LEVEL_REPLY, intent="unavailable_info", match_level="ninguna", abstained=True)
        if glue.is_complaint(text):
            return self._complaint()
        if glue.asks_after_enrolling(text):
            steps = self.kb.get("proceso_matricula_pasos")
            if steps is not None and steps.status == "verified":
                return self._reply(answer=steps.answer, intent=CATEGORY_INTENTS[steps.category], match_level="alta",
                                   sources=[self._source(steps)])
        if glue.asks_unpublished_study_fact(text):
            return self._reply(answer=glue.UNPUBLISHED_FACT_REPLY, intent="unavailable_info", match_level="ninguna",
                               abstained=True)
        if glue.asks_installments(text):
            return self._reply(answer=glue.INSTALLMENTS_REPLY, intent="unavailable_info", match_level="ninguna",
                               abstained=True)
        if glue.not_offered_topic(text) and self._course_by_title(text, set()) is None:
            return self._unpublished_topic("learn", text)
        return None

    def _names_unpublished_course(self, text: str) -> bool:
        """Nombra «curso de X» y ningún curso publicado menciona X: sin esto se respondía con el curso en foco.

        Se mira solo el contenido de los cursos: en el índice completo «contabilidad» se daba por conocida por
        parecerse a «contacto».
        """
        courses = self._published_courses()
        if not courses:
            return False  # sin catálogo vigente no se puede afirmar que un curso no exista
        words = {word for r in courses
                 for word in content_tokens(" ".join([r.title, r.description, *r.keywords, *r.topics]))}
        subjects = [content_tokens(word) for word in glue.named_course_subjects(self.corrected(text))]
        return any(tokens and not any(tokens_match(tokens[0], word) for word in words) for tokens in subjects)

    def _complaint(self) -> AdvisorReply:
        """Un reclamo («me cobraron de más», «no me llegó el certificado»): Libro de Reclamaciones y un asesor.

        Dos textos exactos de fichas verificadas, separados por una línea en blanco (Laravel los acepta tal cual).
        """
        records = [r for r in (self.kb.get("libro_reclamaciones"), self.kb.get("contacto_canales"))
                   if r is not None and r.status == "verified"]
        return self._reply(answer="\n\n".join(r.answer for r in records), intent="policy", match_level="media",
                           sources=[self._source(r) for r in records])

    def _published_courses(self) -> list[KnowledgeRecord]:
        """Cursos publicados del catálogo vigente (vacío si el catálogo caducó)."""
        if self.kb.catalog is None or self.kb.catalog.catalog.is_expired(datetime.now(UTC)):
            return []
        return [r for r in self.kb.records
                if r.source.kind == "catalogo_laravel" and r.status == "verified" and r.kind in {"course", "training_program"}]

    def _courses_in_message(self, message: str) -> list[KnowledgeRecord]:
        generic = {"curso", "cursos", "capacitacion", "capacitaciones", "taller", "programa", "mejor", "corto", "largo"}
        asked = set(content_tokens(message)) - generic
        return [r for r in self._published_courses() if asked & set(content_tokens(r.title))]

    def _course_shortcuts(self, message: str, users: list[str], prior: _Prior | None) -> AdvisorReply | None:
        """Preguntas sobre los cursos publicados que el flujo general resolvía mal (auditoría del 25/11/2026).

        Comparar («compáralos», «¿cuál es más corto?», «¿cuál es mejor, redes u ofimática?»), pedir una recomendación
        sin tema, listar por modalidad («¿cuáles son virtuales?», «no quiero virtual») y preguntar un dato de un curso
        nombrado («¿el curso de comunicación es virtual?»). Todo sale de las fichas publicadas, con las frases que
        Laravel ya verifica; si no hay catálogo vigente, se sigue el flujo general.
        """
        courses = self._published_courses()
        if not courses:
            return None
        focus = list(prior.focus) if prior is not None else (self._focus(users) if users else [])
        focus_courses = [r for r in focus if r.stable_id is not None]
        listing_in_focus = any(r.id == "cat_listado_formacion" for r in focus)
        corrected = self.corrected(message)
        u = understand(corrected, self.kb)

        kind = glue.comparison_kind(message)
        named = self._courses_in_message(message) if kind is not None else []
        if kind == "general" and len(named) < 2 and set(tokenize(normalize(message))) & {"cursos", "capacitaciones"}:
            kind = None  # «compárame los cursos disponibles» (botón del orientador): ficha detallada del listado
        if kind is not None:
            # «¿cuál es el más corto/largo?» pregunta por todo el catálogo: el foco guarda solo 2 cursos y dejaba fuera al
            # tercero (producción, 25/11/2026). «compáralos» sí compara los cursos en foco.
            in_focus = focus_courses if kind == "general" else []
            chosen = (named if len(named) >= 2 else in_focus if len(in_focus) >= 2
                      else courses if len(courses) <= 5 else [])
            if len(chosen) < 2:
                return None
            chosen = chosen[:5]
            if kind == "duration":
                days = [glue.duration_days(r.duration) for r in chosen]
                if all(d is not None for d in days):  # sin datos comparables se conserva el orden del catálogo
                    longest = glue.asks_longest(message)
                    chosen = [r for _, r in sorted(zip(days, chosen, strict=True), key=lambda p: p[0] or 0.0,
                                                   reverse=longest)]
            attrs = ("duracion",) if kind == "duration" else ("modalidad", "duracion", "fechas")
            return self._followup(chosen, replace(u, attributes=attrs, asked_modalities=()))

        if glue.is_open_recommendation(message) and (listing_in_focus or not focus_courses):
            titles = [f"«{r.title}»" for r in courses[:4]]
            options = titles[0] if len(titles) == 1 else f"{', '.join(titles[:-1])} o {titles[-1]}"
            listing = self.kb.get("cat_listado_formacion")
            return self._reply(
                answer="Te ayudo a elegir según lo que buscas.",
                intent="course_recommendation", match_level="media", needs_clarification=True,
                clarification_question=f"¿Cuál se acerca más a tu objetivo: {options}? También puedo compararlos.",
                sources=[self._source(listing)] if listing is not None else [],
                context=ConversationContext(turns_considered=0, focus_ids=[listing.id] if listing else []),
            )

        rejected = glue.rejected_modality(message)
        if rejected is not None:
            others = [r for r in courses if rejected not in {"virtual" if m == "online" else m for m in r.modalities}]
            if not others:
                return self._reply(answer=f"Por ahora todos los cursos vigentes son {_plural(MODALITY_LABEL[rejected])}.",
                                   intent="training_info", match_level="media",
                                   sources=[self._source(r) for r in courses[:5]])
            labels = ", ".join(
                f"{r.title} ({', '.join(MODALITY_LABEL.get(m, m) for m in r.modalities)})" if r.modalities else r.title
                for r in others[:6])
            return self._reply(answer=f"Cursos vigentes que no son {_plural(MODALITY_LABEL[rejected])}: {labels}.",
                               intent="training_info", match_level="media", sources=[self._source(r) for r in others[:5]])

        named_course = self._course_by_title(corrected, set())
        if named_course is not None and (u.attributes or u.asked_modalities):
            attrs = u.attributes or ("modalidad",)
            return self._followup([named_course], replace(u, attributes=attrs))

        words = set(tokenize(normalize(message)))
        if words <= {"modalidad", "modalidades", "la", "las", "y", "cual", "cuales", "es", "son"} and words & {"modalidad", "modalidades"}:
            # «modalidad» sola: la de cada curso publicado, no la frase genérica «consúltalo con un asesor».
            return self._followup((focus_courses or courses)[:5], replace(u, attributes=("modalidad",), asked_modalities=()))
        listing_words = {"curso", "cursos", "capacitacion", "capacitaciones", "cuales", "hay", "tienen", "ofrecen", "son"}
        asked = {MODALITY_PLURALS[w] for w in words if w in MODALITY_PLURALS}
        if len(asked) == 1 and not focus_courses and not named_course and words & listing_words:
            return self._browse_courses_reply(asked.pop(), False)
        return None

    def _off_topic(self, seed: str) -> AdvisorReply:
        """Fuera de lo publicado: tres variantes estables para no repetir la misma frase palabra por palabra."""
        answer = glue.variant(glue.OFF_TOPIC_REPLY, seed).format(topics=FALLBACK_TOPICS)
        return self._reply(answer=answer, intent="out_of_scope", match_level="ninguna", abstained=True)

    # ------------------------------------------------------------------ pegamento conversacional
    def _courtesy(self, options: tuple[str, ...], message: str) -> AdvisorReply:
        """Frase de cortesía con variante estable: sin fuentes, cifras ni enlaces (Laravel la valida como cortesía)."""
        return self._reply(answer=glue.variant(options, message), intent="acknowledgement", match_level="alta")

    def _glue(
        self, message: str, users: list[str], prior: _Prior | None
    ) -> tuple[AdvisorReply | None, str]:
        """Mensajes cortos que solo tienen sentido en una conversación: «ok», «sí», «el otro», «más barato»…

        Devuelve (respuesta, mensaje). Si no hay respuesta directa, el mensaje puede venir reescrito para que siga el
        flujo de seguimiento ya verificado («sí» con un curso en foco pide más detalles de ese curso). Nada se afirma
        que la ficha publicada no tenga: el precio, por ejemplo, sigue derivándose a un asesor.
        """
        if " ".join(glue.content_words(message)) in glue.ENROLLMENT_WORDS or glue.asks_how_to_enroll(message):
            return None, glue.ENROLLMENT_QUERY  # «inscripción» sola o «¿cómo puedo matricularme?»: los pasos
        if re.search(r"\b(gratis|gratuit[oa]s?|sin costo)\b", normalize(message)):
            return None, "¿Tienen becas o descuentos?"  # «¿hay cursos gratuitos?»: promociones (sin dato confirmado)
        contact = self.kb.get(self.kb.intents.human_handoff.record_id)
        if contact is not None and " ".join(glue.content_words(message)) == "informacion":
            # «Información» sola lleva a los canales de contacto; con cursos cargados respondía la ficha de uno.
            return self._reply(answer=contact.answer, intent=CATEGORY_INTENTS[contact.category], match_level="alta",
                               sources=[self._source(contact)]), message
        choice = self._choose_course(message, users, prior)
        if choice is not None:
            return choice, message
        partners = self._partners(message, users, prior)
        if partners is not None:
            return partners, message
        if glue.asks_class_days(message):
            seen = list(prior.focus) if prior is not None else self._focus(users) if users else []
            if not any(r.stable_id is not None for r in seen) and (reply := self._modalities()) is not None:
                return reply, message
        kind = glue.classify(message)
        pick = glue.pick_of(message)
        comparative = glue.comparative_of(message)
        if kind is None and pick is None and comparative is None:
            return None, message  # consulta normal: sin coste añadido
        has_context = bool(users) or prior is not None
        focus: list[KnowledgeRecord] = []
        if has_context and kind not in {"empty", "laugh", "howareyou", "sorry", "no", "help", "rude", "time", "english"}:
            focus = list(prior.focus) if prior is not None else self._focus(users)

        if kind == "empty":
            return self._courtesy(glue.EMPTY_REPLY, message), message
        if kind == "help":
            return self._courtesy(glue.HELP_REPLY, message), message
        if kind == "rude":
            return self._courtesy(glue.RUDE_REPLY, message), message
        if kind == "time":
            return self._courtesy(glue.TIME_REPLY, message), message
        if kind == "english":
            return self._courtesy(glue.ENGLISH_REPLY, message), message
        if kind == "laugh":
            return self._courtesy(glue.LAUGH_REPLY, message), message
        if kind == "howareyou":
            return self._courtesy(glue.HOWAREYOU_REPLY, message), message
        if kind == "sorry":
            return self._courtesy(glue.SORRY_REPLY, message), message
        if kind == "no":
            return self._courtesy(glue.NO_REPLY, message), message
        course_focus = any(r.stable_id is not None or r.id == "cat_listado_formacion" for r in focus)
        if kind == "ack":
            options = glue.ACK_WITH_FOCUS if course_focus else glue.ACK_NO_FOCUS
            return self._courtesy(options, message), message
        if kind == "confused":
            # «No entendí»: repetir lo mismo no ayuda; se pregunta qué parte aclarar (sin inventar contenido).
            options = glue.CONFUSED_WITH_FOCUS if course_focus else glue.REPEAT_NO_FOCUS
            return self._courtesy(options, message), message
        if kind in {"yes", "repeat"}:
            if focus:
                return None, "dame más detalles"  # sigue el flujo: detalles del curso en foco
            options = glue.REPEAT_NO_FOCUS if kind == "repeat" else glue.ACK_NO_FOCUS
            return self._courtesy(options, message), message

        if focus:
            options = focus
            if len(focus) == 1 and focus[0].id == "cat_listado_formacion":
                # «Qué cursos tienen» deja como foco la lista; «el primero» se refiere al primero de esa lista.
                options = [r for r in self.kb.records if r.source.kind == "catalogo_laravel" and r.status == "verified"
                           and r.kind in {"course", "training_program"}][:15]
            chosen: KnowledgeRecord | None = None
            if pick == "first":
                chosen = options[0] if options else None
            elif pick == "second" and len(options) >= 2:
                chosen = options[1]
            elif pick == "other" and len(options) >= 2 and options is focus:
                chosen = options[1]  # el turno anterior comparó dos opciones concretas
            elif pick == "other" and len(focus) == 1 and focus[0].id != "cat_listado_formacion":
                # Un solo curso en foco: «el otro» solo es claro si hay exactamente otro curso publicado.
                others = [r for r in self.kb.records if r.source.kind == "catalogo_laravel" and r.status == "verified"
                          and r.kind in {"course", "training_program"} and r.id != focus[0].id]
                chosen = others[0] if len(others) == 1 else None
                if len(others) > 1:
                    # Con varios cursos, «el otro» no es uno solo: se ofrecen los demás para elegir.
                    return self._which_course(others), message
            elif pick == "last" and len(options) >= 2:
                chosen = options[-1]
            if chosen is not None:
                return self._focus_reference([chosen]), message

        if comparative is not None:
            rewritten = {"price": "cuánto cuesta", "duration": "cuánto dura", "schedule": "cuál es el horario"}[comparative]
            return None, rewritten
        return None, message

    def _choose_course(self, message: str, users: list[str], prior: _Prior | None) -> AdvisorReply | None:
        """«quiero ese», «mejor redes», «opción 1», «y el otro»: elegir un curso sin escribir su nombre completo.

        Si no está claro cuál es, se pregunta con los nombres; nunca se elige uno al azar.
        """
        target = glue.switch_target(message)
        number = glue.numbered_choice(message)
        this = glue.points_at_this(message)
        other = glue.pick_of(message) == "other"
        if target is None and number is None and not this and not other:
            return None
        courses = self._published_courses()
        if not courses:
            return None
        if target is not None:
            corrected = self.corrected(target)
            named = self._course_by_title(corrected, set())
            # Todo lo que sigue a «mejor» tiene que ser parte del título: «prefiero el horario de redes» es otra cosa.
            if named is None or not set(content_tokens(corrected)) <= set(content_tokens(named.title)):
                return None
            return self._focus_reference([named])
        focus: list[KnowledgeRecord] = []
        if prior is not None:
            focus = list(prior.focus)
        elif users:
            focus = self._focus(users)
        listing = len(focus) == 1 and focus[0].id == "cat_listado_formacion"
        shown = courses if listing else focus if all(r in courses for r in focus) else []
        if focus and not listing and not shown:
            return None  # se venía hablando de otra cosa (convenios, pagos): sigue el flujo general
        if number is not None:
            if (listing or len(shown) >= 2) and number <= len(shown):
                return self._focus_reference([shown[number - 1]])
            return self._which_course(courses)
        if this:
            return self._focus_reference(shown) if len(shown) == 1 else self._which_course(shown or courses)
        if not focus or listing:
            return self._which_course(courses)
        return None  # «el otro» entre dos opciones concretas: lo resuelve el flujo que sigue

    def _which_course(self, options: list[KnowledgeRecord]) -> AdvisorReply:
        if len(options) == 1:
            return self._focus_reference(options)
        titles = [f"«{r.title}»" for r in options[:5]]
        joined = f"{', '.join(titles[:-1])} o a {titles[-1]}"
        return self._reply(
            answer="Quiero asegurarme de entenderte bien antes de responder.",
            intent="clarification",
            match_level="baja",
            needs_clarification=True,
            clarification_question=f"¿Te refieres a {joined}?",
            context=ConversationContext(turns_considered=0, focus_ids=[r.id for r in options[:2]]),
        )

    def _partners(self, message: str, users: list[str], prior: _Prior | None) -> AdvisorReply | None:
        if not glue.asks_with_whom(message):
            return None
        focus = list(prior.focus) if prior is not None else self._focus(users) if users else []
        listing = self.kb.get("convenios_resumen")
        if listing is None or listing.status != "verified" or not any(r.category == "convenio" for r in focus):
            return None
        return self._reply(answer=listing.answer, intent=CATEGORY_INTENTS[listing.category], match_level="alta",
                           sources=[self._source(listing)])

    # ------------------------------------------------------------------ búsqueda
    def _answer_from_hits(self, hits: list[Hit], query_terms: set[str]) -> AdvisorReply:
        if not hits or hits[0].score < CLARIFY_THRESHOLD:
            return self._off_topic(" ".join(sorted(query_terms)))
        top = hits[0]
        second = hits[1] if len(hits) > 1 else None

        if top.record.status != "verified":
            return self._abstain_with_context(top, hits)

        # Casi seguro: frase exacta, cerca del umbral y sin competidor cercano («¿Dictan diplomados?»).
        # Además, lo que coincide cubre al menos la mitad de la consulta: una palabra suelta no basta
        # («¿venden celulares de segunda?» no es una pregunta de contacto).
        near_sure = (top.phrase_hit and top.score >= NEAR_SURE_MIN
            and (second is None or top.score - second.score >= CLEAR_MARGIN)
            and bool(query_terms) and len(set(top.matched) & query_terms) >= len(query_terms) / 2)
        if top.score < ANSWER_THRESHOLD and not near_sure:
            pair = self._complementary_pair(top, second, query_terms)
            if pair is not None:
                return self._reply(
                    answer="\n\n".join(r.answer for r in pair),
                    intent=CATEGORY_INTENTS[top.record.category],
                    match_level="media",
                    sources=[self._source(r) for r in pair],
                )
            return self._clarify(hits)

        ambiguous = (
            second is not None
            # Un empate con un dato no publicado («¿Cuándo?»: horario o fechas de cursos) también es ambiguo.
            and second.record.status in ("verified", "unavailable")
            and top.score - second.score < AMBIGUITY_MARGIN
            and second.record.category != top.record.category
            and second.record.id not in top.record.related
            and top.record.id not in second.record.related
            and not top.phrase_hit
        )
        if ambiguous:
            return self._clarify(hits)

        if (second is not None and second.record.status == "verified"
            and top.record.stable_id is not None and second.record.stable_id is not None
            and top.score - second.score < AMBIGUITY_MARGIN
            and not set(content_tokens(top.record.title)) <= query_terms):
            return self._clarify([])

        if top.fragment is not None:
            return self._reply(answer=top.fragment.answer, intent=CATEGORY_INTENTS[top.record.category],
                match_level=_retrieval_level(top.score), sources=[self._source(top.record)])

        records = [top.record]
        if (
            second is not None
            and second.record.status == "verified"
            and second.phrase_hit
            and second.score >= ANSWER_THRESHOLD
            and set(second.matched) - set(top.matched)
        ):
            records.append(second.record)  # p. ej. "dirección y teléfono"
        return self._reply(
            answer="\n\n".join(r.answer for r in records),
            intent=CATEGORY_INTENTS[top.record.category],
            match_level=_retrieval_level(top.score),
            sources=[self._source(r) for r in records],
        )

    def _complementary_pair(
        self, top: Hit, second: Hit | None, query_terms: set[str]
    ) -> tuple[KnowledgeRecord, KnowledgeRecord] | None:
        """Dos registros verificados y relacionados que juntos cubren toda la consulta."""
        if second is None or second.score < CLARIFY_THRESHOLD:
            return None
        if top.record.status != "verified" or second.record.status != "verified":
            return None
        related = second.record.id in top.record.related or top.record.id in second.record.related
        if not related or not set(second.matched) - set(top.matched):
            return None
        if query_terms and query_terms <= set(top.matched) | set(second.matched):
            return top.record, second.record
        return None

    def _confirmed_subject(self, hits: list[Hit], exclude: set[str]) -> KnowledgeRecord | None:
        """Registro verificado que explica de qué trata la consulta, más allá de las palabras de atributo."""
        for h in hits:
            if (
                h.record.status == "verified"
                and h.score >= CLARIFY_THRESHOLD
                and h.record.category != "contacto"
                and set(h.matched) - exclude
            ):
                return h.record
        return None

    def _abstain_with_context(self, top: Hit, hits: list[Hit]) -> AdvisorReply:
        intent = "unavailable_info" if top.record.status == "unavailable" else "unverified_info"
        answer = top.record.answer
        sources: list[SourceRef] = []
        confirmed = self._confirmed_subject(hits[1:], set(top.matched))
        if confirmed is not None:
            answer += f"\n\n¿Quieres que te cuente más sobre «{confirmed.title}»?"
            sources.append(self._source(confirmed))
        elif not any(c in answer for c in (self.contact.phone, self.contact.email, self.contact.whatsapp_url)):
            # Sin otra opción confirmada ni canal en el texto, la abstención no termina en un callejón: indica a quién preguntar.
            answer += f"\n\n{self.contact.advisor_line}"
        return self._reply(
            answer=answer,
            intent=intent,
            match_level=_retrieval_level(top.score),
            abstained=True,
            sources=sources,
            missing_data=[top.record.title.lower()],
        )

    def _clarify(self, hits: list[Hit]) -> AdvisorReply:
        options = [
            h.record for h in hits[:3] if h.record.status == "verified" and h.score >= CLARIFY_THRESHOLD
        ]
        if any(r.stable_id is not None for r in options):
            # «¿y el de redes?»: entre un curso y una ficha institucional («Redes sociales») se elige entre cursos;
            # si queda uno solo, no hay nada que aclarar.
            options = [r for r in options if r.stable_id is not None]
            if len(options) == 1:
                return self._focus_reference(options)
        titles = list(dict.fromkeys(r.title for r in options))[:2]
        if len(titles) >= 2:
            question = f"¿Te refieres a «{titles[0]}» o a «{titles[1]}»?"
        elif titles:
            question = (
                f"¿Tu consulta es sobre «{titles[0]}»? Cuéntame un poco más para responderte con precisión."
            )
        else:
            question = f"¿Podrías darme más detalles? Puedo ayudarte con {FALLBACK_TOPICS}."
        return self._reply(
            answer="Quiero asegurarme de entenderte bien antes de responder.",
            intent="clarification",
            match_level="baja",
            needs_clarification=True,
            clarification_question=question,
            context=ConversationContext(turns_considered=0, focus_ids=[r.id for r in options[:2]]),
        )

    # ------------------------------------------------------------------ atributos y seguimiento
    def _attribute_line(self, rec: KnowledgeRecord, attr: str, u: Understanding) -> tuple[str, bool]:
        """Una línea por (opción, atributo). Devuelve (texto, ¿se afirmó un dato confirmado?)."""
        title = f"«{rec.title}»"
        terms = self.kb.intents.attribute_evidence_terms
        if attr == "modalidad":
            if not rec.modalities:
                if "modalidad: hibrido." in (rec.description or "").lower():
                    if u.asked_modalities:
                        parts = [
                            f"sí es {MODALITY_ASKED[m]}"
                            if m == "semipresencial"
                            else f"no es {MODALITY_ASKED[m]}"
                            for m in u.asked_modalities
                        ]
                        return f"{title}: {'; '.join(parts)} (modalidad: híbrido).", "semipresencial" in u.asked_modalities
                    return f"{title}: es híbrido.", True
                return f"{title}: la modalidad la confirma un asesor.", False
            published = ", ".join(MODALITY_LABEL[m] for m in rec.modalities)
            offered = {"virtual" if m == "online" else m for m in rec.modalities}
            if u.asked_modalities:
                parts = [
                    f"sí es {MODALITY_ASKED[m]}"
                    if m in offered
                    else f"no es {MODALITY_ASKED[m]}"
                    for m in u.asked_modalities
                ]
                # Publicada, la modalidad es un dato confirmado tanto si la respuesta es «sí» como «no».
                return f"{title}: {'; '.join(parts)} (modalidad: {published}).", True
            return f"{title}: es {published}.", True
        if attr == "audiencia":
            if not rec.audience:
                return f"{title}: el público al que se dirige lo confirma un asesor.", False
            labels = " y ".join(AUDIENCE_LABEL[a] for a in rec.audience)
            sentence = evidence_sentence(rec, terms.get("audiencia", []))
            if u.asked_audiences and not set(u.asked_audiences) & set(rec.audience):
                return f"{title}: está dirigido a {labels}, no a ese público.", False
            return f"{title}: está dirigido a {labels}. {sentence or ''}".rstrip(), True
        if attr == "certificado":
            if rec.certification:  # ficha del catálogo; Laravel valida la misma frase
                return f"{title}: certificación: {rec.certification}.", True
            sentence = evidence_sentence(rec, terms.get("certificado", []))
            if sentence:
                return f"{title}: {sentence}", True
            return f"{title}: si incluye certificado lo confirma un asesor.", False
        if attr == "precio":
            if rec.public_price:
                return f"{title}: cuesta {rec.public_price}.", True
            return f"{title}: el precio lo confirma un asesor.", False
        if attr == "fechas":
            if rec.start_text and rec.schedule:
                return f"{title}: inicia el {rec.start_text}; el horario es {rec.schedule}.", True
            if rec.start_text:
                return f"{title}: inicia el {rec.start_text}.", True
            if rec.schedule:
                return f"{title}: el horario es {rec.schedule}.", True
            return f"{title}: las fechas y horarios los confirma un asesor.", False
        if attr == "duracion":
            if rec.duration:
                return f"{title}: dura {rec.duration}.", True
            return f"{title}: la duración la confirma un asesor.", False
        if attr == "requisitos":
            if rec.requirements:
                return f"{title}: requisitos: {'; '.join(rec.requirements)}.", True
            return f"{title}: los requisitos los confirma un asesor.", False
        if attr == "cupos":
            return f"{title}: los cupos los confirma un asesor.", False
        if attr == "contenido":
            # El fragmento literal publicado (el mismo que Laravel reconstruye), no una frase nueva.
            document = self.kb.documents_by_source.get(rec.id)
            for fragment in document.fragments if document is not None else ():
                if any(word in normalize(fragment.answer) for word in ("temario", "silabo", "contenido")):
                    return fragment.answer, True
            return f"{title}: el temario lo confirma un asesor.", False
        if attr == "tiempo_atencion":
            sentence = evidence_sentence(rec, terms.get("tiempo_atencion", []))
            if sentence:
                return f"{title}: {sentence}", True
            return f"{title}: el plazo de atención lo confirma un asesor.", False
        return f"{title}: ese dato lo confirma un asesor.", False

    def _followup(self, focus: list[KnowledgeRecord], u: Understanding) -> AdvisorReply:
        if len(focus) == 1 and focus[0].id == "cat_listado_formacion":
            courses = [
                r for r in self.kb.records
                if r.source.kind == "catalogo_laravel" and r.status == "verified" and r.kind in {"course", "training_program"}
            ]
            if 1 <= len(courses) <= 5:
                focus = courses
        lines: list[str] = []
        affirmed = False
        missing: list[str] = []
        for rec in focus:
            for attr in u.attributes:
                line, ok = self._attribute_line(rec, attr, u)
                if line not in lines:
                    lines.append(line)
                affirmed = affirmed or ok
                if not ok:
                    missing.append(f"{attr} de «{rec.title}»")
        unavailable_only = all(a in self.kb.intents.unavailable_attributes for a in u.attributes)
        if not affirmed:
            lines.append(self.contact.confirm_line)
        return self._reply(
            answer="\n".join(lines),
            intent="unavailable_info" if (not affirmed and unavailable_only) else "followup_attribute",
            match_level="media" if affirmed else "baja",
            abstained=not affirmed,
            sources=[self._source(r) for r in focus],
            missing_data=missing,
        )

    def _evidenced_hit(self, hits: list[Hit], u: Understanding) -> AdvisorReply | None:
        """Si se pregunta un atributo, prefiere el registro cuyo texto lo contiene literalmente."""
        terms = self.kb.intents.attribute_evidence_terms
        if hits and hits[0].record.status != "verified" and hits[0].score >= CLARIFY_THRESHOLD:
            return None  # la mejor coincidencia es una duda no confirmada: prevalece la abstención
        for h in hits[:3]:
            if h.record.status != "verified" or h.score < ANSWER_THRESHOLD:
                continue
            for attr in u.attributes:
                if (
                    attr in terms
                    and attr not in {"modalidad", "audiencia"}
                    and evidence_sentence(h.record, terms[attr])
                ):
                    return self._reply(
                        answer=evidence_sentence(h.record, terms[attr]) or h.record.answer,
                        intent=CATEGORY_INTENTS[h.record.category],
                        match_level=_retrieval_level(h.score),
                        sources=[self._source(h.record)],
                    )
        return None

    @staticmethod
    def _names_other_record(focus: list[KnowledgeRecord], hits: list[Hit]) -> bool:
        """«¿y a qué hora atienden?» tras la ubicación: la pregunta nombra con frase exacta otro registro.

        Solo aplica cuando el foco es información institucional (no recomendable); sobre un curso,
        programa o convenio recomendable («¿y dan certificado?» tras recomendarlo) se conserva el foco.
        """
        if not hits:
            return False
        top, second = hits[0], (hits[1] if len(hits) > 1 else None)
        return (
            top.record.status == "verified"
            and top.phrase_hit
            and top.score >= CLARIFY_THRESHOLD
            and (second is None or top.score - second.score >= AMBIGUITY_MARGIN)
            and top.record.id not in {r.id for r in focus}
            and all(not r.recommendable and r.source.kind != "catalogo_laravel" for r in focus)
        )

    def _focus_reference(self, focus: list[KnowledgeRecord]) -> AdvisorReply:
        rec = focus[0]
        # La ficha de un curso ya empieza con su título («Comunicacion: …»): no se repite.
        answer = rec.answer if rec.answer.startswith(f"{rec.title}:") else f"Sobre «{rec.title}»: {rec.answer}"
        return self._reply(
            answer=answer,
            intent="followup_attribute",
            match_level="media",
            sources=[self._source(rec)],
        )

    def _course_by_title(self, message: str, ignore: set[str]) -> KnowledgeRecord | None:
        """Curso publicado cuyo título comparte una palabra propia con la consulta, solo si es uno y sin ambigüedad."""
        generic = {"curso", "cursos", "capacitacion", "capacitaciones", "taller", "programa", "clase", "clases", "horario",
                   "horarios", "empieza", "inicia", "dura", "duracion", "cuesta", "precio", "modalidad", "certificado"}
        asked = set(content_tokens(message)) - ignore - generic
        if not asked or self.kb.catalog is None or self.kb.catalog.catalog.is_expired(datetime.now(UTC)):
            return None
        found = [r for r in self.kb.records
                 if r.source.kind == "catalogo_laravel" and r.status == "verified" and r.kind in {"course", "training_program"}
                 and asked & set(content_tokens(r.title))]
        return found[0] if len(found) == 1 else None

    @staticmethod
    def _asks_about_something_else(focus: list[KnowledgeRecord], message: str) -> bool:
        if not all(r.source.kind == "catalogo_laravel" and (r.stable_id is None or r.kind in {"course", "training_program"})
                   for r in focus):
            return False  # el foco no son cursos: se conserva el seguimiento de siempre
        words = set(tokenize(normalize(message)))
        other = words & NOT_A_COURSE_WORDS
        titles = {w for r in focus for w in tokenize(normalize(r.title))}
        return bool(other - titles) and not words & COURSE_WORDS

    @staticmethod
    def _only_asks_dates(attrs: list[str], message: str) -> bool:
        words = set(tokenize(normalize(message)))
        return bool(words) and set(attrs) <= {"duracion", "fechas"} and words <= BARE_DATE_WORDS

    def _unavailable_attribute(
        self, attrs: list[str], u: Understanding, hits: list[Hit], current: Profile, message: str = ""
    ) -> AdvisorReply | None:
        top = hits[0] if hits else None
        if (
            top is not None
            and top.record.status == "verified"
            and top.score >= ANSWER_THRESHOLD
            and all(record_covers_attribute(top.record, a, self.kb) for a in attrs)
        ):
            return None  # la ficha trata ese dato (p. ej. fechas de evaluación en la FAQ)
        attr_terms = {
            t for a in attrs for p in self.kb.intents.attribute_lexicon.get(a, []) for t in content_tokens(p)
        }
        ranked = self.recommender.rank(current, goal="unknown")
        # «¿Cuál es el horario del curso de redes?»: el título publicado nombra el curso aunque la búsqueda priorice la
        # ficha genérica de «sin calendario»; va antes que una coincidencia parcial con otra ficha.
        subject = (ranked[0].record if ranked else None) or (self._course_by_title(message, attr_terms) if message else None)
        if subject is None:
            subject = self._confirmed_subject(hits, attr_terms)
        if subject is not None:
            lines = [self._attribute_line(subject, a, u) for a in u.attributes]
            if any(ok for _, ok in lines):  # responde lo publicado (p. ej. horario) y aclara lo faltante (p. ej. precio)
                return self._followup([subject], u)
        elif (
            self.kb.catalog is not None
            and not self.kb.catalog.catalog.is_expired(datetime.now(UTC))
            and (self._only_asks_dates(attrs, message) or (
                not current.specific_topics(self.generic)
                and (bool(current.topics & {"cursos", "capacitacion", "formacion", "modalidades"})
                     or bool(set(tokenize(normalize(message))) & {"curso", "cursos", "capacitacion", "capacitaciones"}))
            ))
        ):
            courses = [
                r for r in self.kb.records
                if r.source.kind == "catalogo_laravel" and r.status == "verified" and r.kind in {"course", "training_program"}
            ][:5]
            if courses and any(self._attribute_line(c, a, u)[1] for c in courses for a in u.attributes):
                return self._followup(courses, u)
        unavailable = self.kb.unavailable_record(attrs[0])
        if unavailable is None:
            return None
        answer = unavailable.answer
        sources: list[SourceRef] = []
        if subject is not None:
            answer += f"\n\n¿Quieres que te cuente más sobre «{subject.title}»?"
            sources.append(self._source(subject))
        return self._reply(
            answer=answer,
            intent="unavailable_info",
            match_level="media",
            abstained=True,
            sources=sources,
            missing_data=[unavailable.title.lower()],
        )

    # ------------------------------------------------------------------ solicitudes comerciales
    def _private_quote(self) -> AdvisorReply:
        return self._reply(
            answer=(
                "No tengo acceso a cotizaciones emitidas ni a datos de clientes, así que no puedo consultarlas. "
                f"Para revisar una cotización que ya recibiste, comunícate con el asesor que te atendió por WhatsApp: {self.contact.quote_url}."
            ),
            intent="quote_request",
            match_level="ninguna",
            abstained=True,
            missing_data=["las cotizaciones emitidas solo las consulta un asesor autorizado"],
        )

    def _quote(self, profile: Profile, focus: list[KnowledgeRecord], hits: list[Hit]) -> AdvisorReply:
        ranked = (
            self.recommender.rank(profile, goal="unknown") if (profile.topics or profile.objectives) else []
        )
        subject = ranked[0].record if ranked else (focus[0] if focus else None)
        if subject is None and hits and hits[0].record.recommendable and hits[0].score >= ANSWER_THRESHOLD:
            subject = hits[0].record
        rules = self.kb.intents.quote
        if subject is None:
            return self._reply(
                answer=f"{QUOTE_INTRO} Para pedir una cotización, escribe a un asesor por WhatsApp: {self.contact.quote_url}.",
                intent="quote_request",
                match_level="baja",
                needs_clarification=True,
                clarification_question=(
                    "¿Para qué curso o capacitación necesitas la información? Por ejemplo: un curso, "
                    "una capacitación para tu personal o una asesoría."
                ),
                missing_data=["curso o capacitación de interés"],
            )
        checklist = self.recommender.checklist_for(subject, profile)
        answer = (
            f"{QUOTE_INTRO}\nPara pedir una cotización de «{subject.title}», escribe a un asesor por WhatsApp: {self.contact.quote_url}.\n"
            f"{rules.checklist_label}: {'; '.join(checklist)}."
        )
        if subject.public_price:
            answer += f"\nComo referencia, el catálogo publica: {subject.public_price}."
        return self._reply(
            answer=answer,
            intent="quote_request",
            match_level="media",
            sources=[self._source(subject)],
            missing_data=["precio: lo cotiza un asesor", *checklist],
        )

    # ------------------------------------------------------------------ contexto
    def _merge_profile(self, current: Profile, users: list[str]) -> tuple[Profile, bool]:
        profile = Profile(
            **{**current.__dict__, "topics": set(current.topics), "objectives": set(current.objectives)}
        )
        continued = False
        for older_text in reversed(users):
            if has_recommendation_cue(older_text, self.kb) or has_profile_slot(older_text, self.kb):
                profile.merge_from(extract_profile(self.corrected(older_text), self.kb), self.generic)
                continued = continued or has_recommendation_cue(older_text, self.kb)
        return profile, continued

    def _prior(self, state: ConversationState | None) -> _Prior | None:
        """Depura el estado recibido: solo claves del vocabulario y registros publicados verificados."""
        if state is None:
            return None
        rules = self.kb.intents
        profile = None
        if state.profile_age is not None:
            profile = Profile(
                topics={t for t in state.topics if t in rules.topic_lexicon},
                level=state.level if state.level in rules.level_lexicon else "desconocido",
                modality=state.modality if state.modality in rules.modality_lexicon else "cualquiera",
                audience=state.audience if state.audience in rules.audience_lexicon else "desconocido",
                goal=state.goal,
                objectives={o for o in state.objectives if o in rules.objective_lexicon},
            )
        focus: list[KnowledgeRecord] = []
        if state.focus_age is not None:
            focus = [r for r in (self.kb.get(x) for x in state.focus_ids) if r is not None and r.status == "verified"][:2]
        return _Prior(
            profile=profile,
            profile_age=state.profile_age if profile is not None else None,
            continued=state.cue_age is not None,
            cue_age=state.cue_age,
            focus=focus,
            focus_age=state.focus_age if focus else None,
            out_of_scope=state.out_of_scope,
            quote=state.quote,
        )

    def _is_every_course(self, ids: list[str]) -> bool:
        listing = self.kb.get("cat_listado_formacion")
        # También el orden: tras ordenar por duración, «el primero» no es el primero del catálogo.
        return listing is not None and listing.status == "verified" and ids == [
            r.id for r in self.kb.records
            if r.source.kind == "catalogo_laravel" and r.status == "verified" and r.kind in {"course", "training_program"}
        ]

    def _next_state(
        self, message: str, prior: _Prior | None, quote: bool, profile: Profile | None, reply: AdvisorReply
    ) -> ConversationState:
        """Lo que el siguiente turno necesita saber de este, sin texto del usuario."""
        cue = has_recommendation_cue(message, self.kb)
        saved: Profile | None = None
        profile_age: int | None = None
        if profile is not None and (cue or has_profile_slot(message, self.kb)):
            saved, profile_age = profile, 0
        elif prior is not None and prior.profile is not None:
            profile_age = _older(prior.profile_age)
            saved = prior.profile if profile_age is not None else None
        cue_age = 0 if cue else (_older(prior.cue_age) if prior is not None else None)
        ids = [r.id for r in reply.recommendations] or [s.id for s in reply.sources]
        focus_ids = [x for x in ids if (r := self.kb.get(x)) is not None and r.status == "verified"]
        # El estado solo guarda 2: tras una lista con TODOS los cursos, en el orden del catálogo, se recuerda el
        # listado; si no, «¿cuándo empiezan?» perdería los demás.
        if len(focus_ids) > 2 and reply.intent == "followup_attribute" and self._is_every_course(focus_ids):
            focus_ids = ["cat_listado_formacion"]
        focus_ids = focus_ids[:2]
        focus_age: int | None = 0 if focus_ids else None
        # Tras «no tengo ese curso» no se arrastra el anterior: «¿y cuánto dura?» no puede responder por otro.
        if not focus_ids and prior is not None and prior.focus and UNPUBLISHED_COURSE not in reply.answer:
            focus_age = _older(prior.focus_age)
            focus_ids = [r.id for r in prior.focus] if focus_age is not None else []
        return ConversationState(
            topics=sorted(saved.topics)[:8] if saved else [],
            objectives=sorted(saved.objectives)[:8] if saved else [],
            level=saved.level if saved else "desconocido",
            modality=saved.modality if saved else "cualquiera",
            audience=saved.audience if saved else "desconocido",
            goal=saved.goal if saved else "unknown",
            profile_age=profile_age,
            cue_age=cue_age,
            focus_ids=focus_ids,
            focus_age=focus_age,
            out_of_scope=(reply.abstained and not reply.sources and not reply.recommendations
                          and (reply.intent == "out_of_scope" or reply.answer.startswith(OUT_OF_SCOPE_OPENING))),
            quote=quote,
        )

    def _names_antecedent(self, message: str) -> bool:
        """Marcador explícito («eso», «ese curso», «lo mismo»), no un simple «y…» que abre otra pregunta."""
        normalized = normalize(message)
        return any(contains_phrase(normalized, m) for m in self.kb.intents.reference_markers)

    def _previous_out_of_scope(self, users: list[str]) -> bool:
        earlier = [HistoryItem(role="user", content=t) for t in users[:-1]]
        previous = self._respond(users[-1], earlier, depth=1)
        return (previous.abstained and not previous.sources and not previous.recommendations
                and (previous.intent == "out_of_scope" or previous.answer.startswith(OUT_OF_SCOPE_OPENING)))

    def _focus(self, users: list[str]) -> list[KnowledgeRecord]:
        """Opciones a las que remite «eso»: se recalculan ejecutando el motor sobre turnos anteriores."""
        for i in range(len(users) - 1, -1, -1):
            earlier = [HistoryItem(role="user", content=t) for t in users[:i]]
            previous = self._respond(users[i], earlier, depth=1)
            ids = [r.id for r in previous.recommendations] or [s.id for s in previous.sources]
            records = [r for r in (self.kb.get(x) for x in ids) if r is not None and r.status == "verified"]
            if records:
                return records[:2]
        return []

    # ------------------------------------------------------------------ API pública
    def chat(
        self, message: str, history: list[HistoryItem] | None = None, state: ConversationState | None = None
    ) -> AdvisorReply:
        reply = self._chat_reply(message, history, state)
        reply.suggestions = self._without_asked(message, self._suggest(reply))
        return reply

    @staticmethod
    def _without_asked(message: str, suggestions: list[Suggestion]) -> list[Suggestion]:
        """No se vuelve a proponer lo que la persona acaba de preguntar («¿cuál es la modalidad?» → sin «modalidad»)."""
        asked = normalize(message)
        topics: dict[Suggestion, tuple[str, ...]] = {
            "schedule": ("horario", "hora ", "cuando", "inicio", "empieza", "inicia", "fecha"),
            "modality": ("modalidad", "virtual", "presencial", "semipresencial", "hibrido"),
            "compare": ("compar", "diferenci"),
            "price": ("precio", "cuesta", "costo", "cuanto"),
        }
        return [x for x in suggestions if not any(word in f"{asked} " for word in topics.get(x, ()))]

    def _suggest(self, reply: AdvisorReply) -> list[Suggestion]:
        """Siguientes pasos como claves cerradas, según el curso en foco y lo que la respuesta ya dijo.

        Sin curso en foco, en aclaraciones, abstenciones de seguridad o fuera de alcance no se propone nada: la
        interfaz decide entonces con su respaldo. Nunca se proponen datos que la ficha publicada no pueda dar.
        """
        if reply.needs_clarification or reply.intent in {"out_of_scope", "sensitive_request", "human_handoff", "clarification",
                                                         "greeting", "farewell", "thanks", "capabilities"}:
            return []
        ids = reply.state.focus_ids if reply.state is not None else []
        focus = [r for r in (self.kb.get(i) for i in ids) if r is not None and r.status == "verified"]
        if not focus:
            return []
        if reply.intent == "acknowledgement":
            # Solo la cortesía que ya pregunta qué aclarar o qué ver; «no», «jaja» o «perdón» no llevan botones.
            if reply.answer in (*glue.ACK_WITH_FOCUS, *glue.CONFUSED_WITH_FOCUS):
                return ["schedule", "modality", "enrollment"]
            return []
        courses = [r for r in self.kb.records if r.source.kind == "catalogo_laravel" and r.status == "verified"
                   and r.kind in {"course", "training_program"}]
        if len(focus) == 1 and focus[0].id == "cat_listado_formacion":
            return ["compare", "schedule", "enrollment"] if len(courses) >= 2 else ["schedule", "enrollment"]
        if len(focus) == 1 and focus[0] in courses:
            text = normalize(reply.answer)
            out: list[Suggestion] = []
            if "horario" not in text:
                out.append("schedule")
            if "modalidad" not in text:
                out.append("modality")
            out.append("enrollment")
            if len(courses) >= 2:
                out.append("compare")
            return out[:4]
        if len(focus) >= 2 and all(r in courses for r in focus):
            return ["compare", "schedule", "enrollment"]
        return []

    def _chat_reply(
        self, message: str, history: list[HistoryItem] | None, state: ConversationState | None
    ) -> AdvisorReply:
        reply = self._respond(message, history or [], depth=0, state=state)
        institutional = len(reply.sources) == 1 and (record := self.kb.get(reply.sources[0].id)) is not None and record.general_information and record.kind == "info"
        if self.gemini is None or reply.abstained or reply.recommendations or institutional or any(s.id == "convenios_resumen" for s in reply.sources):
            return self._requested_detail(message, reply)
        if self._safety(message) is not None or self._rules(message) is not None:
            return self._requested_detail(message, reply)
        understanding = understand(self.corrected(message), self.kb)
        if understanding.attributes or understanding.quote or understanding.referential or history or state:
            return self._requested_detail(message, reply)
        hits = self.retrieve(message, limit=12)
        records = [h.record for h in hits if h.record.status == "verified" and h.score >= CLARIFY_THRESHOLD]
        rid = self.gemini.select(message, records)
        record = next((r for r in records if r.id == rid), None)
        if record is None:
            return self._requested_detail(message, reply)
        selected = self._reply(
            answer=record.answer,
            intent=CATEGORY_INTENTS[record.category],
            match_level="media",
            sources=[self._source(record)],
            context=reply.context,
        )
        if reply.state is not None:
            selected.state = reply.state.model_copy(update={"focus_ids": [record.id], "focus_age": 0})
        return self._requested_detail(message, selected)

    def _requested_detail(self, message: str, reply: AdvisorReply) -> AdvisorReply:
        """Responde con el fragmento necesario; amplía a petición explícita desde la misma fuente."""
        if reply.abstained or reply.needs_clarification or len(reply.sources) != 1:
            return reply
        record = self.kb.get(reply.sources[0].id)
        if record is None or record.status != "verified" or not record.detail_answer:
            return reply
        if any(contains_phrase(normalize(message), cue) for cue in (
            "detalles", "mas detalles", "explicame", "que incluye", "en que consiste", "informacion detallada", "informacion completa",
            "compara", "comparar", "comparame", "diferencia", "diferencias", "resumen de los cursos",
        )):
            document = self.kb.documents_by_source.get(record.id)
            if document is not None and document.fragments:
                if reply.answer in {f.answer for f in document.fragments} and document.fragments[0].answer != record.answer:
                    return reply
                if document.fragments[0].answer != record.answer and not document.fragments[0].text.lower().startswith("modalidad:"):
                    return reply.model_copy(update={"answer": document.fragments[0].answer})
            return reply.model_copy(update={"answer": record.detail_answer})
        if reply.answer != record.answer or record.stable_id is not None or record.id == "cat_listado_formacion":
            return reply
        specific = set(content_tokens(message)) - set(content_tokens(record.title + " " + record.answer))
        sentences = [s.strip() for s in re.split(r"(?<=[.;])\s+", record.detail_answer) if 10 <= len(s.strip()) <= 600]
        # «Más información en la página…» solo remite a otra parte: como respuesta única no dice nada.
        sentences = [s for s in sentences if not s.startswith(("Más información", "Puedes conocer más"))]
        best = max(sentences, key=lambda s: len(specific & set(content_tokens(s))), default="")
        if best and specific & set(content_tokens(best)):
            return reply.model_copy(update={"answer": best})
        return reply

    def _respond(
        self, message: str, history: list[HistoryItem], depth: int, state: ConversationState | None = None
    ) -> AdvisorReply:
        users = [h.content for h in history if h.role == "user"][-MAX_CONTEXT_TURNS:]
        # El historial, si llega, manda; el estado estructurado lo sustituye cuando el cliente no guarda texto.
        prior = self._prior(state) if depth == 0 and not users else None
        message = glue.strip_filler(message)  # «hola pe», «oe causa qué cursos hay»
        early = self._safety(message) or self._about(message) or self._rules(message)
        if early is None:
            # También en los turnos que se reconstruyen desde el historial: así «el primero» deja el mismo foco
            # con el historial que con el estado.
            early, message = self._glue(message, users, prior)
        if early is None and not content_tokens(message):
            early = self._clarify([])
        if early is not None:
            if depth == 0:
                early.state = self._next_state(message, prior, False, None, early)
            return early

        corrected = self.corrected(message)
        u = understand(corrected, self.kb)  # sobre el texto corregido: "cotisasion" -> "cotizacion"
        if u.topic_change:
            users = []  # "ahora otra cosa…": el contexto anterior se descarta
            prior = None
        has_context = bool(users) or prior is not None
        if not has_context:
            # Sin conversación previa a la que remitir: responder por una palabra suelta sería adivinar.
            tokens = set(content_tokens(message))
            if (tokens & HELP_REQUEST and tokens <= HELP_REQUEST | COURTESY_FILLER) or (
                    self._names_antecedent(message) and tokens <= GENERIC_REQUEST | COURTESY_FILLER):
                reply = self._clarify([])
                if depth == 0:
                    reply.state = self._next_state(message, None, u.quote, None, reply)
                return reply
        current = extract_profile(corrected, self.kb)
        if prior is not None:
            profile = Profile(**{**current.__dict__, "topics": set(current.topics), "objectives": set(current.objectives)})
            if prior.profile is not None:
                profile.merge_from(prior.profile, self.generic)
            continued = prior.continued
        else:
            profile, continued = self._merge_profile(current, users)
        current_specific = bool(current.specific_topics(self.generic))
        wants_detail_followup = len(tokenize(message)) <= 8 and any(
            contains_phrase(normalize(message), cue)
            for cue in ("detalles", "mas detalles", "dame detalles", "dame mas detalles", "de que trata", "de que tratan", "en que consiste", "que incluye")
        )
        focus: list[KnowledgeRecord] = []
        words = set(tokenize(normalize(corrected)))
        if (depth == 0 and has_context and not u.attributes and words & {"horario", "horarios"}
                and words <= {"y", "el", "su", "cual", "es", "que", "horario", "horarios", "de", "los", "sus", "son"}):
            # «horario» u «orario» solo, con un curso en foco: el horario de ese curso (no el de atención).
            prior_focus = list(prior.focus) if prior is not None else self._focus(users)
            if any(r.stable_id is not None or r.id == "cat_listado_formacion" for r in prior_focus):
                u = replace(u, attributes=("fechas",))
        if depth == 0 and has_context and not current_specific and (u.attributes or u.quote or u.referential or wants_detail_followup):
            focus = list(prior.focus) if prior is not None else self._focus(users)
            # «¿Cuál es el horario del curso de comunicación?»: si el mensaje nombra otro curso publicado, manda ese
            # curso, no el tema del turno anterior.
            named = self._course_by_title(corrected, set()) if u.attributes else None
            if named is not None and all(r.id != named.id for r in focus):
                focus = [named]
            # Fechas, duración, precio, cupos, requisitos o temario son datos de un curso: una ficha institucional en
            # foco (p. ej. el horario de atención) no se presenta como «curso» con esos datos.
            if set(u.attributes) & COURSE_ONLY_ATTRIBUTES and not u.quote:
                focus = [r for r in focus if r.stable_id is not None or r.id == "cat_listado_formacion"]
        if prior is not None:
            previous_quote = prior.quote
        else:
            previous_quote = bool(users) and understand(self.corrected(users[-1]), self.kb).quote
        if (depth == 0 and has_context and not focus and not current_specific and self._names_antecedent(message)
                and (prior.out_of_scope if prior is not None else self._previous_out_of_scope(users))):
            # «¿Y eso lo hacen para casas?» tras un tema fuera de alcance: no se responde por palabras sueltas.
            reply = self._out_of_scope()
        else:
            shortcut = self._course_shortcuts(message, users, prior) if depth == 0 else None
            reply = shortcut or self._decide(message, corrected, u, current, profile, continued, focus, previous_quote)
            if depth == 0 and not focus and shortcut is None:
                reply = self._several_questions(message, reply)
        # «¿Tienen el curso de contabilidad?»: antes de pedir una aclaración se dice que ese curso no está.
        if depth == 0 and reply.intent == "clarification" and self._names_unpublished_course(message):
            reply = self._unpublished_topic("learn", message)
        if depth == 0:
            reply.state = self._next_state(message, prior, u.quote, profile, reply)
            reply.context = ConversationContext(
                turns_considered=len(users) if prior is None else 1,
                topics=sorted(profile.topics),
                level=None if profile.level == "desconocido" else profile.level,
                modality=None if profile.modality == "cualquiera" else profile.modality,
                focus_ids=(reply.context.focus_ids if reply.needs_clarification and reply.context else [r.id for r in focus]),
                topic_changed=u.topic_change,
                referential=u.referential,
            )
        return reply

    def _verified_blocks(self, reply: AdvisorReply) -> list[str] | None:
        """Bloques de una respuesta que son, cada uno, el texto exacto de una ficha verificada citada (lo que Laravel
        acepta como contenido institucional sin reescribir). None si alguna parte no lo es."""
        if reply.abstained or reply.needs_clarification or not reply.sources or reply.recommendations:
            return None
        if reply.intent not in INFO_INTENTS:
            return None
        texts: set[str] = set()
        for source in reply.sources:
            record = self.kb.get(source.id)
            if record is None or record.status != "verified" or record.source.kind == "catalogo_laravel":
                return None
            for text in (record.answer, record.detail_answer):
                if not text:
                    continue
                texts.add(text)
                # Frases sueltas del texto verificado: Laravel las acepta con el mismo corte (10 a 600 caracteres).
                texts.update(s.strip() for s in re.split(r"(?<=[.;])\s+", text) if 10 <= len(s.strip()) <= 600)
        blocks = reply.answer.split("\n\n")
        return blocks if all(b in texts for b in blocks) else None

    def _several_questions(self, message: str, reply: AdvisorReply) -> AdvisorReply:
        """«¿Dónde están y cómo pago?»: cada pregunta se resuelve por separado y se unen las respuestas verificadas.

        Solo se combinan textos exactos de fichas verificadas (hasta tres bloques). Si alguna parte no tiene respuesta
        verificada (p. ej. el precio), se conserva la respuesta original: nunca se inventa la parte que falta.
        """
        if reply.intent not in INFO_INTENTS | {"clarification", "out_of_scope", "unavailable_info"}:
            return reply  # seguimiento de cursos, matrícula, recomendaciones: ya tienen su propio camino
        parts = [p.strip(" ,.") for p in SEVERAL_QUESTIONS.split(message)]
        parts = [p for p in parts if p and content_tokens(p)]
        if not 2 <= len(parts) <= 3:
            return reply
        blocks: list[str] = []
        sources: list[SourceRef] = []
        for part in parts:
            partial = self._respond(part, [], depth=1)
            found = self._verified_blocks(partial)
            if found is None:
                return reply
            for block in found:
                if block not in blocks:
                    blocks.append(block)
            for source in partial.sources:
                if all(s.id != source.id for s in sources):
                    sources.append(source)
        if len(blocks) < 2 or len(blocks) > 3 or len(sources) <= len(reply.sources):
            return reply
        first = self.kb.get(sources[0].id)
        assert first is not None
        return self._reply(answer="\n\n".join(blocks), intent=CATEGORY_INTENTS[first.category], match_level="media",
                           sources=sources)

    def _decide(
        self,
        message: str,
        corrected: str,
        u: Understanding,
        current: Profile,
        profile: Profile,
        continued: bool,
        focus: list[KnowledgeRecord],
        previous_quote: bool,
    ) -> AdvisorReply:
        plan = plan_query(corrected) if self.kb.catalog is not None else None
        planned_search = bool(plan and plan.recommend and plan.subject and not u.attributes and not u.quote)
        # El tema se busca solo con palabras que algún contenido indexado usa: el relleno conversacional
        # («relacionadas», «que me sirva», «me lo piden») no diluye la cobertura ni inventa un tema.
        topic = self._published_terms(plan.subject, corrected) if plan else ""
        unpublished = bool(planned_search and plan and content_tokens(plan.subject) and not content_tokens(topic))
        hits = self.retrieve(topic if planned_search and topic else message)
        terms = set(content_tokens(corrected))

        if terms & {"convenio", "convenios", "alianza", "alianzas"}:
            generic = {"convenio", "convenios", "alianza", "alianzas", "institucion", "instituciones",
                "colegio", "colegios", "profesionales", "horus", "group", "tienen", "tiene", "cuentan",
                "trabajan", "cuales", "cuantos", "actualmente", "lista", "listado", "son", "hay", "estrategicos",
                "interinstitucionales", "informacion", "saber", "con", "quien", "quienes"}
            listing = self.kb.get("convenios_resumen")
            if listing is not None and listing.status == "verified" and not terms - generic:
                return self._reply(answer=listing.answer, intent="agreement_info", match_level="alta",
                    sources=[self._source(listing)])

        if hits and hits[0].record.status == "verified" and hits[0].record.public_card is not None:
            record = hits[0].record
            if hits[0].score >= ANSWER_THRESHOLD and terms & {"vigencia", "vence", "vencimiento", "vencen"}:
                return self._reply(answer=f"{record.title}: el convenio está vigente hasta el {record.public_card.vigencia}.",
                    intent="agreement_info", match_level="alta", sources=[self._source(record)])

        # Pide un dato de un curso que no existe: no se contesta con el del curso en foco ni con los de todos.
        if (u.attributes or u.asked_modalities) and not u.private_quote and self._names_unpublished_course(message):
            return self._unpublished_topic("learn", message)

        # 2. Solicitudes comerciales.
        if u.private_quote:
            return self._private_quote()
        if u.quote or (previous_quote and (current.topics or current.objectives)):
            quote_profile = current if (current.topics or current.objectives) else profile
            return self._quote(quote_profile, focus, hits)

        # 3. Seguimiento sobre la opción anterior.
        names_other = bool(focus) and self._names_other_record(focus, hits)
        if focus and self._asks_about_something_else(focus, corrected):
            names_other = True
        if focus and u.attributes and (u.referential or u.short) and not names_other:
            return self._followup(focus, u)
        if (
            focus
            and not u.attributes
            and not u.quote
            and not names_other
            and not (plan and plan.subject)
            and len(tokenize(message)) <= 8
            and any(
                contains_phrase(normalize(message), cue)
                for cue in ("detalles", "mas detalles", "dame detalles", "dame mas detalles", "de que trata", "de que tratan", "en que consiste", "que incluye")
            )
        ):
            rec = focus[0]
            return self._reply(
                answer=rec.detail_answer or rec.answer,
                intent=CATEGORY_INTENTS[rec.category],
                match_level="media",
                sources=[self._source(rec)],
            )

        # 4. Atributo no publicado sobre un tema concreto.
        unavailable = [a for a in u.attributes if a in self.kb.intents.unavailable_attributes]
        if unavailable:
            if (
                unavailable == ["contenido"]
                and hits
                and hits[0].record.stable_id is not None
                and hits[0].record.status == "verified"
                and hits[0].score >= ANSWER_THRESHOLD
            ):
                # «¿Qué contenidos tiene el curso X?»: el fragmento publicado del temario, si existe.
                line, published = self._attribute_line(hits[0].record, "contenido", u)
                if published:
                    return self._reply(answer=line, intent=CATEGORY_INTENTS[hits[0].record.category],
                        match_level=_retrieval_level(hits[0].score), sources=[self._source(hits[0].record)])
                return self._answer_from_hits(hits, terms)
            reply = self._unavailable_attribute(unavailable, u, hits, current, corrected)
            if reply is not None:
                return reply

        # 5. Recomendación o búsqueda.
        information = self._institutional_information(hits, plan.goal if plan else "unknown")
        if information is not None and not u.attributes and not (plan and (plan.enrollment or plan.browse or plan.ambiguous)):
            return information
        if (u.attributes and set(u.attributes) != {"audiencia"} and hits
            and hits[0].record.stable_id is not None and hits[0].record.status == "verified"
            and hits[0].score >= ANSWER_THRESHOLD and not (plan and plan.browse)
            and (len(hits) == 1 or hits[1].record.stable_id is None or hits[0].score - hits[1].score >= AMBIGUITY_MARGIN)):
            return self._followup([hits[0].record], u)
        if (
            u.attributes
            and set(u.attributes) != {"audiencia"}
            and self.kb.catalog is not None
            and not self.kb.catalog.catalog.is_expired(datetime.now(UTC))
            and not (current.specific_topics(self.generic) - {"modalidades", "horario", "fechas", "certificacion", "precios"})
            and not (hits and hits[0].record.stable_id is not None and hits[0].score >= ANSWER_THRESHOLD)
            and (
                bool(current.topics & {"cursos", "capacitacion", "formacion"})
                or any(w in normalize(message).split() for w in ("curso", "cursos", "capacitacion", "capacitaciones"))
            )
        ):
            courses = [
                r for r in self.kb.records
                if r.source.kind == "catalogo_laravel" and r.status == "verified" and r.kind in {"course", "training_program"}
            ][:5]
            if courses and any(self._attribute_line(c, a, u)[1] for c in courses for a in u.attributes):
                return self._followup(courses, u)
        if plan is not None:
            course_hit = bool(hits and hits[0].record.source.kind == "catalogo_laravel"
                              and hits[0].record.status == "verified" and hits[0].score >= ANSWER_THRESHOLD)
            steps = self.kb.get("proceso_matricula_pasos")
            if (plan.enrollment and not course_hit and steps is not None and steps.status == "verified"
                    and normalize(message) == normalize(glue.ENROLLMENT_QUERY)):
                # «¿Cómo puedo matricularme?» sin curso (botón del widget): los pasos verificados, no solo «escribe al
                # WhatsApp». Con catálogo cargado el plan de matrícula ganaba a la ficha (producción, 25/11/2026).
                return self._reply(answer=steps.answer, intent=CATEGORY_INTENTS[steps.category], match_level="alta",
                                   sources=[self._source(steps)])
            if plan.enrollment:
                contact = self.kb.get(self.kb.intents.human_handoff.record_id)
                sources = [self._source(contact)] if contact else []
                prefix = ""
                if (
                    hits
                    and hits[0].record.source.kind == "catalogo_laravel"
                    and hits[0].record.status == "verified"
                    and hits[0].score >= ANSWER_THRESHOLD
                ):
                    rec = hits[0].record
                    sources.insert(0, self._source(rec))
                    detalles = []
                    if rec.modalities:
                        detalles.append("modalidad " + ", ".join(MODALITY_LABEL[m] for m in rec.modalities))
                    if rec.schedule:
                        detalles.append(f"horario {rec.schedule}")
                    extra = f" ({'; '.join(detalles)})" if detalles else ""
                    prefix = f"Para el curso «{rec.title}»{extra}: "
                return self._reply(
                    answer=(f"{prefix}Para consultar la matrícula, continúa por el WhatsApp oficial de Horus Group: "
                            f"{self.contact.enrollment_url}. No compartas datos personales en este chat."),
                    intent="enrollment_request", match_level="alta",
                    sources=sources,
                )
            if plan.ambiguous:
                return self._clarify_goal()
            if plan.browse:
                if plan.goal == "learn" and self.kb.catalog is not None and not self.kb.catalog.catalog.is_expired(datetime.now(UTC)):
                    catalog_reply = self._browse_courses_reply(current.modality, recommend=False)
                    if catalog_reply is not None:
                        return catalog_reply
                if plan.goal != "learn":
                    return self._out_of_scope()
                listing = self.kb.get("cat_listado_formacion")
                expired = self.kb.catalog.catalog.is_expired(datetime.now(UTC))
                if listing is not None and not expired:
                    return self._reply(answer=listing.answer, intent="training_info",
                        match_level="alta", sources=[self._source(listing)])
                if expired:
                    # Los cursos existen pero su ficha no está revalidada: no decir que no hay ninguno.
                    return self._reply(answer=self._catalog_refreshing(), intent="unavailable_info",
                        match_level="ninguna", abstained=True)
                return self._reply(answer="Por ahora no hay cursos vigentes.",
                    intent="unavailable_info", match_level="ninguna", abstained=True)
            if plan.recommend:
                if not plan.subject:
                    if plan.goal == "learn" and self.kb.catalog is not None and not self.kb.catalog.catalog.is_expired(datetime.now(UTC)):
                        catalog_reply = self._browse_courses_reply(current.modality, recommend=True)
                        if catalog_reply is not None:
                            return catalog_reply
                    return self._clarify_goal()
                if unpublished:
                    return self._unpublished_topic(plan.goal, corrected)
                current.goal = plan.goal
                relevant = hits if planned_search else self.retrieve(topic or plan.subject)
                if (plan.goal == "learn" and any(h.record.general_information and h.score >= ANSWER_THRESHOLD for h in relevant)
                    and not any(h.record.source.kind == "catalogo_laravel" and h.record.kind == "course"
                        and h.record.status == "verified" and h.score >= ANSWER_THRESHOLD for h in relevant)):
                    return self._unpublished_topic("learn", corrected)
                return (self._multi_recommend(corrected)
                        or self._catalog_recommend(relevant, current, by_title=plan.goal == "learn" and not u.attributes)
                        or self._clarify(relevant))

        cue = has_recommendation_cue(corrected, self.kb)
        follow_up = (
            not cue and continued and has_profile_slot(corrected, self.kb) and len(tokenize(message)) <= 14
        )
        if cue or follow_up:
            if self.kb.catalog is not None:
                if hits and hits[0].record.id == "cat_listado_formacion":
                    return self._answer_from_hits(hits, terms)
                return self._multi_recommend(corrected) or self._catalog_recommend(hits, current) or self._clarify([])
            top = hits[0] if hits else None
            wants_topic = bool(self.recommender.wanted_topics(profile))
            # «¿Dan comprobante a nombre de mi empresa?»: «mi empresa» no convierte una pregunta frecuente
            # inequívoca (frase exacta y margen claro) en un pedido de recomendación.
            clear_faq = (top is not None and top.phrase_hit and top.record.status == "verified"
                and not top.record.recommendable and top.score >= NEAR_SURE_MIN and not wants_topic
                and (len(hits) == 1 or top.score - hits[1].score >= CLEAR_MARGIN)
                and bool(terms) and len(set(top.matched) & terms) >= len(terms) / 2)
            if clear_faq:
                return self._answer_from_hits(hits, terms)
            if top is not None and top.score >= ANSWER_THRESHOLD:
                if top.record.status != "verified" and top.phrase_hit:
                    return self._abstain_with_context(top, hits)
                if top.record.status == "verified" and not top.record.recommendable and not wants_topic:
                    return self._answer_from_hits(hits, terms)
            return self.recommender.recommend(profile, self.mode)

        if u.attributes:
            evidenced = self._evidenced_hit(hits, u)
            if evidenced is not None:
                return evidenced
        reply = self._answer_from_hits(hits, terms)
        if reply.intent == "out_of_scope" and current.goal == "learn" and not focus:
            # «Nunca he estudiado nada técnico, ¿por dónde empiezo?»: quiere aprender; se pregunta el área.
            return self.recommender.recommend(profile, self.mode)
        if focus and u.referential and (reply.needs_clarification or reply.intent == "out_of_scope"):
            if names_other:  # «¿y entregan certificado?» tras los convenios: responde el registro nombrado
                named = hits[0].record
                return self._reply(answer=named.answer, intent=CATEGORY_INTENTS[named.category],
                    match_level="media", sources=[self._source(named)])
            return self._focus_reference(focus)  # "¿y eso qué incluye?"
        return reply

    def _institutional_information(self, hits: list[Hit], goal: str) -> AdvisorReply | None:
        """Explicación pública sin anunciar ofertas, disponibilidad ni recomendaciones retiradas."""
        if goal == "learn" or self.kb.catalog is None:
            return None
        if any(h.record.source.kind == "catalogo_laravel" and h.record.status == "verified"
            and h.score >= ANSWER_THRESHOLD for h in hits):
            return None
        candidates = [h for h in hits if h.record.general_information and h.record.kind == "info"
            and h.record.status == "verified" and h.score >= ANSWER_THRESHOLD]
        if not candidates:
            return None
        first = candidates[0]
        if len(candidates) > 1 and first.score - candidates[1].score < AMBIGUITY_MARGIN:
            return None
        return self._reply(answer=first.record.answer, intent="service_info", match_level=_retrieval_level(first.score),
            sources=[self._source(first.record)])

    def recommend(self, request: RecommendRequest) -> AdvisorReply:
        text = " ".join([*request.interests, request.goal or ""]).strip()
        early = self._safety(text) if text else None
        if early is not None:
            return early
        profile = extract_profile(self.corrected(text), self.kb) if text else Profile()
        if request.level != "desconocido":
            profile.level = request.level
        if request.modality != "cualquiera":
            profile.modality = request.modality
        if request.audience != "desconocido":
            profile.audience = request.audience
        if profile.goal == "unknown" and profile.audience == "empresa":
            profile.goal = "service"
        if self.kb.catalog is not None:
            plan = plan_query(self.corrected(text))
            if plan.ambiguous:
                return self._clarify_goal()
            if plan.goal != "unknown":
                profile.goal = plan.goal
            return self._catalog_recommend(self.retrieve(plan.subject or text), profile) or self._clarify([])
        return self.recommender.recommend(profile, self.mode)

    def _published_terms(self, subject: str, text: str | None = None) -> str:
        """Palabras del tema que algún contenido indexado usa, en el orden del texto original.

        Las palabras funcionales entre dos palabras conservadas se mantienen para que una frase clave
        publicada («hojas de cálculo») siga reconociéndose; el resto del relleno se descarta.
        """
        wanted = set(subject.split())
        tokens = tokenize(text) if text is not None else subject.split()
        known = [t in wanted and bool(content_tokens(t)) and self.lexical.knows(content_tokens(t)[0]) for t in tokens]
        kept: list[str] = []
        for i, token in enumerate(tokens):
            if known[i]:
                kept.append(token)
            elif not content_tokens(token) and kept and kept[-1] == tokens[i - 1] and any(
                known[j] for j in range(i + 1, min(i + 3, len(tokens)))
            ):
                kept.append(token)  # «de» en «hojas de cálculo»
        return " ".join(kept)

    def _unpublished_topic(self, goal: str, text: str) -> AdvisorReply:
        """Tema sin ningún contenido publicado: se reconoce sin inventar ni repetir lo escrito."""
        if goal == "service":
            return self._out_of_scope()
        kind = {"learn": "un curso o capacitación"}.get(goal, "información")
        listing = "¿Qué cursos ofrecen?"
        openings = (
            f"No encontré {kind} sobre ese tema.",
            f"Por ahora no tengo {kind} sobre ese tema.",
        )
        opening = openings[sum(map(ord, text)) % len(openings)]  # determinista: misma consulta, misma respuesta
        return self._reply(
            answer=(f"{opening} Pregúntame «{listing}» y te cuento qué hay. "
                    "También puedes consultarlo con un asesor."),
            intent="unavailable_info",
            match_level="ninguna",
            abstained=True,
        )

    def _multi_recommend(self, text: str) -> AdvisorReply | None:
        """Consulta con varios temas («Excel y también cableado»): una opción publicada por tema, sin mezclar fuentes."""
        parts = re.split(r"\b(?:y|e|tambien|ademas|asi como|junto con)\b|[,;]", normalize(text))
        segments = [s for s in parts if content_tokens(s)]
        if len(segments) < 2:
            return None
        chosen: list[Hit] = []
        undecided: list[list[Hit]] = []
        for segment in segments[:3]:
            plan = plan_query(segment)
            terms = self._published_terms(plan.subject, segment)
            if not content_tokens(terms):
                continue
            options = [
                h for h in self.retrieve(terms)
                if h.record.source.kind == "catalogo_laravel" and h.record.status == "verified"
                and h.record.kind in {"course", "service"} and h.score >= ANSWER_THRESHOLD
                and not (plan.goal == "learn" and h.record.kind != "course")
                and not (plan.goal == "service" and h.record.kind != "service")
            ]
            if not options:
                continue
            if (len(options) > 1 and options[0].score - options[1].score < AMBIGUITY_MARGIN
                    and not set(content_tokens(options[0].record.title)) <= set(content_tokens(terms))):
                undecided.append([h for h in options if options[0].score - h.score < AMBIGUITY_MARGIN])
            elif all(c.record.id != options[0].record.id for c in chosen):
                chosen.append(options[0])
        ids = {c.record.id for c in chosen}
        # Una parte ambigua que ya cubre otra parte («limpieza de datos y gráficos») no es un tema distinto.
        pending = [opts for opts in undecided if not ids & {h.record.id for h in opts}]
        if pending and chosen:
            return self._clarify(pending[0])  # dos opciones parecidas para un tema: se pregunta, no se elige al azar
        if len(chosen) < 2:
            return None
        first = chosen[0]
        titles = [f"«{h.record.title}»" for h in chosen[1:]]
        others = titles[0] if len(titles) == 1 else f"{', '.join(titles[:-1])} y {titles[-1]}"
        return self._reply(
            answer=first.fragment.answer if first.fragment else first.record.answer,
            intent="course_recommendation" if first.record.kind == "course" else "service_recommendation",
            match_level=_retrieval_level(min(h.score for h in chosen)),
            sources=[self._source(h.record) for h in chosen],
            recommendations=[self._recommendation(h) for h in chosen],
            needs_clarification=True,
            clarification_question=f"Para tu otra consulta también tengo {others}. ¿Sobre cuál quieres más detalles?",
        )

    def _recommendation(self, hit: Hit) -> Recommendation:
        record = hit.record
        evidence = hit.fragment.text if hit.fragment else self.kb.documents_by_source[record.id].description
        action = "cotización" if record.kind == "service" else "matrícula"
        url = self.contact.quote_url if record.kind == "service" else self.contact.enrollment_url
        return Recommendation(id=record.id, title=record.title, kind=record.kind,
            reason=f"Lo que dice el contenido sobre tu consulta: «{evidence}».", evidence=[record.id],
            evidence_text=evidence,
            next_step=f"Para consultar la {action}, continúa por el WhatsApp oficial: {url}.",
            match_level=_retrieval_level(hit.score), url_path=record.source.url_path)

    def _browse_courses_reply(self, modality: str, recommend: bool) -> AdvisorReply | None:
        courses = [
            r for r in self.kb.records
            if r.source.kind == "catalogo_laravel" and r.status == "verified" and r.kind in {"course", "training_program"}
        ]
        if not courses:
            return None
        if modality != "cualquiera":
            matches = [
                r for r in courses
                if modality in {"virtual" if m == "online" else m for m in r.modalities}
            ]
            if len(matches) == 1 and recommend and matches[0].kind == "course":
                rec = matches[0]
                return self._reply(
                    answer=rec.answer,
                    intent="course_recommendation",
                    match_level="alta",
                    sources=[self._source(rec)],
                    recommendations=[self._recommendation(Hit(record=rec, score=1.0))],
                )
            if matches:
                titles = ", ".join(r.title for r in matches[:8])
                return self._reply(
                    answer=(f"En modalidad {MODALITY_LABEL[modality]} están vigentes: {titles}. "
                            "Dime cuál te interesa y te cuento su horario y detalles."),
                    intent="training_info",
                    match_level="alta",
                    sources=[self._source(r) for r in matches[:5]],
                )
            other = ", ".join(
                f"{r.title} ({', '.join(MODALITY_LABEL[m] for m in r.modalities)})" if r.modalities else r.title
                for r in courses[:6]
            )
            return self._reply(
                answer=f"Por ahora no hay cursos en modalidad {MODALITY_LABEL[modality]}. Sí tenemos: {other}.",
                intent="training_info",
                match_level="media",
                sources=[self._source(r) for r in courses[:5]],
            )
        if recommend and len(courses) == 1 and courses[0].kind == "course":
            rec = courses[0]
            return self._reply(
                answer=rec.answer,
                intent="course_recommendation",
                match_level="alta",
                sources=[self._source(rec)],
                recommendations=[self._recommendation(Hit(record=rec, score=1.0))],
            )
        listing = self.kb.get("cat_listado_formacion")
        if listing is None:
            return None
        return self._reply(
            answer=listing.answer,
            intent="training_info",
            match_level="alta",
            sources=[self._source(listing)],
        )

    def _clarify_goal(self) -> AdvisorReply:
        return self._reply(answer="Puedo ayudarte con información de cursos, capacitaciones y atención al cliente de Horus Group.",
            intent="clarification", match_level="baja", needs_clarification=True,
            clarification_question="¿Buscas un curso o capacitación? ¿Sobre qué tema?")

    def _out_of_scope(self) -> AdvisorReply:
        """Servicios tecnológicos y similares: el asistente solo atiende educación y atención al cliente."""
        return self._reply(
            answer=(f"{OUT_OF_SCOPE_OPENING} puede orientarte sobre cursos y capacitaciones, asesoramiento, "
                    "convenios, pagos y atención al cliente de Horus Group. Para otras consultas, comunícate con un asesor: "
                    f"{self.contact.phone} o {self.contact.email}."),
            intent="unavailable_info", match_level="ninguna", abstained=True)

    def _catalog_refreshing(self) -> str:
        """Catálogo caducado: las fichas existen pero no están revalidadas; se deriva sin negar que haya cursos."""
        return ("La lista de cursos se está actualizando en este momento, así que no puedo confirmarte cuáles están abiertos. "
                f"Un asesor puede indicarte la oferta actual al {self.contact.phone} o en {self.contact.email}.")

    def _catalog_recommend(self, hits: list[Hit], profile: Profile, by_title: bool = False) -> AdvisorReply | None:
        candidates = []
        for hit in hits:
            record = hit.record
            if record.source.kind != "catalogo_laravel" or record.status != "verified" or record.kind not in {"course", "service"} or hit.score < ANSWER_THRESHOLD:
                continue
            if profile.goal == "learn" and record.kind != "course" or profile.goal == "service" and record.kind != "service":
                continue
            if profile.modality != "cualquiera" and profile.modality not in record.modalities:
                continue
            candidates.append(hit)
        if (len(candidates) > 1 and candidates[0].score - candidates[1].score < AMBIGUITY_MARGIN
            and not set(content_tokens(candidates[0].record.title)) <= set(content_tokens(profile.text))):
            return None
        title_match = False
        if not candidates and by_title:
            # «Quiero aprender redes desde cero»: el título de un curso publicado coincide aunque la puntuación léxica
            # sea baja; se recomienda ese curso (único) con nivel medio, nunca uno inventado.
            named = self._course_by_title(profile.text, set())
            candidates = [h for h in hits if named is not None and h.record.id == named.id and named.kind == "course"][:1]
            if candidates and (profile.modality != "cualquiera" and profile.modality not in candidates[0].record.modalities):
                candidates = []
            title_match = bool(candidates)
        if not candidates:
            return None
        hit = candidates[0]
        record = hit.record
        # Otras fichas publicadas que también cubren lo pedido (hasta dos), cada una con su motivo y enlace.
        others = [h for h in candidates[1:] if h.record.kind == record.kind]
        others = [h for i, h in enumerate(others) if all(o.record.id != h.record.id for o in others[:i])][:2]
        chosen = [hit, *others]
        question = None
        if others:
            titles = [f"«{h.record.title}»" for h in others]
            question = (f"También coincide{'n' if len(titles) > 1 else ''} con lo que buscas: {' y '.join(titles)}. "
                        "¿Quieres que te cuente de alguno?")
        return self._reply(answer=hit.fragment.answer if hit.fragment else record.answer,
            intent="course_recommendation" if record.kind == "course" else "service_recommendation",
            match_level="media" if title_match else _retrieval_level(hit.score), sources=[self._source(h.record) for h in chosen],
            recommendations=[self._recommendation(h) for h in chosen],
            needs_clarification=bool(others), clarification_question=question)
