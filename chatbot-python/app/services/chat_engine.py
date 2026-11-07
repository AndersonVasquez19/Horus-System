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
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Literal

from app.core.security import detect_prompt_injection, detect_sensitive_request
from app.core.text import contains_phrase, content_tokens, correct_tokens, normalize, tokenize
from app.schemas.chat import (
    AdvisorReply,
    ConversationContext,
    HistoryItem,
    MatchLevel,
    Recommendation,
    RecommendRequest,
    SourceRef,
)
from app.schemas.knowledge import KnowledgeRecord
from app.services.catalog_documents import PublicFragment
from app.services.gemini import GeminiSelector
from app.services.knowledge import KnowledgeBase
from app.services.lexical_search import LexicalIndex
from app.services.query_plan import QUERY_WORDS, plan_query
from app.services.recommendations import (
    AUDIENCE_LABEL,
    CONTACT_LINE,
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
SEMANTIC_WEIGHT = 0.5
# Contra falsos positivos semánticos: sin apoyo léxico, la similitud solo cuenta si es alta.
SEMANTIC_MIN_LEXICAL = 0.15
SEMANTIC_ONLY_MIN_COSINE = 0.72
MAX_CONTEXT_TURNS = 3
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

FALLBACK_TOPICS = (
    "servicios tecnológicos (cámaras, cableado y redes, soporte y mantenimiento), cursos y capacitaciones, "
    "asesoramiento, convenios, pagos y contacto"
)
QUOTE_INTRO = (
    "Las cotizaciones las prepara un asesor de Horus Group: este asistente no calcula precios ni consulta "
    "cotizaciones emitidas."
)
WHATSAPP_QUOTE_URL = "https://wa.me/51927582305?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n"
WHATSAPP_MATRICULA_URL = (
    "https://wa.me/51927582305?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula"
)
CONTACT_CHANNELS = "+51 927 582 305 (teléfono o WhatsApp) o horusgroupcajamarca@gmail.com"


@dataclass(frozen=True)
class Hit:
    record: KnowledgeRecord
    score: float
    lexical: float
    semantic: float | None
    phrase_hit: bool
    matched: tuple[str, ...]
    fragment: PublicFragment | None = None


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
        hits.sort(key=lambda h: (-h.score, h.record.id))
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
        for template in self.kb.intents.templates:
            found = [p for p in template.patterns if contains_phrase(normalized, p)]
            if not found:
                continue
            if template.standalone:
                rest = normalized
                for p in sorted(found, key=len, reverse=True):
                    rest = rest.replace(normalize(p), " ")
                if content_tokens(rest):
                    continue  # "hola, quiero aprender redes" sigue al siguiente nivel
            return self._reply(answer=template.response, intent=template.name, match_level="alta")
        return None

    # ------------------------------------------------------------------ búsqueda
    def _answer_from_hits(self, hits: list[Hit], query_terms: set[str]) -> AdvisorReply:
        if not hits or hits[0].score < CLARIFY_THRESHOLD:
            return self._reply(
                answer=(
                    "No tengo información confirmada sobre eso. Puedo ayudarte con "
                    f"{FALLBACK_TOPICS}. ¿Sobre cuál de estos temas necesitas información?"
                ),
                intent="out_of_scope",
                match_level="ninguna",
                abstained=True,
            )
        top = hits[0]
        second = hits[1] if len(hits) > 1 else None

        if top.record.status != "verified":
            return self._abstain_with_context(top, hits)

        if top.score < ANSWER_THRESHOLD:
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
            and second.record.status == "verified"
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
            path = f" ({confirmed.source.url_path})" if confirmed.source.url_path else ""
            answer += f"\n\nLo que sí está publicado: «{confirmed.title}»{path}."
            sources.append(self._source(confirmed))
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
        """Una línea por (opción, atributo). Devuelve (texto, ¿se afirmó algo publicado?)."""
        title = f"«{rec.title}»"
        terms = self.kb.intents.attribute_evidence_terms
        if attr == "modalidad":
            if not rec.modalities:
                return f"{title}: la modalidad no está publicada; confírmala con un asesor.", False
            published = ", ".join(MODALITY_LABEL[m] for m in rec.modalities)
            offered = {"virtual" if m == "online" else m for m in rec.modalities}
            if u.asked_modalities:
                parts = [
                    f"sí figura la modalidad {MODALITY_ASKED[m]}"
                    if m in offered
                    else f"la modalidad {MODALITY_ASKED[m]} no figura"
                    for m in u.asked_modalities
                ]
                return f"{title}: {'; '.join(parts)} (modalidad publicada: {published}).", any(
                    m in offered for m in u.asked_modalities
                )
            return f"{title}: modalidad publicada: {published}.", True
        if attr == "audiencia":
            if not rec.audience:
                return f"{title}: no está publicado a qué público se dirige; confírmalo con un asesor.", False
            labels = " y ".join(AUDIENCE_LABEL[a] for a in rec.audience)
            sentence = evidence_sentence(rec, terms.get("audiencia", []))
            if u.asked_audiences and not set(u.asked_audiences) & set(rec.audience):
                return f"{title}: según lo publicado se dirige a {labels}, no a ese público.", False
            quote = f" Según la web: «{sentence}»" if sentence else ""
            return f"{title}: según lo publicado se dirige a {labels}.{quote}", True
        if attr == "certificado":
            sentence = evidence_sentence(rec, terms.get("certificado", []))
            if sentence:
                return f"{title}: {sentence}", True
            return f"{title}: no está publicado si incluye certificado; confírmalo con un asesor.", False
        if attr == "precio":
            if rec.public_price:
                return f"{title}: precio público publicado {rec.public_price}.", True
            return f"{title}: el precio no está publicado; se solicita como cotización a un asesor.", False
        if attr == "fechas":
            if rec.schedule:
                return f"{title}: horario publicado: {rec.schedule}.", True
            return f"{title}: las fechas y horarios no están publicados.", False
        if attr == "duracion":
            if rec.duration:
                return f"{title}: duración publicada: {rec.duration}.", True
            return f"{title}: la duración no está publicada.", False
        if attr == "requisitos":
            if rec.requirements:
                return f"{title}: requisitos publicados: {'; '.join(rec.requirements)}.", True
            return f"{title}: los requisitos no están publicados.", False
        if attr == "cupos":
            return f"{title}: la disponibilidad de cupos no está publicada.", False
        if attr == "tiempo_atencion":
            sentence = evidence_sentence(rec, terms.get("tiempo_atencion", []))
            if sentence:
                return f"{title}: {sentence}", True
            return f"{title}: los plazos de atención no están publicados.", False
        return f"{title}: ese dato no está publicado.", False

    def _followup(self, focus: list[KnowledgeRecord], u: Understanding) -> AdvisorReply:
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
            lines.append(CONTACT_LINE)
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

    def _focus_reference(self, focus: list[KnowledgeRecord]) -> AdvisorReply:
        rec = focus[0]
        return self._reply(
            answer=f"Sobre «{rec.title}», lo publicado es: {rec.answer}",
            intent="followup_attribute",
            match_level="media",
            sources=[self._source(rec)],
        )

    def _unavailable_attribute(
        self, attrs: list[str], u: Understanding, hits: list[Hit], current: Profile
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
        subject = ranked[0].record if ranked else self._confirmed_subject(hits, attr_terms)
        if subject is not None:
            lines = [self._attribute_line(subject, a, u) for a in attrs]
            if all(ok for _, ok in lines):  # p. ej. un catálogo con requisitos publicados
                return self._reply(
                    answer="\n".join(text for text, _ in lines),
                    intent=CATEGORY_INTENTS[subject.category],
                    match_level="media",
                    sources=[self._source(subject)],
                )
        unavailable = self.kb.unavailable_record(attrs[0])
        if unavailable is None:
            return None
        answer = unavailable.answer
        sources: list[SourceRef] = []
        if subject is not None:
            path = f" ({subject.source.url_path})" if subject.source.url_path else ""
            answer += f"\n\nLo que sí está publicado: «{subject.title}»{path}."
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
                f"Para revisar una cotización que ya recibiste, comunícate con el asesor que te atendió por WhatsApp: {WHATSAPP_QUOTE_URL}."
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
                answer=f"{QUOTE_INTRO} Para solicitar una cotización formal, te invitamos a continuar por WhatsApp con un asesor oficial: {WHATSAPP_QUOTE_URL}.",
                intent="quote_request",
                match_level="baja",
                needs_clarification=True,
                clarification_question=(
                    "¿Para qué servicio o capacitación necesitas la cotización? Por ejemplo: cámaras de seguridad, "
                    "cableado o red, mantenimiento de equipos, capacitación para tu personal o asesoría."
                ),
                missing_data=["servicio o capacitación a cotizar"],
            )
        checklist = self.recommender.checklist_for(subject, profile)
        path = f" ({subject.source.url_path})" if subject.source.url_path else ""
        answer = (
            f"{QUOTE_INTRO}\nPara solicitar una cotización de «{subject.title}»{path}, te invitamos a continuar por WhatsApp con un asesor oficial: {WHATSAPP_QUOTE_URL}.\n"
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
    def chat(self, message: str, history: list[HistoryItem] | None = None) -> AdvisorReply:
        reply = self._respond(message, history or [], depth=0)
        institutional = len(reply.sources) == 1 and (record := self.kb.get(reply.sources[0].id)) is not None and record.general_information and record.kind == "info"
        if self.gemini is None or reply.abstained or reply.recommendations or institutional or any(s.id == "convenios_resumen" for s in reply.sources):
            return self._requested_detail(message, reply)
        if self._safety(message) is not None or self._rules(message) is not None:
            return self._requested_detail(message, reply)
        understanding = understand(self.corrected(message), self.kb)
        if understanding.attributes or understanding.quote or understanding.referential or history:
            return self._requested_detail(message, reply)
        hits = self.retrieve(message, limit=12)
        records = [h.record for h in hits if h.record.status == "verified" and h.score >= CLARIFY_THRESHOLD]
        rid = self.gemini.select(message, records)
        record = next((r for r in records if r.id == rid), None)
        if record is None:
            return self._requested_detail(message, reply)
        return self._requested_detail(message, self._reply(
            answer=record.answer,
            intent=CATEGORY_INTENTS[record.category],
            match_level="media",
            sources=[self._source(record)],
            context=reply.context,
        ))

    def _requested_detail(self, message: str, reply: AdvisorReply) -> AdvisorReply:
        """Responde con el fragmento necesario; amplía a petición explícita desde la misma fuente."""
        if reply.abstained or reply.needs_clarification or len(reply.sources) != 1:
            return reply
        record = self.kb.get(reply.sources[0].id)
        if record is None or record.status != "verified" or not record.detail_answer:
            return reply
        if any(contains_phrase(normalize(message), cue) for cue in (
            "detalles", "explicame", "que incluye", "en que consiste", "informacion detallada", "informacion completa",
        )):
            document = self.kb.documents_by_source.get(record.id)
            if document is not None and document.fragments:
                if reply.answer in {f.answer for f in document.fragments}:
                    return reply
                return reply.model_copy(update={"answer": document.fragments[0].answer})
            return reply.model_copy(update={"answer": record.detail_answer})
        if reply.answer != record.answer or record.stable_id is not None:
            return reply
        specific = set(content_tokens(message)) - set(content_tokens(record.title + " " + record.answer))
        sentences = [s.strip() for s in re.split(r"(?<=[.;])\s+", record.detail_answer) if 10 <= len(s.strip()) <= 600]
        best = max(sentences, key=lambda s: len(specific & set(content_tokens(s))), default="")
        if best and specific & set(content_tokens(best)):
            return reply.model_copy(update={"answer": best})
        return reply

    def _respond(self, message: str, history: list[HistoryItem], depth: int) -> AdvisorReply:
        early = self._safety(message) or self._rules(message)
        if early is not None:
            return early
        if not content_tokens(message):
            return self._clarify([])

        corrected = self.corrected(message)
        u = understand(corrected, self.kb)  # sobre el texto corregido: "cotisasion" -> "cotizacion"
        users = [h.content for h in history if h.role == "user"][-MAX_CONTEXT_TURNS:]
        if u.topic_change:
            users = []  # "ahora otra cosa…": el contexto anterior se descarta
        current = extract_profile(corrected, self.kb)
        profile, continued = self._merge_profile(current, users)
        current_specific = bool(current.specific_topics(self.generic))
        focus: list[KnowledgeRecord] = []
        if depth == 0 and users and not current_specific and (u.attributes or u.quote or u.referential):
            focus = self._focus(users)
        reply = self._decide(message, corrected, u, current, profile, continued, focus, users)
        if depth == 0:
            reply.context = ConversationContext(
                turns_considered=len(users),
                topics=sorted(profile.topics),
                level=None if profile.level == "desconocido" else profile.level,
                modality=None if profile.modality == "cualquiera" else profile.modality,
                focus_ids=(reply.context.focus_ids if reply.needs_clarification and reply.context else [r.id for r in focus]),
                topic_changed=u.topic_change,
                referential=u.referential,
            )
        return reply

    def _decide(
        self,
        message: str,
        corrected: str,
        u: Understanding,
        current: Profile,
        profile: Profile,
        continued: bool,
        focus: list[KnowledgeRecord],
        users: list[str],
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
                "interinstitucionales", "informacion", "saber"}
            listing = self.kb.get("convenios_resumen")
            if listing is not None and listing.status == "verified" and not terms - generic:
                return self._reply(answer=listing.answer, intent="agreement_info", match_level="alta",
                    sources=[self._source(listing)])

        if hits and hits[0].record.status == "verified" and hits[0].record.public_card is not None:
            record = hits[0].record
            if hits[0].score >= ANSWER_THRESHOLD and terms & {"vigencia", "vence", "vencimiento", "vencen"}:
                return self._reply(answer=f"{record.title}: Vigencia publicada hasta el {record.public_card.vigencia}.",
                    intent="agreement_info", match_level="alta", sources=[self._source(record)])

        # 2. Solicitudes comerciales.
        if u.private_quote:
            return self._private_quote()
        previous_quote = bool(users) and understand(self.corrected(users[-1]), self.kb).quote
        if u.quote or (previous_quote and (current.topics or current.objectives)):
            quote_profile = current if (current.topics or current.objectives) else profile
            return self._quote(quote_profile, focus, hits)

        # 3. Seguimiento sobre la opción anterior.
        if focus and u.attributes and (u.referential or u.short):
            return self._followup(focus, u)

        # 4. Atributo no publicado sobre un tema concreto.
        unavailable = [a for a in u.attributes if a in self.kb.intents.unavailable_attributes]
        if unavailable:
            reply = self._unavailable_attribute(unavailable, u, hits, current)
            if reply is not None:
                return reply

        # 5. Recomendación o búsqueda.
        information = self._institutional_information(hits, plan.goal if plan else "unknown")
        if information is not None and not u.attributes and not (plan and (plan.enrollment or plan.browse or plan.ambiguous)):
            return information
        if (u.attributes and set(u.attributes) != {"audiencia"} and hits
            and hits[0].record.stable_id is not None and hits[0].record.status == "verified"
            and hits[0].score >= ANSWER_THRESHOLD and not (plan and plan.browse)
            and (len(hits) == 1 or hits[0].score - hits[1].score >= AMBIGUITY_MARGIN)):
            return self._followup([hits[0].record], u)
        if plan is not None:
            if plan.enrollment:
                contact = self.kb.get(self.kb.intents.human_handoff.record_id)
                return self._reply(
                    answer=("Para consultar la matrícula, continúa por el WhatsApp oficial de Horus Group: "
                            f"{WHATSAPP_MATRICULA_URL}. No compartas datos personales en este chat."),
                    intent="enrollment_request", match_level="alta",
                    sources=[self._source(contact)] if contact else [],
                )
            if plan.ambiguous:
                return self._clarify_goal()
            if plan.browse:
                listing_id = "cat_listado_formacion" if plan.goal == "learn" else "cat_listado_servicios"
                listing = self.kb.get(listing_id)
                if listing is not None and not self.kb.catalog.catalog.is_expired(datetime.now(UTC)):
                    return self._reply(answer=listing.answer, intent="training_info" if plan.goal == "learn" else "service_info",
                        match_level="alta", sources=[self._source(listing)])
                subject = "cursos" if plan.goal == "learn" else "servicios"
                return self._reply(answer=f"No hay {subject} publicados vigentes en el catálogo disponible.",
                    intent="unavailable_info", match_level="ninguna", abstained=True)
            if plan.recommend:
                if not plan.subject:
                    return self._clarify_goal()
                if unpublished:
                    return self._unpublished_topic(plan.goal, corrected)
                current.goal = plan.goal
                relevant = hits if planned_search else self.retrieve(topic or plan.subject)
                if (plan.goal == "learn" and any(h.record.general_information and h.score >= ANSWER_THRESHOLD for h in relevant)
                    and not any(h.record.source.kind == "catalogo_laravel" and h.record.kind == "course"
                        and h.record.status == "verified" and h.score >= ANSWER_THRESHOLD for h in relevant)):
                    return self._unpublished_topic("learn", corrected)
                return (self._multi_recommend(corrected) or self._catalog_recommend(relevant, current)
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
        if focus and u.referential and (reply.needs_clarification or reply.intent == "out_of_scope"):
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
        kind = {"learn": "un curso o capacitación", "service": "un servicio tecnológico"}.get(goal, "información")
        listing = {"learn": "¿Qué cursos ofrecen?", "service": "¿Qué servicios ofrecen?"}.get(
            goal, "¿Qué cursos ofrecen? o ¿Qué servicios ofrecen?"
        )
        openings = (
            f"No encontré {kind} publicado sobre ese tema en el catálogo vigente de Horus Group.",
            f"Por ahora no hay {kind} publicado sobre ese tema en el catálogo vigente de Horus Group.",
        )
        opening = openings[sum(map(ord, text)) % len(openings)]  # determinista: misma consulta, misma respuesta
        return self._reply(
            answer=(f"{opening} Si quieres ver lo que está vigente, pregúntame «{listing}». "
                    "También puedes consultarlo con un asesor por los canales oficiales."),
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
        verb = "está publicado" if len(titles) == 1 else "están publicados"
        return self._reply(
            answer=first.fragment.answer if first.fragment else first.record.answer,
            intent="course_recommendation" if first.record.kind == "course" else "service_recommendation",
            match_level=_retrieval_level(min(h.score for h in chosen)),
            sources=[self._source(h.record) for h in chosen],
            recommendations=[self._recommendation(h) for h in chosen],
            needs_clarification=True,
            clarification_question=f"Para tu otra consulta también {verb} {others}. ¿Sobre cuál quieres más detalles?",
        )

    def _recommendation(self, hit: Hit) -> Recommendation:
        record = hit.record
        evidence = hit.fragment.text if hit.fragment else self.kb.documents_by_source[record.id].description
        action = "cotización" if record.kind == "service" else "matrícula"
        url = WHATSAPP_QUOTE_URL if record.kind == "service" else WHATSAPP_MATRICULA_URL
        return Recommendation(id=record.id, title=record.title, kind=record.kind,
            reason=f"El contenido publicado trata tu consulta: «{evidence}».", evidence=[record.id],
            evidence_text=evidence,
            next_step=f"Para consultar la {action}, continúa por el WhatsApp oficial: {url}.",
            match_level=_retrieval_level(hit.score), url_path=record.source.url_path)

    def _clarify_goal(self) -> AdvisorReply:
        return self._reply(answer="Puedo ayudarte a encontrar información publicada de Horus Group.",
            intent="clarification", match_level="baja", needs_clarification=True,
            clarification_question="¿Buscas un curso para aprender o un servicio tecnológico? ¿Sobre qué tema?")

    def _catalog_recommend(self, hits: list[Hit], profile: Profile) -> AdvisorReply | None:
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
        for hit in candidates[:1]:
            record = hit.record
            return self._reply(answer=hit.fragment.answer if hit.fragment else record.answer,
                intent="course_recommendation" if record.kind == "course" else "service_recommendation",
                match_level=_retrieval_level(hit.score), sources=[self._source(record)],
                recommendations=[self._recommendation(hit)])
        return None
