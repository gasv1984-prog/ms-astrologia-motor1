from fastapi.testclient import TestClient

from app.main import app


def test_compatible_birth_chart_api_returns_spanish_svg_and_data():
    with TestClient(app) as client:
        response = client.post(
            "/api/v5/chart/birth-chart",
            json={
                "subject": {
                    "name": "Ada Ejemplo",
                    "year": 1990,
                    "month": 5,
                    "day": 1,
                    "hour": 10,
                    "minute": 30,
                    "longitude": -74.0721,
                    "latitude": 4.711,
                    "timezone": "America/Bogota",
                },
                "language": "ES",
            },
        )

    assert response.status_code == 200
    payload = response.json()
    assert payload["status"] == "OK"
    assert payload["language"] == "ES"
    assert "<svg" in payload["chart"]
    assert payload["chart_data"]["chart_type"] == "Natal"

