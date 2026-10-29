"""Recomendador explicable sobre registros verificados.

Cada opción se puntúa por: coincidencia temática, similitud con la necesidad expresada, público,
modalidad y nivel. Solo se consideran registros verificados y recomendables; si nada confirmado
cubre el tema, se dice explícitamente y la orientación general queda separada (no atribuida a Horus).
"""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Literal

from app.core.text import contains_phrase, normalize
from app.schemas.chat import AdvisorReply, MatchLevel, Recommendation, SourceRef
from app.schemas.knowledge import KnowledgeRecord
from app.services.knowledge import KnowledgeBase

Goal = Literal["learn", "service", "unknown"]

LEARN_KINDS = {"training_program", "course_modality", "agreement", "course"}
SERVICE_KINDS = {"service", "advisory"}

TOPIC_LABELS = {
    "redes": "redes",
    "cableado": "cableado de red",
    "wifi": "red Wi-Fi",
    "camaras": "cámaras de seguridad",
    "mantenimiento": "mantenimiento",
    "computadoras": "computadoras y equipos",
    "software": "programas y sistemas",
    "ofimatica": "ofimática",
    "enfermeria": "enfermería",
    "administracion": "administración",
    "economia": "economía",
    "tesis": "tesis",
    "investigacion": "investigación",
    "finanzas": "finanzas",
    "liderazgo": "liderazgo",
    "legal": "asesoría legal",
    "certificacion": "certificación",
    "tecnologia": "tecnología",
    "capacitacion": "capacitación",
    "cursos": "cursos",
    "empresas": "empresas",
}

CONTACT_LINE = "Para confirmar disponibilidad, contenidos, fechas y precios, consulta al +51 927 582 305 o a horusgroupcajamarca@gmail.com."


@dataclass
class Profile:
    topics: set[str] = field(default_factory=set)
    level: str = "desconocido"
    modality: str = "cualquiera"
    audience: str = "desconocido"
    goal: Goal = "unknown"
    text: str = ""
    objectives: set[str] = field(default_factory=set)

    def specific_topics(self, generic: set[str]) -> set[str]:
        return self.topics - generic

    def merge_from(self, older: Profile, generic: set[str]) -> None:
        """Completa huecos con turnos anteriores; el mensaje actual tiene prioridad."""
        if not self.specific_topics(generic):
            self.topics |= older.topics
        if self.level == "desconocido":
            self.level = older.level
        if self.modality == "cualquiera":
            self.modality = older.modality
        if self.audience == "desconocido":
            self.audience = older.audience
        if self.goal == "unknown":
            self.goal = older.goal
        if not self.objectives:
            self.objectives = set(older.objectives)
        self.text = f"{older.text} {self.text}".strip()


def _match_lexicon(normalized: str, lexicon: dict[str, list[str]]) -> list[str]:
    return [name for name, phrases in lexicon.items() if any(contains_phrase(normalized, p) for p in phrases)]


def _cue_count(normalized: str, phrases: list[str]) -> int:
    return sum(1 for p in phrases if contains_phrase(normalized, p))


def extract_profile(text: str, kb: KnowledgeBase) -> Profile:
    rules = kb.intents
    normalized = normalize(text)
    profile = Profile(text=text)
    profile.topics = set(_match_lexicon(normalized, rules.topic_lexicon))
    levels = _match_lexicon(normalized, rules.level_lexicon)
    if levels:
        profile.level = levels[0]
    modalities = _match_lexicon(normalized, rules.modality_lexicon)
    if modalities:
        profile.modality = modalities[0]
    audiences = _match_lexicon(normalized, rules.audience_lexicon)
    if "empresa" in audiences or "empresas" in profile.topics:
        profile.audience = "empresa"
    elif audiences:
        profile.audience = audiences[0]
    profile.objectives = {
        name
        for name, rule in rules.objective_lexicon.items()
        if any(contains_phrase(normalized, p) for p in rule.patterns)
    }
    learn = _cue_count(normalized, rules.recommendation_cues.learn)
    service = _cue_count(normalized, rules.recommendation_cues.service)
    if profile.level != "desconocido":
        learn += 1
    if learn > service:
        profile.goal = "learn"
    elif service > learn:
        profile.goal = "service"
    elif learn:
        profile.goal = "service" if profile.audience == "empresa" else "learn"
    return profile


def has_recommendation_cue(text: str, kb: KnowledgeBase) -> bool:
    normalized = normalize(text)
    cues = kb.intents.recommendation_cues
    return any(contains_phrase(normalized, p) for p in cues.learn + cues.service)


