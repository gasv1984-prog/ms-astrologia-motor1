import sqlite3
from types import SimpleNamespace

from fastapi.testclient import TestClient

from app import db, geo
from app.geo_import import SCHEMA
from app.main import app


def seed_geodata(path):
    conn = sqlite3.connect(path)
    conn.executescript(SCHEMA)
    conn.execute("INSERT INTO countries(code, name) VALUES ('CO', 'Colombia')")
    conn.execute("INSERT INTO admin1(country_code, code, name, geoname_id) VALUES ('CO', '34', 'Cundinamarca', 1)")
    conn.execute(
        """
        INSERT INTO places(id, name, ascii_name, latitude, longitude, country_code, admin1_code, population, timezone)
        VALUES (3688689, 'Bogota', 'Bogota', 4.60971, -74.08175, 'CO', '34', 7674366, 'America/Bogota')
        """
    )
    conn.commit()
    conn.close()


def test_location_filters_and_automatic_coordinates(tmp_path, monkeypatch):
    geodata_path = tmp_path / "geonames.db"
    appdata_path = tmp_path / "app.db"
    seed_geodata(geodata_path)
    monkeypatch.setattr(geo, "settings", SimpleNamespace(geodata_path=geodata_path))
    monkeypatch.setattr(
        db,
        "settings",
        SimpleNamespace(database_path=appdata_path, admin_username="admin", admin_password="cambiar-esta-clave"),
    )

    assert geo.list_countries()[0]["code"] == "CO"
    assert geo.list_admin1("CO")[0]["name"] == "Cundinamarca"
    assert geo.list_places("CO", "34")[0]["timezone"] == "America/Bogota"

    with TestClient(app) as client:
        response = client.post(
            "/solicitudes",
            data={
                "full_name": "Ada Ejemplo",
                "email": "ada@example.com",
                "phone": "3001234567",
                "payment_method": "llave",
                "request_password": "clave-segura-123",
                "birth_date": "1990-05-01",
                "birth_time": "10:30",
                "city_id": "3688689",
                "consent": "yes",
            },
            follow_redirects=False,
        )
        assert response.status_code == 303

    record = db.list_service_requests()[0]
    assert record["birthplace"] == "Bogota, Cundinamarca, Colombia"
    assert record["latitude"] == 4.60971
    assert record["longitude"] == -74.08175
    assert record["timezone"] == "America/Bogota"
