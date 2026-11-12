"""Comparación reproducible: chatbot PHP (motor local) vs Horus AI Advisor sobre las MISMAS consultas.

Uso (PowerShell, desde chatbot-python/):
    .venv\\Scripts\\python.exe scripts\\evaluate.py --mode both --tag despues   # informes de Python
    .venv\\Scripts\\python.exe scripts\\compare_php.py --python-tag despues

Condiciones (ver scripts/php_compare/php_matcher.php):
  - Solo el motor LOCAL del PHP: intenciones incorporadas (ChatKnowledge), sin intenciones de la base
    de datos, sin OpenAI (clave vacía -> respuesta de respaldo), sin catálogo dinámico (apagado por
    defecto en Laravel), sin caché de duplicados. No se modifica ni se conecta nada real.
  - El PHP no expone fuentes ni recomendaciones: sus intenciones se traducen a registros con la tabla
    PHP_INTENT_RECORDS (auditable). La puntuación es la misma función para ambos motores, con reglas
    de equivalencia explícitas para lo que el PHP no puede expresar (ver `php_matches`).
  - Todas las consultas son sintéticas. El resultado compara motores, no satisfacción de usuarios.
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LARAVEL_ROOT = ROOT.parent
sys.path.insert(0, str(ROOT))

from app.core.text import normalize  # noqa: E402

PHP_INTENT_RECORDS: dict[str, list[str]] = {
    "consulta_requisitos": ["no_disponible_requisitos"],
    "consulta_horarios_cursos": ["no_disponible_fechas"],
    "consulta_servicios": ["servicios_resumen"],
    "derivar_humano": ["contacto_canales"],
    "consulta_precios": ["no_disponible_precios"],
    "consulta_evaluaciones": ["faq_evaluaciones"],
    "consulta_recuperacion_practica": ["faq_practica_perdida"],
    "consulta_modalidades": ["cursos_modalidades"],
    "consulta_certificados": ["cursos_certificados"],
    "consulta_matricula": ["no_disponible_requisitos"],
    "consulta_pagos": ["faq_medios_pago"],
    "consulta_comprobantes": ["faq_comprobantes"],
    "consulta_pedido": ["faq_pedido"],
    "consulta_devoluciones": ["politica_devoluciones"],
    "consulta_camaras": ["servicio_camaras"],
    "consulta_cableado": ["servicio_cableado"],
    "consulta_soporte": ["servicio_soporte"],
    "consulta_capacitaciones": ["capacitaciones_empresariales", "formacion_personalizada"],
    "consulta_cursos": ["cursos_modalidades", "cursos_catalogo"],
    "consulta_horarios": ["horario_atencion"],
    "consulta_ubicacion": ["ubicacion_sede"],
    "consulta_contacto": ["contacto_canales"],
    "consulta_empresa": ["empresa_descripcion"],
}
PHP_ABSTAIN_INTENTS = {
    "consulta_requisitos",
    "consulta_horarios_cursos",
    "consulta_precios",
    "consulta_matricula",
}
PHP_TEMPLATES = {"saludo", "despedida"}


def php_matches(reply: dict, behavior: str, expected: list[str]) -> bool:
    """Misma semántica que evaluate.matches_behavior, adaptada a lo que el PHP devuelve."""
    intent, fuente = reply.get("intent"), reply.get("fuente")
    ids = PHP_INTENT_RECORDS.get(intent or "", [])
    hit = not expected or bool(set(ids) & set(expected))
    if behavior == "answer":
        return fuente == "local" and intent not in PHP_ABSTAIN_INTENTS | PHP_TEMPLATES and bool(ids) and hit
    if behavior == "recommend":  # equivalencia: responder sobre la opción correcta
        return fuente == "local" and bool(ids) and bool(expected) and hit
    if behavior == "recommend_none":  # no afirma una oferta concreta
        return fuente in {"respaldo", "aclaracion"} or intent in {
            "consulta_cursos",
            "consulta_capacitaciones",
        }
    if behavior == "clarify":
        return fuente == "aclaracion"
    if behavior == "abstain":
        return fuente == "respaldo" or intent in PHP_ABSTAIN_INTENTS
    if behavior == "refuse":  # el PHP bloquea y responde con su texto de respaldo
        return fuente == "respaldo" and intent is None
    if behavior == "template":
        return intent in PHP_TEMPLATES
    if behavior == "quote_guidance":  # el PHP deriva cotizaciones con su intención de precios
        return intent in {"consulta_precios", "derivar_humano"}
    if behavior == "followup":
        return fuente == "local" and bool(ids) and hit
    if behavior == "answer_check":
        return True
    raise ValueError(behavior)


def content_ok(text: str, spec: dict) -> bool:
    folded = normalize(text)
    if spec.get("must_contain_any") and not any(normalize(s) in folded for s in spec["must_contain_any"]):
        return False
    return not any(normalize(s) in folded for s in spec.get("must_not_contain", []))


def build_input(dataset: dict, conversations: dict) -> dict:
    items = [
        {
            "id": i["id"],
            "query": i["query"],
            "history": [h["content"] for h in i.get("history", []) if h["role"] == "user"],
        }
        for i in dataset["items"]
    ]
    convs = [{"id": c["id"], "turns": [t["user"] for t in c["scenarios_turns"]]} for c in conversations]
    return {"items": items, "conversations": convs}


def run_php(payload: dict, work: Path, php: str) -> dict:
    work.mkdir(parents=True, exist_ok=True)
    source, target = work / "php-input.json", work / "php-output.json"
    source.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    script = ROOT / "scripts" / "php_compare" / "php_matcher.php"
    done = subprocess.run(  # noqa: S603
        [php, str(script), str(LARAVEL_ROOT), str(source), str(target)],
        capture_output=True,
        text=True,
        timeout=300,
        cwd=str(LARAVEL_ROOT),
        check=False,
    )
    if done.returncode != 0:
        raise SystemExit(f"El puente PHP terminó con código {done.returncode}: {done.stderr.strip()[:500]}")
    print(done.stdout.strip())
    return json.loads(target.read_text(encoding="utf-8"))


def _ratio(a: int, b: int) -> float | None:
    return round(a / b, 3) if b else None


def main() -> int:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument("--python-tag", default="despues")
    parser.add_argument("--php", default=shutil.which("php") or "php")
    args = parser.parse_args()

    dataset = json.loads((ROOT / "data" / "evaluation.json").read_text(encoding="utf-8"))
    conversations = json.loads((ROOT / "data" / "conversations.json").read_text(encoding="utf-8"))[
        "scenarios"
    ]
    for c in conversations:
        c["scenarios_turns"] = c["turns"]
    php_out = run_php(build_input(dataset, conversations), ROOT / "reports" / "php", args.php)
    php_by_id = {r["id"]: r for r in php_out["results"]}

    python_reports = {}
    for mode in ("lexical", "hybrid"):
        path = ROOT / "reports" / f"evaluation-{mode}-{args.python_tag}.json"
        if path.exists():
            python_reports[mode] = json.loads(path.read_text(encoding="utf-8"))

    rows = []
    for item in dataset["items"]:
        reply = php_by_id[item["id"]]
        ok = any(php_matches(reply, b, item["expected_ids"]) for b in item["behaviors"])
        rows.append(
            {
                "id": item["id"],
                "split": item["split"],
                "category": item["category"],
                "ok": ok,
                "intent": reply.get("intent"),
                "fuente": reply.get("fuente"),
                "ms": reply.get("ms", 0.0),
            }
        )
    conv_rows = []
    for c in conversations:
        turns = php_by_id[c["id"]]["turns"]
        oks = [
            any(php_matches(t, b, spec.get("expected_ids", [])) for b in spec["behaviors"])
            and content_ok(t["respuesta"], spec)
            for t, spec in zip(turns, c["turns"], strict=True)
        ]
        conv_rows.append({"id": c["id"], "split": c["split"], "turn_oks": oks})

    def acc(subset: list[dict]) -> float | None:
        return _ratio(sum(r["ok"] for r in subset), len(subset))

    splits = {
        "all": rows,
        "dev": [r for r in rows if r["split"] == "dev"],
        "test": [r for r in rows if r["split"] == "test"],
    }
    summary: dict = {"php": {k: acc(v) for k, v in splits.items()}}
    for mode, report in python_reports.items():
        summary[mode] = {k: report["metrics"][k]["behavior_accuracy"] for k in ("all", "dev", "test")}
    cats = defaultdict(list)
    for r in rows:
        cats[r["category"]].append(r)
    by_cat = {c: {"n": len(v), "php": acc(v)} for c, v in sorted(cats.items())}
    for mode, report in python_reports.items():
        for c, v in report["metrics"]["by_category"].items():
            by_cat.setdefault(c, {})[mode] = v["behavior_accuracy"]
    conv_summary = {
        "php": {
            "turn_accuracy": _ratio(
                sum(sum(r["turn_oks"]) for r in conv_rows), sum(len(r["turn_oks"]) for r in conv_rows)
            ),
            "scenario_success": _ratio(sum(all(r["turn_oks"]) for r in conv_rows), len(conv_rows)),
        }
    }
    for mode, report in python_reports.items():
        conv_summary[mode] = {
            "turn_accuracy": report["metrics"]["conversations"]["all"]["turn_accuracy"],
            "scenario_success": report["metrics"]["conversations"]["all"]["scenario_success"],
        }
    fallback = sum(r["fuente"] == "respaldo" for r in rows)
    out = {
        "conditions": __doc__,
        "summary": summary,
        "by_category": by_cat,
        "conversations": conv_summary,
        "php_fallback_responses": fallback,
        "php_latency_ms_p50": sorted(r["ms"] for r in rows)[len(rows) // 2],
        "rows": rows,
    }
    (ROOT / "reports" / "comparison-php.json").write_text(
        json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    lines = [
        "# Comparación PHP (motor local) vs Horus AI Advisor",
        "",
        "Consultas sintéticas; ver condiciones en scripts/compare_php.py.",
        "",
        "| Motor | todos | dev | test | turnos | escenarios |",
        "| --- | --- | --- | --- | --- | --- |",
    ]
    for engine, values in summary.items():
        c = conv_summary.get(engine, {})
        lines.append(
            f"| {engine} | {values['all']} | {values['dev']} | {values['test']} | {c.get('turn_accuracy')} | {c.get('scenario_success')} |"
        )
    lines += ["", "| Categoría | n | php | lexical | hybrid |", "| --- | --- | --- | --- | --- |"]
    lines += [
        f"| {c} | {v.get('n')} | {v.get('php')} | {v.get('lexical')} | {v.get('hybrid')} |"
        for c, v in by_cat.items()
    ]
    lines += ["", f"Respuestas de respaldo del PHP (sin coincidencia local): {fallback} de {len(rows)}."]
    (ROOT / "reports" / "comparison-php.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(
        json.dumps(
            {"summary": summary, "conversations": conv_summary, "php_fallback": fallback},
            ensure_ascii=False,
            indent=1,
        )
    )
    print("Informe: reports/comparison-php.md")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
