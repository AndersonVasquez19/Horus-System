"""Evaluación reproducible de Horus AI Advisor.

Uso (PowerShell, desde chatbot-python/):
    .venv\\Scripts\\python.exe scripts\\evaluate.py                  # modo léxico
    .venv\\Scripts\\python.exe scripts\\evaluate.py --mode hybrid    # requiere el modelo en models/
    .venv\\Scripts\\python.exe scripts\\evaluate.py --mode both      # cada modo en su propio proceso
    .venv\\Scripts\\python.exe scripts\\evaluate.py --tag antes      # sufijo para conservar informes

Entradas: data/evaluation.json (consultas sueltas) y data/conversations.json (varios turnos).
Salida: reports/evaluation-<modo>[-<tag>].json y .md. Deterministas salvo tiempos y memoria.

Métricas:
  - behavior_accuracy: comportamiento esperado (y fuente/recomendación correcta cuando se indica).
  - intent_accuracy: la intención devuelta está entre las aceptadas.
  - retrieval_hit@1 / hit@3: consultas con expected_ids y comportamiento answer/abstain.
  - recommendation_precision@1: la primera recomendación es pertinente (consultas "recommend").
  - abstention_precision / recall.
  - ungrounded_responses: cita registros no verificados, recomienda algo no recomendable o
    contiene cifras que no existen en la base de conocimiento (p. ej. repite "100 soles").
  - conversaciones: exactitud por turno y escenarios completos correctos.
  - memoria del proceso (conjunto de trabajo / RSS) tras cargar y pico.

TODAS las consultas son sintéticas: no son evidencia de usuarios reales.
"""

from __future__ import annotations

import argparse
import json
import re
import statistics
import subprocess
import sys
import time
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.core.config import Settings  # noqa: E402
from app.core.text import normalize  # noqa: E402
from app.schemas.chat import AdvisorReply, HistoryItem  # noqa: E402
from app.services.chat_engine import AdvisorEngine  # noqa: E402
from app.services.knowledge import KnowledgeBase  # noqa: E402
from app.services.semantic_search import SemanticIndex, SentenceTransformerEmbedder  # noqa: E402
from scripts.sysinfo import process_memory_mb  # noqa: E402

_NUMBER = re.compile(r"\d+")
_ENUMERATION = re.compile(r"(?m)^\d+\.\s")  # "1. Opción" no es una cifra
REFUSAL_INTENTS = {"prompt_injection", "sensitive_request"}
TEMPLATE_INTENTS = {"greeting", "thanks", "farewell", "capabilities"}


def build_engine(mode: str, settings: Settings) -> tuple[AdvisorEngine, float | None]:
    catalog = getattr(settings, "catalog_path", None)  # ausente en versiones anteriores del motor
    paths = (settings.knowledge_path, settings.intents_path) + ((catalog,) if catalog else ())
    kb = KnowledgeBase.load(*paths)
    if mode == "lexical":
        return AdvisorEngine(kb), None
    index = SemanticIndex(kb.records)
    started = time.perf_counter()
    embedder = SentenceTransformerEmbedder(
        settings.semantic_model_dir,
        settings.ml_threads,
        settings.semantic_query_prefix,
        settings.semantic_passage_prefix,
    )
    index.attach(embedder, settings.semantic_query_prefix, settings.semantic_passage_prefix)
    return AdvisorEngine(kb, index), round(time.perf_counter() - started, 1)


def all_ids(reply: AdvisorReply) -> list[str]:
    return [s.id for s in reply.sources] + [r.id for r in reply.recommendations]


def _text(reply: AdvisorReply) -> str:
    return normalize(reply.answer + " " + (reply.clarification_question or ""))


def matches_behavior(reply: AdvisorReply, behavior: str, expected: list[str]) -> bool:
    ids = all_ids(reply)
    if behavior == "answer":
        return (
            not reply.abstained
            and not reply.needs_clarification
            and bool(reply.sources)
            and (not expected or reply.sources[0].id in expected)
        )
    if behavior == "recommend":
        return (
            bool(reply.recommendations)
            and not reply.abstained
            and (not expected or reply.recommendations[0].id in expected)
        )
    if behavior == "recommend_none":
        return "no encuentro" in _text(reply) and all(r.match_level != "alta" for r in reply.recommendations)
    if behavior == "clarify":
        return reply.needs_clarification
    if behavior == "abstain":
        return reply.abstained and reply.intent not in REFUSAL_INTENTS
    if behavior == "refuse":
        return reply.intent in REFUSAL_INTENTS
    if behavior == "template":
        return reply.intent in TEMPLATE_INTENTS and not ids
    if behavior == "quote_guidance":
        return reply.intent == "quote_request" and (not expected or bool(set(ids) & set(expected)))
    if behavior == "followup":
        return (
            reply.intent == "followup_attribute"
            and bool(reply.sources)
            and (not expected or reply.sources[0].id in expected)
        )
    if behavior == "answer_check":
        return True  # solo se exigen las restricciones de contenido del turno
    raise ValueError(behavior)


