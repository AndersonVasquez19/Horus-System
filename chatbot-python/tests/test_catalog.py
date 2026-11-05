"""Contrato del catálogo público futuro (Laravel -> FastAPI) con datos FICTICIOS."""

from __future__ import annotations

import copy
import json
from datetime import UTC, datetime
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.core.config import Settings
from app.main import create_app
from app.schemas.chat import HistoryItem
from app.services.catalog import CatalogError, load_catalog, sign, validate_payload
from app.services.chat_engine import AdvisorEngine
from app.services.knowledge import KnowledgeBase, KnowledgeError
from tests.conftest import TEST_TOKEN, make_settings

FIXTURE = Path(__file__).parent / "fixtures" / "catalogo_laravel_ficticio.json"
KEY = "clave-de-firma-ficticia-" + "k" * 24
NOW = datetime(2026, 10, 30, 12, 0, tzinfo=UTC)


def _raw() -> dict:
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def _write(tmp_path: Path, raw: dict, signed: bool = True) -> Path:
    data = copy.deepcopy(raw)
    if signed:
        data["signature"] = sign(data, KEY)
    path = tmp_path / "catalogo.json"
    path.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    return path


@pytest.fixture
def catalog_engine(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> AdvisorEngine:
    s = Settings(_env_file=None)  # type: ignore[call-arg]
    path = _write(tmp_path, _raw())
    monkeypatch.setattr("app.services.catalog.datetime", _FrozenDatetime)
    kb = KnowledgeBase.load(s.knowledge_path, s.intents_path, path, KEY)
    return AdvisorEngine(kb)


class _FrozenDatetime(datetime):
    @classmethod
    def now(cls, tz=None):  # type: ignore[no-untyped-def, override]
        return NOW if tz else NOW.replace(tzinfo=None)


def _all_text(reply) -> str:  # type: ignore[no-untyped-def]
    return json.dumps(reply.model_dump(), ensure_ascii=False)


def test_status_mapping(tmp_path: Path) -> None:
    load = load_catalog(_write(tmp_path, _raw()), KEY, now=NOW)
    by_stable = {r.stable_id: r for r in load.records if r.stable_id}
    assert by_stable["curso:redes-basico"].status == "verified"
    assert by_stable["curso:redes-basico"].recommendable
    assert by_stable["curso:camaras-instalacion"].status == "unavailable"
    assert by_stable["servicio:redes-wifi"].status == "provisional"
    assert by_stable["curso:enfermeria-uci"].status == "unavailable"  # fuera de su ventana
    assert "curso:excel-avanzado" not in by_stable  # no_publicado: descartado
    assert load.summary["outcome"]["descartado_no_publicado"] == 1
    assert not any(r.recommendable for r in load.records if r.status != "verified")


def test_unpublished_item_never_appears(catalog_engine: AdvisorEngine) -> None:
    for record in catalog_engine.kb.records:
        assert "ZETA" not in record.title + record.description + record.answer
    reply = catalog_engine.chat("¿Tienen el borrador zeta?")
    assert "ZETA" not in _all_text(reply)


def test_catalog_supersedes_unpublished_course_list(catalog_engine: AdvisorEngine) -> None:
    assert catalog_engine.kb.get("cursos_catalogo") is None
    reply = catalog_engine.chat("¿Qué cursos tienen?")
    assert reply.sources and reply.sources[0].id == "cat_listado_formacion"
    assert "[FICTICIO] Fundamentos de redes" in reply.answer
    assert "Instalación de cámaras" not in reply.answer  # retirado: no figura como vigente


def test_vigente_course_is_recommended_with_attributes(catalog_engine: AdvisorEngine) -> None:
    first = catalog_engine.chat("Soy principiante y quiero aprender redes")
    assert first.recommendations[0].id == "cat_curso_redes_basico"
    history = [HistoryItem(role="user", content="Soy principiante y quiero aprender redes")]
    price = catalog_engine.chat("¿Y cuánto cuesta?", history)
    assert "PEN 150.00" in price.answer and price.intent == "followup_attribute"
    reqs = catalog_engine.chat("¿y qué requisitos tiene?", history)
    assert "laptop propia" in reqs.answer


def test_retired_and_unknown_items_are_not_affirmed(catalog_engine: AdvisorEngine) -> None:
    retired = catalog_engine.chat("¿Tienen el curso de cámaras?")
    assert retired.abstained or retired.needs_clarification
    assert all(r.id != "cat_curso_camaras_instalacion" for r in retired.recommendations)
    unknown = catalog_engine.chat("¿Hacen auditoría de red inalámbrica?")
    assert all(s.id != "cat_servicio_redes_wifi" for s in unknown.sources)


def test_stale_catalog_is_not_presented_as_fact(tmp_path: Path) -> None:
    load = load_catalog(_write(tmp_path, _raw()), KEY, now=datetime(2027, 1, 10, tzinfo=UTC))
    assert load.summary["stale"] is True
    assert not any(r.status == "verified" for r in load.records)  # caducado: nada se afirma
    assert load.summary["outcome"]["vigente_pero_catalogo_caducado"] == 4


@pytest.mark.parametrize(
    ("mutation", "message"),
    [
        (lambda d: d["items"][0].update({"cotizacion_id": "Q-1"}), "campo no permitido"),
        (lambda d: d["items"][0].update({"estudiantes": ["Ana"]}), "campo no permitido"),
        (
            lambda d: d["items"][2].update(
                {"public_price": {"amount": "1.00", "currency": "PEN", "conditions": "x" * 5}}
            ),
            "precio en retirado",
        ),
        (lambda d: d["items"][0].update({"stable_id": "servicio:redes-basico"}), "prefijo distinto del tipo"),
        (lambda d: d.update({"expires_at": "2027-06-01T00:00:00-05:00"}), "vigencia > 90 días"),
        (lambda d: d["items"].append(copy.deepcopy(d["items"][0])), "stable_id duplicado"),
        (lambda d: d.update({"source_system": "otro"}), "sistema de origen"),
    ],
)
def test_invalid_catalogs_are_rejected(tmp_path: Path, mutation, message: str) -> None:  # type: ignore[no-untyped-def]
    raw = _raw()
    mutation(raw)
    with pytest.raises(CatalogError):
        load_catalog(_write(tmp_path, raw), KEY, now=NOW)


def test_private_contact_data_is_rejected(tmp_path: Path) -> None:
    raw = _raw()
    raw["items"][0]["summary"] = "Curso ficticio: escribe a docente.privado@example.com para inscribirte."
    s = Settings(_env_file=None)  # type: ignore[call-arg]
    with pytest.raises(KnowledgeError, match="privados"):
        KnowledgeBase.load(s.knowledge_path, s.intents_path, _write(tmp_path, raw), KEY)


def test_signature_is_required_and_checked(tmp_path: Path) -> None:
    raw = _raw()
    with pytest.raises(CatalogError, match="ausente"):
        load_catalog(_write(tmp_path, raw, signed=False), KEY, now=NOW)
    tampered = copy.deepcopy(raw)
    tampered["signature"] = sign(raw, KEY)
    tampered["items"][0]["title"] = "[FICTICIO] Título alterado"
    path = tmp_path / "alterado.json"
    path.write_text(json.dumps(tampered, ensure_ascii=False), encoding="utf-8")
    with pytest.raises(CatalogError, match="inválida"):
        load_catalog(path, KEY, now=NOW)
    with pytest.raises(CatalogError, match="clave"):
        load_catalog(_write(tmp_path, raw, signed=False), None, now=NOW)
    assert load_catalog(_write(tmp_path, raw, signed=False), None, allow_unsigned=True, now=NOW).records


def test_validate_payload_reports_without_loading() -> None:
    raw = _raw()
    report = validate_payload(raw, None, now=NOW)
    assert report["valid"] and report["signature"] == "not_checked"
    assert report["summary"]["outcome"]["vigente"] == 3
    bad = copy.deepcopy(raw)
    bad["items"][0]["precio_interno"] = 10
    report = validate_payload(bad, KEY, now=NOW)
    assert not report["valid"] and report["errors"] and report["signature"] == "missing"


def test_validate_endpoint_is_dry_run(tmp_path: Path) -> None:
    raw = _raw()
    for i in range(30):  # relleno ficticio para superar el límite general de 32 KB
        extra = copy.deepcopy(raw["items"][1])
        extra.update(
            {"stable_id": f"capacitacion:relleno-{i}", "details": "Detalle ficticio de relleno. " * 50}
        )
        raw["items"].append(extra)
    with TestClient(create_app(make_settings())) as c:
        headers = {"X-Horus-Service-Token": TEST_TOKEN}
        body = json.dumps(raw, ensure_ascii=False).encode()
        assert len(body) > 32768
        chat = c.post("/api/v1/chat", content=body, headers={**headers, "Content-Type": "application/json"})
        assert chat.status_code == 413  # el límite ampliado solo vale para la validación del catálogo
        r = c.post(
            "/api/v1/catalog/validate", content=body, headers={**headers, "Content-Type": "application/json"}
        )
        assert r.status_code == 200, r.text
        assert r.json()["loaded"] is False
        assert c.get("/api/v1/info", headers=headers).json()["catalog_version"] is None
        assert c.get("/ready").json()["catalog"] == "none"
        unauth = c.post("/api/v1/catalog/validate", json=raw)
        assert unauth.status_code == 401


def test_bad_catalog_keeps_service_on_verified_base(tmp_path: Path) -> None:
    raw = _raw()
    path = _write(tmp_path, raw)
    path.write_text(path.read_text(encoding="utf-8").replace("Fundamentos", "Alterado"), encoding="utf-8")
    settings = make_settings(catalog_path=path, catalog_signing_key=KEY)
    with TestClient(create_app(settings)) as c:
        assert c.get("/ready").json()["catalog"] == "error"
        r = c.post(
            "/api/v1/chat", json={"message": "¿Aceptan Yape?"}, headers={"X-Horus-Service-Token": TEST_TOKEN}
        )
        assert r.json()["sources"][0]["id"] == "faq_medios_pago"


def test_signed_catalog_loads_in_app(tmp_path: Path) -> None:
    path = _write(tmp_path, _raw())
    settings = make_settings(catalog_path=path, catalog_signing_key=KEY)
    with TestClient(create_app(settings)) as c:
        assert c.get("/ready").json()["catalog"] in {"loaded", "stale"}
        info = c.get("/api/v1/info", headers={"X-Horus-Service-Token": TEST_TOKEN}).json()
        assert info["catalog_version"] == "2026.10.29-ficticio"


def test_empty_catalog_settings_mean_no_catalog() -> None:
    settings = Settings(catalog_path="", catalog_signing_key="", _env_file=None)  # type: ignore[call-arg]
    assert settings.catalog_path is None and settings.catalog_key is None
