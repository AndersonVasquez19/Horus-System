"""Mide cómo responde el motor a oraciones largas (data/oraciones_examen.json, u otro banco con --archivo).

Si el archivo trae «conversaciones» (data/oraciones_visitante.json), cada turno cuenta como un caso y el estado de la
respuesta se reenvía al turno siguiente, como hace la web.

Cada caso termina en una de cuatro categorías:
  - acierta: cita una de las fichas esperadas (o, si se esperaba, dice con honestidad que no tiene el dato o que el
    tema está fuera de su alcance);
  - aclara: pide una aclaración en lugar de responder;
  - equivocada: responde otra cosa (lo más grave: el visitante recibe información que no pidió);
  - bloqueada: el filtro de privacidad de Laravel no la deja pasar (solo con --filtro, necesita PHP).

Uso, desde apps/ai:
    .venv\\Scripts\\python.exe scripts\\medir_oraciones.py [--filtro] [--detalle] [--archivo ruta.json]

El catálogo es el de prueba (apps/api/tests/Fixtures/chat-jurado-catalogo.json): no consulta producción ni guarda
nada de lo que se mide.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from collections import Counter, defaultdict
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(RAIZ))
os.environ.setdefault("HORUS_AI_SEMANTIC_ENABLED", "false")

from tests.test_phase_c import make_engine  # noqa: E402

API = RAIZ.parent / "api"
CATALOGO = API / "tests" / "Fixtures" / "chat-jurado-catalogo.json"
# Lo que el asistente no debe ofrecer ni mencionar (decisión del propietario: solo educación).
SERVICIOS = re.compile(r"c[aá]maras?|cableado|soporte t[eé]cnico|mantenimiento|reparaci[oó]n", re.IGNORECASE)
CORTESIA = {"greeting", "thanks", "farewell", "acknowledgement"}


def motor():
    return make_engine(json.loads(CATALOGO.read_text(encoding="utf-8"))["items"])


def filtro_privacidad(textos: list[str]) -> list[str | None]:
    """Motivo de bloqueo de FiltroPrivacidadChat para cada texto (None si pasa)."""
    codigo = (
        "require 'vendor/autoload.php'; $f = new App\\Support\\FiltroPrivacidadChat;"
        "foreach (json_decode(stream_get_contents(STDIN), true) as $m) { echo ($f->motivoBloqueo($m) ?? '-'), \"\\n\"; }"
    )
    # PHP del PATH, el mismo con que se ejecutan las pruebas de Laravel; solo lee, no toca bases.
    salida = subprocess.run(["php", "-r", codigo], input=json.dumps(textos), capture_output=True, text=True,  # noqa: S607
                            cwd=API, encoding="utf-8", check=True).stdout.split()
    if len(salida) != len(textos):
        raise RuntimeError("El filtro de privacidad no devolvió un resultado por texto")
    return [None if m == "-" else m for m in salida]


def calificar(caso: dict, respuesta) -> str:
    esperado = set(caso["esperado"])
    ids = {s.id for s in respuesta.sources} | {r.id for r in respuesta.recommendations}
    if "FUERA" in esperado:
        sin_datos = not ids and respuesta.intent not in CORTESIA and not respuesta.needs_clarification
        if sin_datos and not SERVICIOS.search(respuesta.answer):
            return "acierta"
    if "NO_DATO" in esperado and respuesta.abstained and respuesta.intent in {"unavailable_info", "unverified_info"}:
        return "acierta"  # una abstención no afirma ningún dato, cite la ficha que cite
    if respuesta.intent in esperado:  # «capabilities», «thanks»…: la intención es la respuesta correcta
        return "acierta"
    if ids & esperado:
        return "acierta"
    if respuesta.needs_clarification:
        return "aclara"
    return "equivocada"


def medir(casos: list[dict], con_filtro: bool) -> tuple[list[dict], dict]:
    m = motor()
    motivos = filtro_privacidad([c["texto"] for c in casos]) if con_filtro else [None] * len(casos)
    filas = []
    for caso, motivo in zip(casos, motivos, strict=True):
        if motivo is not None:
            filas.append({**caso, "resultado": "bloqueada", "motivo": motivo})
            continue
        r = m.chat(caso["texto"], [], None)
        filas.append({**caso, "resultado": calificar(caso, r), "intent": r.intent,
                      "fuentes": [s.id for s in r.sources], "respuesta": r.clarification_question or r.answer})
    total = Counter(f["resultado"] for f in filas)
    return filas, total


def medir_conversaciones(conversaciones: list[list[dict]], con_filtro: bool) -> tuple[list[dict], Counter]:
    """Cada turno se califica como un caso; el estado de cada respuesta se reenvía al turno siguiente (como la web).

    Un turno bloqueado por el filtro no llega a Horus AI: el estado anterior se conserva.
    """
    m = motor()
    textos = [t["texto"] for c in conversaciones for t in c]
    motivos = iter(filtro_privacidad(textos) if con_filtro else [None] * len(textos))
    filas = []
    for numero, conversacion in enumerate(conversaciones, 1):
        estado = None
        for turno in conversacion:
            caso = {**turno, "tema": f"conversación {numero}"}
            motivo = next(motivos)
            if motivo is not None:
                filas.append({**caso, "resultado": "bloqueada", "motivo": motivo})
                continue
            r = m.chat(turno["texto"], [], estado)
            estado = r.state
            filas.append({**caso, "resultado": calificar(caso, r), "intent": r.intent,
                          "fuentes": [s.id for s in r.sources], "respuesta": r.clarification_question or r.answer})
    return filas, Counter(f["resultado"] for f in filas)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--archivo", type=Path, default=RAIZ / "data" / "oraciones_examen.json")
    parser.add_argument("--filtro", action="store_true", help="pasar también por el filtro de privacidad de Laravel")
    parser.add_argument("--detalle", action="store_true", help="mostrar cada caso que no acierta")
    args = parser.parse_args()
    sys.stdout.reconfigure(encoding="utf-8")

    datos = json.loads(args.archivo.read_text(encoding="utf-8"))
    filas, total = medir(datos["casos"], args.filtro)
    if datos.get("conversaciones"):
        turnos, total_turnos = medir_conversaciones(datos["conversaciones"], args.filtro)
        filas += [{**t, "tema": "conversaciones"} for t in turnos]
        total += total_turnos
    por_tema: dict[str, Counter] = defaultdict(Counter)
    for f in filas:
        por_tema[f["tema"]][f["resultado"]] += 1

    print(f"{'tema':16} {'casos':>5} {'acierta':>8} {'aclara':>7} {'equivoc':>8} {'bloq':>5}")
    for tema, c in por_tema.items():
        print(f"{tema:16} {sum(c.values()):5} {c['acierta']:8} {c['aclara']:7} {c['equivocada']:8} {c['bloqueada']:5}")
    n = len(filas)
    print(f"\nTOTAL {n}: acierta {total['acierta']} ({total['acierta'] / n:.0%}) · aclara {total['aclara']} "
          f"({total['aclara'] / n:.0%}) · equivocada {total['equivocada']} ({total['equivocada'] / n:.0%})"
          + (f" · bloqueada {total['bloqueada']} ({total['bloqueada'] / n:.0%})" if args.filtro else ""))
    if args.detalle:
        for f in filas:
            if f["resultado"] != "acierta":
                print(f"\n[{f['resultado']}] {f['texto']}\n   esperado: {f['esperado']}")
                if f["resultado"] == "bloqueada":
                    print(f"   motivo: {f['motivo']}")
                else:
                    print(f"   obtuvo: {f['intent']} {f['fuentes']} · {f['respuesta'][:140]!r}")


if __name__ == "__main__":
    main()
