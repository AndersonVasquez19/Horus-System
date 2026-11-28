"""Clasificador de respaldo (27/11/2026): solo actúa cuando el motor iba a pedir aclaración y responde con fichas."""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest

from app.core.config import PROJECT_ROOT, Settings
from app.core.text import normalize
from app.main import load_classifier
from app.services.chat_engine import AdvisorEngine
from app.services.intent_classifier import OTHER, IntentClassifier
from app.services.knowledge import KnowledgeBase
from tests.test_phase_c import make_engine

sys.path.insert(0, str(PROJECT_ROOT / "scripts"))
import entrenar_clasificador as trainer  # noqa: E402

ARTIFACT = PROJECT_ROOT / "data" / "clasificador.json"


@pytest.fixture(scope="module")
def classifier() -> IntentClassifier:
    return IntentClassifier.load(ARTIFACT)


CATALOG = PROJECT_ROOT.parent / "api" / "tests" / "Fixtures" / "chat-jurado-catalogo.json"


@pytest.fixture()
def engine() -> AdvisorEngine:
    # El mismo catálogo de prueba que scripts/medir_oraciones.py.
    return make_engine(json.loads(CATALOG.read_text(encoding="utf-8"))["items"])


@pytest.fixture()
def with_classifier(classifier: IntentClassifier) -> AdvisorEngine:
    built = make_engine(json.loads(CATALOG.read_text(encoding="utf-8"))["items"])
    built.classifier = classifier
    return built


def test_solo_propone_fichas_que_existen(kb: KnowledgeBase, classifier: IntentClassifier) -> None:
    assert set(classifier.classes) - {OTHER} <= {r.id for r in kb.records}


@pytest.mark.parametrize(("query", "record_id"), [
    # Las dos primeras son del examen data/oraciones_examen.json, que nunca se usa para entrenar.
    ("Yo vivo en Lima, puedo llevar sus cursos desde aquí o necesariamente tengo que viajar a Cajamarca",
     "alcance_nacional_virtual"),
    ("Quiero formalizar mi negocio y no sé por dónde empezar, brindan asesoría a emprendedores",
     "asesoramiento_empresarial"),
    ("¿Me dan recibo?", "faq_comprobantes"),
])
def test_una_aclaracion_se_cambia_por_la_ficha_reconocida(
    engine: AdvisorEngine, with_classifier: AdvisorEngine, query: str, record_id: str
) -> None:
    assert engine.chat(query).intent == "clarification"
    reply = with_classifier.chat(query)
    record = with_classifier.kb.get(record_id)
    assert [s.id for s in reply.sources] == [record_id]
    assert reply.answer == record.answer  # texto exacto de la ficha: Laravel lo valida igual que cualquier otro
    assert not reply.needs_clarification


@pytest.mark.parametrize("query", ["Cuéntame un chiste", "¿Qué equipo ganará la Liga 1?", "asdf qwerty"])
def test_lo_ajeno_no_recibe_una_ficha(with_classifier: AdvisorEngine, query: str) -> None:
    assert not with_classifier.chat(query).sources


@pytest.mark.parametrize("query", ["¿Dónde están ubicados?", "hola", "¿Qué cursos tienen?", "¿Cuál es el horario de atención?"])
def test_no_cambia_lo_que_el_motor_ya_decide(engine: AdvisorEngine, with_classifier: AdvisorEngine, query: str) -> None:
    assert with_classifier.chat(query).model_dump() == engine.chat(query).model_dump()


def test_apagado_por_defecto_y_sin_archivo_el_motor_sigue(tmp_path: Path) -> None:
    assert load_classifier(Settings(_env_file=None)) is None  # type: ignore[call-arg]
    missing = Settings(_env_file=None, classifier_enabled=True, classifier_path=tmp_path / "no.json")  # type: ignore[call-arg]
    assert load_classifier(missing) is None
    assert load_classifier(Settings(_env_file=None, classifier_enabled=True)) is not None  # type: ignore[call-arg]


def test_un_archivo_truncado_se_rechaza(tmp_path: Path) -> None:
    raw = json.loads(ARTIFACT.read_text(encoding="utf-8"))
    raw["weights"][0] = raw["weights"][0][:100]
    with pytest.raises(ValueError):
        IntentClassifier(raw)


def test_nunca_entrena_con_los_examenes() -> None:
    assert not set(trainer.TRAIN_BANKS) & set(trainer.HELD_OUT)
    trained = {normalize(t) for t, _ in trainer.training_set()}
    assert not trained & trainer.held_out_texts()


def test_la_inferencia_en_python_puro_coincide_con_scikit_learn(classifier: IntentClassifier) -> None:
    pytest.importorskip("sklearn")
    rows = trainer.training_set()
    union, model = trainer.train(rows)
    sample = [normalize(t) for t, _ in rows[::25]]
    expected = model.decision_function(union.transform(sample))
    for row, text in zip(expected, sample, strict=True):
        assert classifier.scores(text) == pytest.approx(list(row), abs=1e-3)