def has_profile_slot(text: str, kb: KnowledgeBase) -> bool:
    normalized = normalize(text)
    rules = kb.intents
    return bool(
        _match_lexicon(normalized, rules.level_lexicon)
        or _match_lexicon(normalized, rules.modality_lexicon)
        or _match_lexicon(normalized, rules.topic_lexicon)
        or any(
            any(contains_phrase(normalized, p) for p in r.patterns) for r in rules.objective_lexicon.values()
        )
    )


def _level_for(score: float) -> MatchLevel:
    if score >= 0.7:
        return "alta"
    if score >= 0.45:
        return "media"
    if score >= 0.25:
        return "baja"
    return "ninguna"


SimilarityFn = Callable[[str, set[str]], dict[str, float]]


@dataclass
class _Scored:
    record: KnowledgeRecord
    score: float
    covered: set[str]
    reasons: list[str]
    missing: list[str]
    signals: list[str] = field(default_factory=list)
    evidence_text: str | None = None


AUDIENCE_LABEL = {"persona": "personas y hogares", "empresa": "empresas e instituciones"}
MODALITY_LABEL = {
    "presencial": "presencial",
    "online": "online",
    "virtual": "virtual",
    "semipresencial": "semipresencial",
}


class Recommender:
    def __init__(self, kb: KnowledgeBase, similarity: SimilarityFn) -> None:
        self.kb = kb
        self.similarity = similarity
        self.generic = set(kb.intents.generic_topics)

    def _candidates(self, goal: Goal) -> list[KnowledgeRecord]:
        records = self.kb.recommendable()
        if goal == "learn":
            return [
                r
                for r in records
                if r.kind in LEARN_KINDS or "capacitacion" in r.topics or "tesis" in r.topics
            ]
        if goal == "service":
            return [
                r
                for r in records
                if r.kind in SERVICE_KINDS or (r.kind == "training_program" and "empresa" in r.audience)
            ]
        return records

    def wanted_topics(self, profile: Profile) -> set[str]:
        wanted = profile.specific_topics(self.generic)
        if not wanted and profile.audience != "desconocido" and len(profile.topics) >= 2:
            wanted = set(profile.topics)  # p. ej. capacitación + empresa
        if not wanted and profile.objectives:
            wanted = {f"obj:{o}" for o in profile.objectives}  # p. ej. "postular al Estado"
        return wanted

    def _offered(self, record: KnowledgeRecord, wanted: set[str]) -> tuple[set[str], str | None]:
        """Temas del registro más objetivos respaldados por una frase literal del propio registro."""
        from app.services.understanding import evidence_sentence

        offered = set(record.topics)
        evidence = None
        for item in wanted:
            if item.startswith("obj:"):
                rule = self.kb.intents.objective_lexicon.get(item[4:])
                sentence = evidence_sentence(record, rule.evidence_terms) if rule else None
                if sentence:
                    offered.add(item)
                    evidence = evidence or sentence
        return offered, evidence

    def rank(self, profile: Profile, goal: Goal | None = None) -> list[_Scored]:
        """Opciones verificadas que cubren lo pedido, ordenadas. Sin texto: lo usan chat y cotizaciones."""
        wanted = self.wanted_topics(profile)
        if not wanted:
            return []
        goal = goal or profile.goal
        if any(w.startswith("obj:") for w in wanted) and goal == "service":
            goal = "unknown"  # un objetivo (postular) puede cumplirse con formación o con asesoría
        candidates = self._candidates(goal)
        sims = self.similarity(profile.text, {r.id for r in candidates}) if candidates else {}
        scored = [self._score(r, profile, sims.get(r.id, 0.0)) for r in candidates]
        scored = [x for x in scored if x.covered and _level_for(x.score) != "ninguna"]
        scored.sort(key=lambda x: (-x.score, x.record.id))
        return scored

    def _score(self, record: KnowledgeRecord, profile: Profile, sim: float) -> _Scored:
        wanted = self.wanted_topics(profile)
        offered, evidence = self._offered(record, wanted)
        covered = wanted & offered
        generic_hits = (profile.topics & self.generic) & offered
        reasons: list[str] = []
        missing: list[str] = []
        topic_part = 0.6 * (len(covered) / len(wanted)) if wanted else 0.2 * min(1.0, len(generic_hits) / 2)
        signals: list[str] = []
        topic_covered = {t for t in covered if not t.startswith("obj:")}
        if topic_covered:
            reasons.append(
                "coincide con tu interés en "
                + ", ".join(TOPIC_LABELS.get(t, t) for t in sorted(topic_covered))
            )
            signals.append("tema: " + ", ".join(sorted(topic_covered)))
        for item in sorted(covered - topic_covered):
            rule = self.kb.intents.objective_lexicon[item[4:]]
            reasons.append(f"sirve a tu objetivo de {rule.label} (lo indica la web)")
            signals.append(f"objetivo: {item[4:]}")
        uncovered = wanted - offered
        if covered and uncovered:
            reasons.append(
                "no cubre "
                + ", ".join(TOPIC_LABELS.get(t, t) for t in sorted(uncovered) if not t.startswith("obj:"))
            )
            missing.append("no cubre " + ", ".join(sorted(uncovered)))
        score = topic_part + 0.35 * sim + 0.1 * min(1.0, len(generic_hits))
        if profile.audience != "desconocido" and record.audience:
            if profile.audience in record.audience:
                score += 0.05
                reasons.append(
                    "está dirigida a " + ("empresas" if profile.audience == "empresa" else "personas")
                )
                signals.append(f"público: {profile.audience}")
            else:
                score -= 0.2
        if profile.modality != "cualquiera":
            offered_mod = {"virtual" if m == "online" else m for m in record.modalities}
            if not offered_mod:
                missing.append(f"modalidad {profile.modality} no publicada para «{record.title}»")
            elif profile.modality in offered_mod or (
                profile.modality == "semipresencial" and "online" in record.modalities
            ):
                score += 0.05
                reasons.append(f"se publica en modalidad {profile.modality}")
            else:
                score -= 0.15
                missing.append(f"«{record.title}» no figura en modalidad {profile.modality}")
        if profile.level != "desconocido":
            if record.levels and profile.level not in record.levels:
                score -= 0.2
            elif not record.levels:
                missing.append("nivel requerido no publicado")
        if record.modalities:
            signals.append("modalidad publicada: " + ", ".join(MODALITY_LABEL[m] for m in record.modalities))
        return _Scored(
            record, round(max(0.0, min(1.0, score)), 4), covered, reasons, missing, signals, evidence
        )

    def checklist_for(self, record: KnowledgeRecord | None, profile: Profile | None = None) -> list[str]:
        checklist = self.kb.intents.quote.checklist
        topics = list(record.topics) if record else []
        topics += sorted(profile.topics) if profile else []
        for topic in topics:
            if topic in checklist:
                return checklist[topic]
        return checklist["_default"]

    def limitations_for(self, scored: _Scored, profile: Profile) -> list[str]:
        record = scored.record
        items = list(scored.missing)
        if record.kind in LEARN_KINDS and not record.public_price:
            items.append("fechas, precios y cupos no publicados")
        elif record.kind in SERVICE_KINDS and not record.public_price:
            items.append("precio sujeto a cotización con un asesor")
        if (
            profile.level != "desconocido"
            and not record.levels
            and "nivel requerido no publicado" not in items
        ):
            items.append("nivel requerido no publicado")
        return list(dict.fromkeys(items))

    def recommend(self, profile: Profile, mode: Literal["lexical", "hybrid"] = "lexical") -> AdvisorReply:
        wanted = self.wanted_topics(profile)
        goal = profile.goal
        intent = "service_recommendation" if goal == "service" else "course_recommendation"

        if not wanted:
            return self._clarify_topic(profile, intent, mode)

        top = self.rank(profile)[:3]
        if goal == "unknown" and top:
            intent = (
                "service_recommendation" if top[0].record.kind in SERVICE_KINDS else "course_recommendation"
            )

        missing: list[str] = []
        for s in top:
            for m in s.missing:
                if m not in missing:
                    missing.append(m)

        covered_all = set().union(*(s.covered for s in top)) if top else set()
        uncovered = wanted - covered_all
        guidance = None
        lines: list[str] = []

        if top:
            lines.append(
                "Según la información publicada por Horus Group, estas opciones se ajustan a lo que buscas:"
            )
            for i, s in enumerate(top, 1):
                why = "; ".join(s.reasons) if s.reasons else "relacionada con tu consulta"
                lines.append(f"{i}. {s.record.title}: {why}.")
                if s.evidence_text:
                    lines.append(f"   Según la web: «{s.evidence_text}»")
        if uncovered:
            labels = ", ".join(TOPIC_LABELS.get(t, t) for t in sorted(uncovered))
            if goal == "learn":
                lines.append(
                    f"No encuentro en la información publicada un curso o capacitación específica de {labels}; "
                    "la web no publica todavía la lista de cursos vigentes."
                )
                if "formacion_personalizada" not in {s.record.id for s in top}:
                    alt = self.kb.get("formacion_personalizada")
                    if alt and alt.status == "verified":
                        top.append(
                            _Scored(
                                alt,
                                0.25,
                                set(),
                                [
                                    "es formación adaptada a tus necesidades; confirma con un asesor si puede cubrir "
                                    + labels
                                ],
                                [],
                            )
                        )
                        lines.append(f"{len(top)}. {alt.title}: alternativa a confirmar con un asesor.")
                missing.append(f"oferta vigente de cursos de {labels}")
            elif goal == "service":
                lines.append(f"No encuentro un servicio publicado de Horus Group para {labels}.")
            else:
                lines.append(
                    f"No encuentro en la información publicada una opción de Horus Group sobre {labels}."
                )
            for topic in sorted(uncovered):
                if topic in self.kb.intents.general_guidance:
                    guidance = self.kb.intents.general_guidance[topic]
                    break
            # Señala, sin recomendarlo como curso, que existe un servicio relacionado.
            if goal == "learn":
                related_services = [
                    r for r in self.kb.recommendable() if r.kind == "service" and uncovered & set(r.topics)
                ]
                if related_services:
                    names = ", ".join(f"«{r.title}»" for r in related_services[:2])
                    lines.append(f"Horus Group sí ofrece {names} como servicio para clientes, no como curso.")

        if goal == "learn":
            if profile.level != "desconocido" and "nivel requerido no publicado" not in missing and top:
                missing.append("nivel requerido no publicado")
            missing.append("fechas, precios y cupos no publicados")
        lines.append(CONTACT_LINE)

        recommendations = [self._to_recommendation(s, profile) for s in top]
        best = max(
            (_level_for(s.score) for s in top),
            key=["ninguna", "baja", "media", "alta"].index,
            default="ninguna",
        )
        needs_clarification = False
        question = None
        mixed = len({s.record.kind in SERVICE_KINDS for s in top}) > 1
        if goal == "unknown" and mixed:
            needs_clarification = True
            question = (
                "¿Buscas aprender (curso o capacitación) o contratar un servicio para tu hogar o empresa?"
            )
        elif goal == "learn" and profile.modality == "cualquiera" and top:
            question = "¿Prefieres modalidad presencial, semipresencial o virtual?"
        return AdvisorReply(
            answer="\n".join(lines),
            intent=intent,
            match_level=best,
            needs_clarification=needs_clarification,
            clarification_question=question,
            abstained=not any(s.covered for s in top),
            recommendations=recommendations,
            missing_data=missing,
            general_guidance=guidance,
            sources=[
                SourceRef(id=s.record.id, title=s.record.title, url_path=s.record.source.url_path)
                for s in top
            ],
            mode=mode,
        )

    def _to_recommendation(self, s: _Scored, profile: Profile) -> Recommendation:
        record = s.record
        question = None
        if profile.modality != "cualquiera" and not record.modalities:
            question = f"La modalidad de «{record.title}» no está publicada: ¿quieres que te indique cómo consultarla?"
        next_step = None
        if record.kind in SERVICE_KINDS or "empresa" in record.audience:
            next_step = (
                "Para cotizar, escribe a un asesor e indica: "
                + "; ".join(self.checklist_for(record, profile))
                + "."
            )
        return Recommendation(
            id=record.id,
            title=record.title,
            kind=record.kind,
            reason=("; ".join(s.reasons) or "relacionada con tu consulta").capitalize() + ".",
            evidence=[record.id],
            evidence_text=s.evidence_text,
            match_level=_level_for(s.score),
            url_path=record.source.url_path,
            signals=s.signals,
            limitations=self.limitations_for(s, profile),
            needs_clarification=question is not None,
            clarification_question=question,
            next_step=next_step,
        )

    def _clarify_topic(
        self, profile: Profile, intent: str, mode: Literal["lexical", "hybrid"]
    ) -> AdvisorReply:
        if profile.goal == "service" or profile.audience == "empresa" and profile.goal != "learn":
            question = (
                "¿Qué necesitas resolver? Por ejemplo: cámaras de seguridad, cableado o red de la oficina, "
                "mantenimiento de computadoras, capacitación para tu personal o asesoramiento empresarial."
            )
            answer = "Puedo orientarte hacia el servicio adecuado de Horus Group."
        else:
            parts = []
            if profile.level == "desconocido":
                parts.append("¿Tienes alguna experiencia o estás comenzando?")
            parts.append(
                "¿Qué área te interesa? En la información publicada hay formación en enfermería, administración "
                "y economía (por convenios), uso de programas informáticos, capacitaciones personalizadas, "
                "certificaciones profesionales y asesoría de tesis."
            )
            question = " ".join(parts)
            answer = "Puedo ayudarte a encontrar una formación adecuada."
        return AdvisorReply(
            answer=answer,
            intent=intent,
            match_level="ninguna",
            needs_clarification=True,
            clarification_question=question,
            missing_data=["tema o necesidad concreta"],
            general_guidance=self.kb.intents.general_guidance.get("tecnologia")
            if "tecnologia" in profile.topics
            else None,
            mode=mode,
        )
