from pathlib import Path

from app.astrology import calculate_natal_chart


def test_natal_chart_generates_data_and_svg(tmp_path: Path):
    record = {
        "id": 42,
        "full_name": "Ada Ejemplo",
        "birth_date": "1990-05-01",
        "birth_time": "10:30",
        "latitude": 4.711,
        "longitude": -74.0721,
        "timezone": "America/Bogota",
    }
    chart, relative_svg = calculate_natal_chart(record, tmp_path)

    assert chart["chart_type"] == "Natal"
    assert "subject" in chart
    assert "aspects" in chart
    assert relative_svg == "generated/carta-42.svg"
    assert (tmp_path / "carta-42.svg").stat().st_size > 10_000
