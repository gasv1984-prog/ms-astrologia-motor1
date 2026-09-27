from types import SimpleNamespace

from fastapi.testclient import TestClient

from app import db
from app.main import app


def _settings(database_path):
    return SimpleNamespace(
        database_path=database_path,
        admin_username="admin",
        admin_password="cambiar-esta-clave",
    )


def test_public_request_and_admin_login(tmp_path, monkeypatch):
    monkeypatch.setattr(db, "settings", _settings(tmp_path / "test.db"))
    with TestClient(app) as client:
        response = client.post(
            "/solicitudes",
            data={
                "full_name": "Ada Ejemplo",
                "email": "ada@example.com",
                "phone": "+57 300 123 4567",
                "payment_method": "nequi",
                "request_password": "clave-segura-123",
                "birth_date": "1990-05-01",
                "birth_time": "10:30",
                "birthplace": "Bogota, Colombia",
                "latitude": "4.711",
                "longitude": "-74.0721",
                "timezone": "America/Bogota",
                "consent": "yes",
            },
            follow_redirects=False,
        )
        assert response.status_code == 303
        assert response.headers["location"].startswith("/solicitudes/recibida/")

        login = client.post(
            "/admin/login",
            data={"username": "admin", "password": "cambiar-esta-clave"},
            follow_redirects=False,
        )
        assert login.status_code == 303
        assert SESSION_COOKIE_NAME in login.cookies

        dashboard = client.get("/admin")
        assert dashboard.status_code == 200
        assert "Ada Ejemplo" in dashboard.text

        record = db.list_service_requests()[0]
        assert record["request_password_hash"] != "clave-segura-123"
        tracking = client.post(
            "/mi-solicitud",
            data={"public_id": record["public_id"], "password": "clave-segura-123"},
        )
        assert tracking.status_code == 200
        assert "Ada Ejemplo" in tracking.text
        assert "Nequi" in tracking.text


SESSION_COOKIE_NAME = "msastro_session"


def test_invalid_timezone_is_rejected(tmp_path, monkeypatch):
    monkeypatch.setattr(db, "settings", _settings(tmp_path / "test-invalid.db"))
    with TestClient(app) as client:
        response = client.post(
            "/solicitudes",
            data={
                "full_name": "Ada Ejemplo",
                "email": "ada@example.com",
                "phone": "3001234567",
                "payment_method": "daviplata",
                "request_password": "clave-segura-123",
                "birth_date": "1990-05-01",
                "birth_time": "10:30",
                "birthplace": "Bogota",
                "latitude": "4.711",
                "longitude": "-74.0721",
                "timezone": "Planeta/Marte",
                "consent": "yes",
            },
        )
        assert response.status_code == 422
        assert "zona horaria" in response.text