def content_ok(reply: AdvisorReply, spec: dict) -> bool:
    text = _text(reply)
    if spec.get("must_contain_any") and not any(normalize(s) in text for s in spec["must_contain_any"]):
        return False
    return not any(normalize(s) in text for s in spec.get("must_not_contain", []))


def ungrounded_reasons(reply: AdvisorReply, engine: AdvisorEngine, kb_numbers: set[str]) -> list[str]:
    reasons = []
    for source in reply.sources:
        record = engine.kb.get(source.id)
        if record is None or record.status != "verified":
            reasons.append(f"fuente no verificada {source.id}")
    for rec in reply.recommendations:
        record = engine.kb.get(rec.id)
        if record is None or record.status != "verified" or not record.recommendable:
            reasons.append(f"recomendación no autorizada {rec.id}")
    text = _ENUMERATION.sub("", reply.answer + "\n" + (reply.clarification_question or ""))
    for number in _NUMBER.findall(text):
        if number not in kb_numbers:
            reasons.append(f"cifra sin fuente {number}")
    return reasons


def _ratio(num: int, den: int) -> float | None:
    return round(num / den, 3) if den else None


def _latency(values: list[float]) -> dict[str, float | None]:
    if not values:
        return {"p50": None, "p95": None, "max": None}
    ordered = sorted(values)
    return {
        "p50": round(statistics.median(ordered), 2),
        "p95": round(ordered[int(0.95 * (len(ordered) - 1))], 2),
        "max": round(ordered[-1], 2),
    }


def block(subset: list[dict]) -> dict:
    retrieval = [
        r
        for r in subset
        if r["expected_ids"]
        and set(r["expected_behaviors"]) & {"answer", "abstain"}
        and r["expected_behaviors"][0] != "recommend"
    ]
    recs = [r for r in subset if r["expected_behaviors"][0] == "recommend" and r["expected_ids"]]
    should_abstain = [
        r
        for r in subset
        if "abstain" in r["expected_behaviors"]
        and set(r["expected_behaviors"]) <= {"abstain", "refuse", "clarify"}
    ]
    did_abstain = [r for r in subset if r["abstained"] and r["intent"] not in REFUSAL_INTENTS]
    correct_abstain = [
        r
        for r in did_abstain
        if set(r["expected_behaviors"]) & {"abstain", "recommend_none", "quote_guidance"}
    ]
    lat = _latency([r["ms"] for r in subset])
    return {
        "n": len(subset),
        "behavior_accuracy": _ratio(sum(r["behavior_ok"] for r in subset), len(subset)),
        "intent_accuracy": _ratio(sum(r["intent_ok"] for r in subset), len(subset)),
        "retrieval_hit@1": _ratio(
            sum(bool(r["ids"]) and r["ids"][0] in r["expected_ids"] for r in retrieval), len(retrieval)
        ),
        "retrieval_hit@3": _ratio(
            sum(bool(set(r["ids"]) & set(r["expected_ids"])) for r in retrieval), len(retrieval)
        ),
        "recommendation_precision@1": _ratio(
            sum(bool(r["ids"]) and r["ids"][0] in r["expected_ids"] for r in recs), len(recs)
        ),
        "abstention_recall": _ratio(sum(r["abstained"] for r in should_abstain), len(should_abstain)),
        "abstention_precision": _ratio(len(correct_abstain), len(did_abstain)),
        "ungrounded_responses": sum(bool(r["ungrounded"]) for r in subset),
        "latency_ms_p50": lat["p50"],
        "latency_ms_p95": lat["p95"],
        "latency_ms_max": lat["max"],
    }


def evaluate_items(engine: AdvisorEngine, dataset: dict, kb_numbers: set[str]) -> list[dict]:
    rows = []
    for item in dataset["items"]:
        history = [HistoryItem(**h) for h in item.get("history", [])]
        started = time.perf_counter()
        reply = engine.chat(item["query"], history)
        elapsed_ms = (time.perf_counter() - started) * 1000
        ok = any(matches_behavior(reply, b, item["expected_ids"]) for b in item["behaviors"])
        rows.append(
            {
                "id": item["id"],
                "split": item["split"],
                "category": item["category"],
                "origin": item.get("origin", "sintetico_asistente"),
                "added_in": item.get("added_in", "fase1"),
                "query": item["query"],
                "expected_behaviors": item["behaviors"],
                "expected_ids": item["expected_ids"],
                "intent": reply.intent,
                "intent_ok": reply.intent in item["intents"],
                "behavior_ok": ok,
                "abstained": reply.abstained,
                "needs_clarification": reply.needs_clarification,
                "ids": all_ids(reply)[:3],
                "match_level": reply.match_level,
                "ungrounded": ungrounded_reasons(reply, engine, kb_numbers),
                "ms": round(elapsed_ms, 2),
            }
        )
    return rows


