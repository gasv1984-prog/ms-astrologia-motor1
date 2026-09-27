from pathlib import Path

from app.delivery import is_ready
from app.main import render_markdown
from app.pdf_report import generate_reading_pdf
from app.security import create_result_token, verify_result_token


def sample_record():
    return {
        "public_id": "demo-seguro",
        "full_name": "Persona Ejemplo",
        "birth_date": "1990-05-01",
        "birth_time": "10:30",
        "birthplace": "Bogota, Colombia",
        "timezone": "America/Bogota",
        "payment_status": "paid",
        "chart_json": "{}",
        "chart_svg_path": "",
        "ai_interpretation": "### Sintesis\n\n**Fortaleza:** claridad interior.",
    }


def test_result_token_is_signed():
    token = create_result_token("demo-seguro")
    assert verify_result_token("demo-seguro", token)
    assert not verify_result_token("otra-solicitud", token)
    assert not verify_result_token("demo-seguro", "alterado")


def test_markdown_is_formatted_and_html_is_escaped():
    rendered = str(render_markdown("### Titulo\n\n**Texto** <script>alert(1)</script>"))
    assert "<h3>Titulo</h3>" in rendered
    assert "<strong>Texto</strong>" in rendered
    assert "<script>" not in rendered


def test_result_requires_payment_chart_and_interpretation():
    record = sample_record()
    assert is_ready(record)
    record["payment_status"] = "pending"
    assert not is_ready(record)


def test_pdf_is_generated_without_markdown_marks(tmp_path):
    path = generate_reading_pdf(sample_record(), tmp_path, Path("app/static"))
    assert path.read_bytes().startswith(b"%PDF")
    assert path.stat().st_size > 2000
