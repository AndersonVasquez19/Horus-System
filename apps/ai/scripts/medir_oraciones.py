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
    .venv\\Scripts\\python.exe scripts\\medir_oraciones.py [--filtro] [--validar-laravel] [--detalle] [--estricto] [--archivo ruta.json]

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
VALIDADOR_LARAVEL = API / "scripts" / "horus-validar-contrato.php"
# Lo que el asistente no debe ofrecer ni mencionar (decisión del propietario: solo educación).
SERVICIOS = re.compile(r"c[aá]maras?|cableado|soporte t[eé]cnico|mantenimiento|reparaci[oó]n", re.IGNORECASE)
CORTESIA = {"greeting", "thanks", "farewell", "acknowledgement"}
# «IDIOMA»: en otro idioma basta el aviso de que solo responde en español.
AVISO_IDIOMA = re.compile(r"\bspanish\b|solo (?:puedo )?(?:responder|atender) en espa[nñ]ol", re.IGNORECASE)
# Datos que el asistente nunca debe afirmar por su cuenta (el «%XX» de un enlace codificado no es un porcentaje).
PRECIO = re.compile(r"S/\s?\d|\d+\s?soles|\d+\s?%(?![0-9A-F]{2})|US\$|\$\s?\d", re.IGNORECASE)
TELEFONO = re.compile(r"(?<!\d)9\d{2}\s?\d{3}\s?\d{3}(?!\d)")
CORREO = re.compile(r"[\w.+-]+@[\w-]+\.[\w.]+")
TELEFONOS_OFICIALES = {"972021374"}
CORREOS_OFICIALES = {"horusgroupcajamarca@gmail.com"}


def datos_sospechosos(texto: str) -> list[str]:
    """Precio, teléfono o correo no oficial en una respuesta: señal de dato inventado para revisar a mano."""
    sospechas = ["precio/porcentaje"] if PRECIO.search(texto) else []
    sospechas += [f"teléfono {t}" for t in TELEFONO.findall(texto) if re.sub(r"\s", "", t) not in TELEFONOS_OFICIALES]
    sospechas += [f"correo {c}" for c in CORREO.findall(texto) if c.lower().rstrip(".") not in CORREOS_OFICIALES]
    return sospechas


def resultado_bloqueo(caso: dict) -> str:
    """«BLOQUEO_OK»: el mensaje trae datos personales y el filtro hace bien en bloquearlo."""
    return "acierta" if "BLOQUEO_OK" in caso["esperado"] else "bloqueada"


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


def validar_contrato_laravel(casos_y_respuestas: list[dict]) -> list[dict]:
    """Valida entrada (privacidad/seguridad) y salida (respuestaValida/estadoValido) en Laravel sin tocar BD."""
    items = json.loads(CATALOGO.read_text(encoding="utf-8"))["items"]
    proc = subprocess.run(
        ["php", str(VALIDADOR_LARAVEL)],  # noqa: S607
        input=json.dumps({"items": items, "cases": casos_y_respuestas}, ensure_ascii=False),
        capture_output=True,
        text=True,
        cwd=API,
        encoding="utf-8",
        check=True,
    )
    datos = json.loads(proc.stdout)
    if len(datos) != len(casos_y_respuestas):
        raise RuntimeError("El validador de Laravel no devolvió un resultado por caso")
    return datos