def evaluate_conversations(engine: AdvisorEngine, conversations: dict, kb_numbers: set[str]) -> list[dict]:
    results = []
    for scenario in conversations["scenarios"]:
        history: list[HistoryItem] = []
        turns = []
        for spec in scenario["turns"]:
            reply = engine.chat(spec["user"], history[-10:])
            expected = spec.get("expected_ids", [])
            behavior_ok = any(matches_behavior(reply, b, expected) for b in spec["behaviors"])
            ok = behavior_ok and content_ok(reply, spec)
            turns.append(
                {
                    "user": spec["user"],
                    "ok": ok,
                    "intent": reply.intent,
                    "intent_ok": reply.intent in spec.get("intents", [reply.intent]),
                    "ids": all_ids(reply)[:3],
                    "answer": reply.answer[:240],
                    "ungrounded": ungrounded_reasons(reply, engine, kb_numbers),
                }
            )
            history += [
                HistoryItem(role="user", content=spec["user"]),
                HistoryItem(role="assistant", content=reply.answer[:2000]),
            ]
        results.append(
            {"id": scenario["id"], "split": scenario["split"], "title": scenario["title"], "turns": turns}
        )
    return results


def conversation_block(results: list[dict]) -> dict:
    turns = [t for s in results for t in s["turns"]]
    return {
        "scenarios": len(results),
        "scenario_success": _ratio(sum(all(t["ok"] for t in s["turns"]) for s in results), len(results)),
        "turns": len(turns),
        "turn_accuracy": _ratio(sum(t["ok"] for t in turns), len(turns)),
        "ungrounded_turns": sum(bool(t["ungrounded"]) for t in turns),
    }


def evaluate(mode: str, settings: Settings, dataset: dict, conversations: dict) -> dict:
    memory_before = process_memory_mb()
    engine, load_s = build_engine(mode, settings)
    memory_loaded = process_memory_mb()
    kb_text = json.dumps(json.loads(settings.knowledge_path.read_text(encoding="utf-8")), ensure_ascii=False)
    kb_numbers = set(_NUMBER.findall(kb_text))
    engine.chat("calentamiento")  # excluye la primera llamada (cachés) de los tiempos
    rows = evaluate_items(engine, dataset, kb_numbers)
    convs = evaluate_conversations(engine, conversations, kb_numbers)
    memory_after = process_memory_mb()

    by_category: dict[str, list[dict]] = defaultdict(list)
    for r in rows:
        by_category[r["category"]].append(r)
    metrics = {
        "all": block(rows),
        "dev": block([r for r in rows if r["split"] == "dev"]),
        "test": block([r for r in rows if r["split"] == "test"]),
        "sprint2_dev": block([r for r in rows if r["added_in"] == "sprint2" and r["split"] == "dev"]),
        "sprint2_test": block([r for r in rows if r["added_in"] == "sprint2" and r["split"] == "test"]),
        "by_category": {
            c: {"n": len(v), "behavior_accuracy": _ratio(sum(r["behavior_ok"] for r in v), len(v))}
            for c, v in sorted(by_category.items())
        },
        "conversations": {
            "all": conversation_block(convs),
            "dev": conversation_block([c for c in convs if c["split"] == "dev"]),
            "test": conversation_block([c for c in convs if c["split"] == "test"]),
        },
    }
    return {
        "mode": engine.mode,
        "knowledge_version": engine.kb.version,
        "dataset_version": dataset["version"],
        "conversations_version": conversations["version"],
        "origins": sorted({r["origin"] for r in rows}),
        "model_load_seconds": load_s,
        "memory_mb": {"before_load": memory_before, "after_load": memory_loaded, "after_run": memory_after},
        "metrics": metrics,
        "rows": rows,
        "conversations": convs,
    }


KEYS = [
    "n",
    "behavior_accuracy",
    "intent_accuracy",
    "retrieval_hit@1",
    "retrieval_hit@3",
    "recommendation_precision@1",
    "abstention_recall",
    "abstention_precision",
    "ungrounded_responses",
    "latency_ms_p50",
    "latency_ms_p95",
    "latency_ms_max",
]


