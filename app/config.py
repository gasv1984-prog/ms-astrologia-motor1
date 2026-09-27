from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _load_dotenv(path: Path = Path(".env")) -> None:
    """Small dotenv loader so local development needs no extra dependency."""
    if not path.exists():
        return
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


_load_dotenv()


@dataclass(frozen=True)
class Settings:
    app_env: str = os.getenv("APP_ENV", "development")
    app_secret: str = os.getenv("APP_SECRET", "development-only-secret-change-me")
    admin_username: str = os.getenv("ADMIN_USERNAME", "admin")
    admin_password: str = os.getenv("ADMIN_PASSWORD", "cambiar-esta-clave")
    database_path: Path = Path(os.getenv("DATABASE_PATH", "data/msastrologia.db"))
    geodata_path: Path = Path(os.getenv("GEODATA_PATH", "data/geonames.db"))
    public_base_url: str = os.getenv("PUBLIC_BASE_URL", "http://127.0.0.1:8000")
    results_dir: Path = Path(os.getenv("RESULTS_DIR", "output/pdf"))
    smtp_host: str = os.getenv("SMTP_HOST", "")
    smtp_port: int = int(os.getenv("SMTP_PORT", "465"))
    smtp_username: str = os.getenv("SMTP_USERNAME", "")
    smtp_password: str = os.getenv("SMTP_PASSWORD", "")
    smtp_from_email: str = os.getenv("SMTP_FROM_EMAIL", "")
    smtp_security: str = os.getenv("SMTP_SECURITY", "ssl").lower()
    whatsapp_api_url: str = os.getenv("WHATSAPP_API_URL", "")
    whatsapp_access_token: str = os.getenv("WHATSAPP_ACCESS_TOKEN", "")
    default_country_dial_code: str = os.getenv("DEFAULT_COUNTRY_DIAL_CODE", "57")
    astrology_api_key: str = os.getenv("ASTROLOGY_API_KEY", "")

    @property
    def production(self) -> bool:
        return self.app_env.lower() == "production"


settings = Settings()

if settings.production and (
    settings.app_secret == "development-only-secret-change-me"
    or settings.admin_password == "cambiar-esta-clave"
):
    raise RuntimeError("Configura APP_SECRET y ADMIN_PASSWORD seguros antes de iniciar en produccion.")