def calificar(caso: dict, respuesta) -> str:
    esperado = set(caso["esperado"])
    ids = {s.id for s in respuesta.sources} | {r.id for r in respuesta.recommendations}
    if "FUERA" in esperado:
        sin_datos = not ids and respuesta.intent not in CORTESIA and not respuesta.needs_clarification
        if sin_datos and not SERVICIOS.search(respuesta.answer):
            return "acierta"
    if "NO_DATO" in esperado and respuesta.abstained and respuesta.intent in {"unavailable_info", "unverified_info"}:
        return "acierta"  # una abstención no afirma ningún dato, cite la ficha que cite
    if "IDIOMA" in esperado and AVISO_IDIOMA.search(respuesta.answer or ""):
        return "acierta"
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
            filas.append({**caso, "resultado": resultado_bloqueo(caso), "motivo": motivo, "_reply": None})
            continue
        r = m.chat(caso["texto"], [], None)
        filas.append({**caso, "resultado": calificar(caso, r), "intent": r.intent,
                      "fuentes": [s.id for s in r.sources], "respuesta": r.clarification_question or r.answer,
                      "_reply": r.model_dump(mode="json")})
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
                filas.append({**caso, "resultado": resultado_bloqueo(caso), "motivo": motivo, "_reply": None})
                continue
            r = m.chat(turno["texto"], [], estado)
            estado = r.state
            filas.append({**caso, "resultado": calificar(caso, r), "intent": r.intent,
                          "fuentes": [s.id for s in r.sources], "respuesta": r.clarification_question or r.answer,
                          "_reply": r.model_dump(mode="json")})
    return filas, Counter(f["resultado"] for f in filas)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--archivo", type=Path, default=RAIZ / "data" / "oraciones_examen.json")
    parser.add_argument("--filtro", action="store_true", help="pasar también por el filtro de privacidad de Laravel")
    parser.add_argument("--validar-laravel", action="store_true",
                        help="validar privacidad, seguridad, respuestaValida() y estadoValido() en Laravel")
    parser.add_argument("--estricto", action="store_true",
                        help="terminar con código distinto de 0 si Laravel rechaza alguna respuesta o estado")
    parser.add_argument("--detalle", action="store_true", help="mostrar cada caso que no acierta")
    parser.add_argument("--guardar", type=Path, help="escribir cada caso con su resultado en este JSON (fuera del repo)")
    args = parser.parse_args()
    sys.stdout.reconfigure(encoding="utf-8")

    usar_filtro = args.filtro or args.validar_laravel
    datos = json.loads(args.archivo.read_text(encoding="utf-8"))
    filas, total = medir(datos["casos"], usar_filtro)
    if datos.get("conversaciones"):
        turnos, total_turnos = medir_conversaciones(datos["conversaciones"], usar_filtro)
        filas += [{**t, "tema": "conversaciones"} for t in turnos]
        total += total_turnos

    rechazadas_laravel: list[dict] = []
    if args.validar_laravel:
        validaciones = validar_contrato_laravel([{"query": f["texto"], "reply": f["_reply"]} for f in filas])
        for f, v in zip(filas, validaciones, strict=True):
            if f["_reply"] is None or v["security"]:  # bloqueada por el filtro: no llegó a Horus AI
                continue
            if not v["reply_valid"] or not v["state_valid"]:
                f["laravel_error"] = "reply_valid=False" if not v["reply_valid"] else "state_valid=False"
                rechazadas_laravel.append(f)

    por_tema: dict[str, Counter] = defaultdict(Counter)
    for f in filas:
        por_tema[f["tema"]][f["resultado"]] += 1

    print(f"{'tema':16} {'casos':>5} {'acierta':>8} {'aclara':>7} {'equivoc':>8} {'bloq':>5}")
    for tema, c in por_tema.items():
        print(f"{tema:16} {sum(c.values()):5} {c['acierta']:8} {c['aclara']:7} {c['equivocada']:8} {c['bloqueada']:5}")
    n = len(filas)
    print(f"\nTOTAL {n}: acierta {total['acierta']} ({total['acierta'] / n:.0%}) · aclara {total['aclara']} "
          f"({total['aclara'] / n:.0%}) · equivocada {total['equivocada']} ({total['equivocada'] / n:.0%})"
          + (f" · bloqueada {total['bloqueada']} ({total['bloqueada'] / n:.0%})" if usar_filtro else "")
          + (f" · rechazadas_laravel {len(rechazadas_laravel)}" if args.validar_laravel else ""))
    # Una equivocada sin fuente es «no entendió» (dice que no tiene el dato); con fuente, responde otro tema.
    sin_dato = sum(1 for f in filas if f["resultado"] == "equivocada" and not f["fuentes"])
    print(f"   de las equivocadas: {sin_dato} dicen no tener el dato · {total['equivocada'] - sin_dato} responden otro tema")
    for f in filas:
        f["sospechas"] = datos_sospechosos(f["respuesta"]) if f["_reply"] is not None else []
    sospechosas = [f for f in filas if f["sospechas"]]
    print(f"   respuestas con precio, teléfono o correo no oficial (revisar a mano): {len(sospechosas)}")
    if args.guardar:
        campos = ("tema", "texto", "esperado", "resultado", "intent", "fuentes", "respuesta", "motivo", "sospechas",
                  "laravel_error")
        args.guardar.write_text(json.dumps([{k: f.get(k) for k in campos} for f in filas], ensure_ascii=False, indent=1),
                                encoding="utf-8")
    if args.detalle:
        for f in filas:
            if f["resultado"] != "acierta" or f.get("laravel_error"):
                etiqueta = f.get("laravel_error") or f["resultado"]
                print(f"\n[{etiqueta}] {f['texto']}\n   esperado: {f['esperado']}")
                if f["resultado"] == "bloqueada":
                    print(f"   motivo: {f['motivo']}")
                else:
                    print(f"   obtuvo: {f['intent']} {f['fuentes']} · {f['respuesta'][:140]!r}")
    if args.estricto and rechazadas_laravel:
        sys.exit(1)


if __name__ == "__main__":
    main()