def to_markdown(report: dict) -> str:
    m = report["metrics"]
    cols = ["all", "dev", "test", "sprint2_dev", "sprint2_test"]
    lines = [
        f"# Evaluación Horus AI Advisor — modo {report['mode']}",
        "",
        f"Conocimiento {report['knowledge_version']} · dataset {report['dataset_version']} · "
        f"conversaciones {report['conversations_version']} · origen: {', '.join(report['origins'])} (sintético)",
        "",
        f"Carga del modelo: {report['model_load_seconds']} s · memoria (MB): {report['memory_mb']}",
        "",
        "| Métrica | " + " | ".join(cols) + " |",
        "| --- |" + " --- |" * len(cols),
    ]
    lines += [f"| {k} | " + " | ".join(str(m[c][k]) for c in cols) + " |" for k in KEYS]
    lines += [
        "",
        "## Conversaciones",
        "",
        "| Split | escenarios | éxito | turnos | exactitud por turno | sin fundamento |",
    ]
    lines += ["| --- | --- | --- | --- | --- | --- |"]
    for split, c in m["conversations"].items():
        lines.append(
            f"| {split} | {c['scenarios']} | {c['scenario_success']} | {c['turns']} | {c['turn_accuracy']} | {c['ungrounded_turns']} |"
        )
    lines += ["", "## Por categoría", "", "| Categoría | n | behavior_accuracy |", "| --- | --- | --- |"]
    lines += [f"| {c} | {v['n']} | {v['behavior_accuracy']} |" for c, v in m["by_category"].items()]
    failures = [r for r in report["rows"] if not r["behavior_ok"] or r["ungrounded"]]
    lines += [
        "",
        f"## Fallos en consultas sueltas ({len(failures)})",
        "",
        "| id | split | consulta | esperado | obtenido |",
        "| --- | --- | --- | --- | --- |",
    ]
    for r in failures:
        got = f"{r['intent']} {r['ids'][:2]}" + (f" ⚠ {r['ungrounded']}" if r["ungrounded"] else "")
        lines.append(
            f"| {r['id']} | {r['split']} | {r['query']} | {'/'.join(r['expected_behaviors'])} {r['expected_ids'][:2]} | {got} |"
        )
    lines += ["", "## Turnos fallidos", "", "| escenario | turno | obtenido |", "| --- | --- | --- |"]
    for s in report["conversations"]:
        for t in s["turns"]:
            if not t["ok"] or t["ungrounded"]:
                lines.append(
                    f"| {s['id']} | {t['user']} | {t['intent']} {t['ids'][:2]} — {t['answer'][:90]!r} |"
                )
    return "\n".join(lines) + "\n"


def run_mode(mode: str, tag: str | None) -> dict:
    settings = Settings(_env_file=None)  # type: ignore[call-arg]
    dataset = json.loads((ROOT / "data" / "evaluation.json").read_text(encoding="utf-8"))
    conversations = json.loads((ROOT / "data" / "conversations.json").read_text(encoding="utf-8"))
    report = evaluate(mode, settings, dataset, conversations)
    out_dir = ROOT / "reports"
    out_dir.mkdir(exist_ok=True)
    name = f"evaluation-{mode}" + (f"-{tag}" if tag else "")
    (out_dir / f"{name}.json").write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    (out_dir / f"{name}.md").write_text(to_markdown(report), encoding="utf-8")
    m = report["metrics"]
    print(
        f"\n== modo {report['mode']} (carga {report['model_load_seconds']} s, memoria {report['memory_mb']} MB) =="
    )
    for split in ("all", "dev", "test", "sprint2_dev", "sprint2_test"):
        s = m[split]
        print(
            f"{split:>12}: n={s['n']} comp={s['behavior_accuracy']} int={s['intent_accuracy']} "
            f"hit@1={s['retrieval_hit@1']} rec@1={s['recommendation_precision@1']} "
            f"abst_r={s['abstention_recall']} abst_p={s['abstention_precision']} "
            f"inventadas={s['ungrounded_responses']} p50={s['latency_ms_p50']}ms p95={s['latency_ms_p95']}ms"
        )
    for split, c in m["conversations"].items():
        print(
            f"{'conv-' + split:>12}: escenarios={c['scenario_success']} turnos={c['turn_accuracy']} ({c['turns']})"
        )
    print(f"Informe: reports/{name}.md")
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description="Evalúa Horus AI Advisor")
    parser.add_argument("--mode", choices=["lexical", "hybrid", "both"], default="lexical")
    parser.add_argument("--tag", default=None, help="Sufijo del informe (p. ej. 'antes', 'despues')")
    args = parser.parse_args()
    if args.mode == "both":  # procesos separados: la memoria de un modo no contamina al otro
        cmd = [sys.executable, str(Path(__file__).resolve())]
        extra = ["--tag", args.tag] if args.tag else []
        for mode in ("lexical", "hybrid"):
            code = subprocess.call([*cmd, "--mode", mode, *extra])  # noqa: S603
            if code:
                return code
        return 0
    run_mode(args.mode, args.tag)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
