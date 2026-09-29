from types import SimpleNamespace

from fastapi.testclient import TestClient

from app import db
from app.astrology import build_horoscope_prompt
from app.main import app


def test_horoscope_prompt_covers_the_three_decanates():
    prompt = build_horoscope_prompt("Leo", "Semanal", "Semana de prueba")
    assert "PRIMER DECANATO · 0°00′ A 9°59′" in prompt
    assert "SEGUNDO DECANATO · 10°00′ A 19°59′" in prompt
    assert "TERCER DECANATO · 20°00′ A 29°59′" in prompt
    assert "grado exacto del Sol natal" in prompt


def test_horoscope_draft_publish_and_public_query(tmp_path, monkeypatch):
    monkeypatch.setattr(
        db,
        "settings",
        SimpleNamespace(database_path=tmp_path / "horoscopes.db", admin_username="admin", admin_password="clave-administrador"),
    )
    with TestClient(app) as client:
        draft = db.create_horoscope(
            {
                "sign": "aries",
                "period_type": "weekly",
                "period_label": "Semana de prueba",
                "title": "Aries · Semana de prueba",
                "content": "### Panorama\n\n" + "Una orientación simbólica y consciente. " * 8,
                "ai_provider": "gemini",
                "ai_model": "modelo-prueba",
            }
        )
        assert client.get("/horoscopo?sign=aries").text.find("Semana de prueba") == -1

        db.update_horoscope(
            draft["id"],
            {
                "sign": "aries",
                "period_type": "weekly",
                "period_label": "Semana de prueba",
                "title": "Aries · Semana de prueba",
                "content": draft["content"],
                "status": "published",
            },
        )

        public = client.get("/horoscopo?sign=aries")
        assert public.status_code == 200
        assert "Aries · Semana de prueba" in public.text
        assert "<h3>Panorama</h3>" in public.text

        landing = client.get("/")
        assert "zodiac-svg" in landing.text
        assert "Aries · Semana de prueba" in landing.text

        login = client.post(
            "/admin/login",
            data={"username": "admin", "password": "clave-administrador"},
            follow_redirects=False,
        )
        assert login.status_code == 303
        admin_page = client.get("/admin/horoscopos")
        assert admin_page.status_code == 200
        assert "Generar nuevos borradores" in admin_page.text
