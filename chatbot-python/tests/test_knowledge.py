from __future__ import annotations

import copy
import json
from pathlib import Path

import pytest

from app.core.config import PROJECT_ROOT
from app.services.knowledge import KnowledgeBase, KnowledgeError, find_privacy_violations, load_knowledge_file

REPO_ROOT = PROJECT_ROOT.parent
KNOWLEDGE = PROJECT_ROOT / "data" / "knowledge.json"


def _raw() -> dict:
    return json.loads(KNOWLEDGE.read_text(encoding="utf-8"))


def _write(tmp_path: Path, data: dict) -> Path:
    path = tmp_path / "knowledge.json"
    path.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    return path


def test_knowledge_loads_and_has_verified_records(kb: KnowledgeBase) -> None:
    assert len(kb.verified()) >= 30
    assert {r.status for r in kb.records} == {"verified", "provisional", "unavailable"}


def test_only_verified_records_are_recommendable(kb: KnowledgeBase) -> None:
    assert kb.recommendable()
    assert all(r.status == "verified" for r in kb.recommendable())


def test_sources_point_to_existing_files(kb: KnowledgeBase) -> None:
    """Cada fuente cita archivos reales de Horus-System (lectura, sin modificarlos)."""
    for record in kb.records:
        for part in record.source.file.split(";"):
            assert (REPO_ROOT / part.strip()).exists(), f"{record.id}: fuente inexistente {part!r}"


def test_public_url_paths_exist_in_laravel_routes(kb: KnowledgeBase) -> None:
    routes = (REPO_ROOT / "routes" / "web.php").read_text(encoding="utf-8")
    for record in kb.records:
        path = (record.source.url_path or "").split("#")[0]
        if path and path != "/":
            assert f"'{path}'" in routes, f"{record.id}: {path} no figura en routes/web.php"


def test_no_private_data_in_knowledge(kb: KnowledgeBase) -> None:
    assert find_privacy_violations(kb.data) == []


def test_knowledge_has_no_excluded_modules(kb: KnowledgeBase) -> None:
    text = json.dumps(_raw(), ensure_ascii=False).lower()
    for word in ("docente", "sesiones de clase", "módulo de certificados", "estudiante:", "matriculado"):
        assert word not in text


def test_no_named_courses_invented(kb: KnowledgeBase) -> None:
    """La web pública no publica cursos con nombre: no debe existir ningún registro de tipo 'course'."""
    assert not [r.id for r in kb.records if r.kind == "course"]


def test_privacy_check_rejects_unknown_email(tmp_path: Path) -> None:
    data = _raw()
    data["records"][0]["answer"] = "Escribe a juan.perez@example.com para inscribirte."
    with pytest.raises(KnowledgeError, match="privados"):
        load_knowledge_file(_write(tmp_path, data))


def test_privacy_check_rejects_unknown_phone(tmp_path: Path) -> None:
    data = _raw()
    data["records"][0]["description"] = "Llama al +51 999 888 777 para más datos de la empresa."
    with pytest.raises(KnowledgeError):
        load_knowledge_file(_write(tmp_path, data))


@pytest.mark.parametrize("field", ["id", "title", "source", "status", "keywords", "answer"])
def test_incomplete_record_rejected(tmp_path: Path, field: str) -> None:
    data = _raw()
    del data["records"][0][field]
    with pytest.raises(KnowledgeError):
        load_knowledge_file(_write(tmp_path, data))


def test_unknown_status_rejected(tmp_path: Path) -> None:
    data = _raw()
    data["records"][0]["status"] = "probably_true"
    with pytest.raises(KnowledgeError):
        load_knowledge_file(_write(tmp_path, data))


def test_recommendable_must_be_verified(tmp_path: Path) -> None:
    data = _raw()
    record = next(r for r in data["records"] if r["status"] == "provisional")
    record["recommendable"] = True
    record["topics"] = record.get("topics") or ["formacion"]
    with pytest.raises(KnowledgeError):
        load_knowledge_file(_write(tmp_path, data))


def test_duplicate_ids_and_broken_relations_rejected(tmp_path: Path) -> None:
    data = _raw()
    dup = copy.deepcopy(data)
    dup["records"].append(copy.deepcopy(dup["records"][0]))
    with pytest.raises(KnowledgeError):
        load_knowledge_file(_write(tmp_path, dup))
    broken = copy.deepcopy(data)
    broken["records"][0]["related"] = ["registro_inexistente"]
    with pytest.raises(KnowledgeError):
        load_knowledge_file(_write(tmp_path, broken))


def test_unexpected_field_rejected(tmp_path: Path) -> None:
    data = _raw()
    data["records"][0]["precio"] = "S/ 100"
    with pytest.raises(KnowledgeError):
        load_knowledge_file(_write(tmp_path, data))


def test_engine_never_presents_unverified_as_fact(engine) -> None:  # type: ignore[no-untyped-def]
    """Para cada registro no verificado, preguntar por él produce abstención y ninguna fuente no verificada."""
    for record in engine.kb.records:
        if record.status == "verified":
            continue
        reply = engine.chat(record.keywords[0])
        cited = {s.id for s in reply.sources}
        assert record.id not in cited
        assert all(engine.kb.get(i).status == "verified" for i in cited)  # type: ignore[union-attr]
        assert reply.abstained or reply.needs_clarification, (record.id, reply.intent)
