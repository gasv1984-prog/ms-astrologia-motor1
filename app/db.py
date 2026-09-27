from __future__ import annotations

import json
import sqlite3
from contextlib import contextmanager
from datetime import UTC, datetime
from typing import Any, Iterator

from .config import settings
from .security import hash_password


def utcnow() -> str:
    return datetime.now(UTC).replace(microsecond=0).isoformat()


@contextmanager
def connection() -> Iterator[sqlite3.Connection]:
    settings.database_path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(settings.database_path)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    conn.execute("PRAGMA journal_mode = WAL")
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


SCHEMA = """
CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS service_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    full_name TEXT NOT NULL,
    email TEXT NOT NULL,
    birth_date TEXT NOT NULL,
    birth_time TEXT NOT NULL,
    birthplace TEXT NOT NULL,
    latitude REAL NOT NULL CHECK(latitude BETWEEN -90 AND 90),
    longitude REAL NOT NULL CHECK(longitude BETWEEN -180 AND 180),
    timezone TEXT NOT NULL,
    service_type TEXT NOT NULL DEFAULT 'natal',
    notes TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    chart_json TEXT,
    chart_svg_path TEXT,
    ai_interpretation TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS ai_configs (
    provider TEXT PRIMARY KEY CHECK(provider IN ('openai', 'gemini')),
    encrypted_api_key TEXT NOT NULL,
    model TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    last_checked_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS delivery_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL REFERENCES service_requests(id) ON DELETE CASCADE,
    channel TEXT NOT NULL CHECK(channel IN ('email', 'whatsapp')),
    status TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS aurita_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL REFERENCES service_requests(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK(role IN ('user', 'assistant')),
    content TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS horoscopes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sign TEXT NOT NULL,
    period_type TEXT NOT NULL DEFAULT 'weekly',
    period_label TEXT NOT NULL,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    ai_provider TEXT NOT NULL DEFAULT '',
    ai_model TEXT NOT NULL DEFAULT '',
    published_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_horoscopes_public ON horoscopes(status, sign, published_at);
"""


def init_db() -> None:
    with connection() as conn:
        conn.executescript(SCHEMA)
        existing_columns = {row["name"] for row in conn.execute("PRAGMA table_info(service_requests)")}
        migrations = {
            "phone": "TEXT NOT NULL DEFAULT ''",
            "payment_method": "TEXT NOT NULL DEFAULT ''",
            "payment_status": "TEXT NOT NULL DEFAULT 'pending'",
            "request_password_hash": "TEXT NOT NULL DEFAULT ''",
            "ai_provider": "TEXT NOT NULL DEFAULT ''",
            "ai_model": "TEXT NOT NULL DEFAULT ''",
        }
        for column, definition in migrations.items():
            if column not in existing_columns:
                conn.execute(f"ALTER TABLE service_requests ADD COLUMN {column} {definition}")
        admin = conn.execute("SELECT id FROM admins LIMIT 1").fetchone()
        if not admin:
            now = utcnow()
            conn.execute(
                "INSERT INTO admins(username, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)",
                (settings.admin_username, hash_password(settings.admin_password), now, now),
            )


def row_to_dict(row: sqlite3.Row | None) -> dict[str, Any] | None:
    return dict(row) if row else None


def get_admin_by_username(username: str) -> dict[str, Any] | None:
    with connection() as conn:
        return row_to_dict(conn.execute("SELECT * FROM admins WHERE username = ?", (username.strip(),)).fetchone())


def get_admin(admin_id: int) -> dict[str, Any] | None:
    with connection() as conn:
        return row_to_dict(conn.execute("SELECT * FROM admins WHERE id = ?", (admin_id,)).fetchone())


def update_admin(admin_id: int, username: str, password_hash: str | None = None) -> None:
    with connection() as conn:
        if password_hash:
            conn.execute(
                "UPDATE admins SET username = ?, password_hash = ?, updated_at = ? WHERE id = ?",
                (username.strip(), password_hash, utcnow(), admin_id),
            )
        else:
            conn.execute(
                "UPDATE admins SET username = ?, updated_at = ? WHERE id = ?",
                (username.strip(), utcnow(), admin_id),
            )


def create_service_request(data: dict[str, Any]) -> dict[str, Any]:
    import secrets

    public_id = secrets.token_urlsafe(8)
    now = utcnow()
    with connection() as conn:
        cursor = conn.execute(
            """
            INSERT INTO service_requests(
                public_id, full_name, email, birth_date, birth_time, birthplace,
                latitude, longitude, timezone, service_type, notes, phone, payment_method,
                payment_status, request_password_hash, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
            """,
            (
                public_id,
                data["full_name"].strip(),
                data["email"].strip().lower(),
                data["birth_date"], data["birth_time"], data["birthplace"].strip(),
                data["latitude"], data["longitude"], data["timezone"].strip(),
                data.get("service_type", "natal"), data.get("notes", "").strip(),
                data["phone"].strip(), data["payment_method"], hash_password(data["request_password"]), now, now,
            ),
        )
        return row_to_dict(conn.execute("SELECT * FROM service_requests WHERE id = ?", (cursor.lastrowid,)).fetchone()) or {}


def list_service_requests(status: str | None = None) -> list[dict[str, Any]]:
    with connection() as conn:
        if status and status != "all":
            rows = conn.execute("SELECT * FROM service_requests WHERE status = ? ORDER BY created_at DESC", (status,)).fetchall()
        else:
            rows = conn.execute("SELECT * FROM service_requests ORDER BY created_at DESC").fetchall()
    return [dict(row) for row in rows]


def get_service_request(request_id: int) -> dict[str, Any] | None:
    with connection() as conn:
        return row_to_dict(conn.execute("SELECT * FROM service_requests WHERE id = ?", (request_id,)).fetchone())


def get_service_request_by_public_id(public_id: str) -> dict[str, Any] | None:
    with connection() as conn:
        return row_to_dict(conn.execute("SELECT * FROM service_requests WHERE public_id = ?", (public_id.strip(),)).fetchone())


def update_request_status(request_id: int, status: str) -> None:
    with connection() as conn:
        conn.execute("UPDATE service_requests SET status = ?, updated_at = ? WHERE id = ?", (status, utcnow(), request_id))


def update_payment_status(request_id: int, status: str) -> None:
    with connection() as conn:
        conn.execute(
            "UPDATE service_requests SET payment_status = ?, updated_at = ? WHERE id = ?",
            (status, utcnow(), request_id),
        )


def save_chart(request_id: int, chart: dict[str, Any], svg_path: str | None) -> None:
    with connection() as conn:
        conn.execute(
            "UPDATE service_requests SET chart_json = ?, chart_svg_path = ?, status = 'calculated', updated_at = ? WHERE id = ?",
            (json.dumps(chart, ensure_ascii=False), svg_path, utcnow(), request_id),
        )


def save_interpretation(request_id: int, text: str, provider: str = "", model: str = "") -> None:
    with connection() as conn:
        conn.execute(
            "UPDATE service_requests SET ai_interpretation = ?, ai_provider = ?, ai_model = ?, status = 'completed', updated_at = ? WHERE id = ?",
            (text, provider, model, utcnow(), request_id),
        )


def record_delivery(request_id: int, channel: str, status: str, detail: str = "") -> None:
    with connection() as conn:
        conn.execute(
            "INSERT INTO delivery_events(request_id, channel, status, detail, created_at) VALUES (?, ?, ?, ?, ?)",
            (request_id, channel, status, detail[:1000], utcnow()),
        )


def latest_deliveries(request_id: int) -> dict[str, dict[str, Any]]:
    with connection() as conn:
        rows = conn.execute(
            """
            SELECT * FROM delivery_events
            WHERE request_id = ?
            ORDER BY id DESC
            """,
            (request_id,),
        ).fetchall()
    latest: dict[str, dict[str, Any]] = {}
    for row in rows:
        item = dict(row)
        latest.setdefault(item["channel"], item)
    return latest


def add_aurita_message(request_id: int, role: str, content: str) -> None:
    with connection() as conn:
        conn.execute(
            "INSERT INTO aurita_messages(request_id, role, content, created_at) VALUES (?, ?, ?, ?)",
            (request_id, role, content[:8000], utcnow()),
        )


def list_aurita_messages(request_id: int, limit: int = 12) -> list[dict[str, Any]]:
    with connection() as conn:
        rows = conn.execute(
            """
            SELECT * FROM (
                SELECT * FROM aurita_messages WHERE request_id = ? ORDER BY id DESC LIMIT ?
            ) ORDER BY id ASC
            """,
            (request_id, limit),
        ).fetchall()
    return [dict(row) for row in rows]


def save_ai_config(provider: str, encrypted_api_key: str, model: str) -> None:
    now = utcnow()
    with connection() as conn:
        conn.execute(
            """
            INSERT INTO ai_configs(provider, encrypted_api_key, model, enabled, last_checked_at, updated_at)
            VALUES (?, ?, ?, 1, ?, ?)
            ON CONFLICT(provider) DO UPDATE SET
                encrypted_api_key = excluded.encrypted_api_key,
                model = excluded.model,
                enabled = 1,
                last_checked_at = excluded.last_checked_at,
                updated_at = excluded.updated_at
            """,
            (provider, encrypted_api_key, model, now, now),
        )


def get_ai_config(provider: str) -> dict[str, Any] | None:
    with connection() as conn:
        return row_to_dict(conn.execute("SELECT * FROM ai_configs WHERE provider = ?", (provider,)).fetchone())


def list_ai_configs() -> list[dict[str, Any]]:
    with connection() as conn:
        return [dict(row) for row in conn.execute("SELECT * FROM ai_configs ORDER BY provider").fetchall()]


def dashboard_counts() -> dict[str, int]:
    counts = {"all": 0, "pending": 0, "calculated": 0, "completed": 0}
    with connection() as conn:
        for row in conn.execute("SELECT status, COUNT(*) AS total FROM service_requests GROUP BY status"):
            counts[row["status"]] = row["total"]
            counts["all"] += row["total"]
    return counts


def create_horoscope(data: dict[str, Any]) -> dict[str, Any]:
    now = utcnow()
    with connection() as conn:
        cursor = conn.execute(
            """
            INSERT INTO horoscopes(sign, period_type, period_label, title, content, status,
                                   ai_provider, ai_model, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)
            """,
            (
                data["sign"], data["period_type"], data["period_label"], data["title"], data["content"],
                data.get("ai_provider", ""), data.get("ai_model", ""), now, now,
            ),
        )
        return row_to_dict(conn.execute("SELECT * FROM horoscopes WHERE id = ?", (cursor.lastrowid,)).fetchone()) or {}


def update_horoscope(horoscope_id: int, data: dict[str, Any]) -> None:
    now = utcnow()
    published_at = now if data["status"] == "published" else None
    with connection() as conn:
        existing = conn.execute("SELECT published_at FROM horoscopes WHERE id = ?", (horoscope_id,)).fetchone()
        if existing and data["status"] == "published" and existing["published_at"]:
            published_at = existing["published_at"]
        conn.execute(
            """
            UPDATE horoscopes SET sign=?, period_type=?, period_label=?, title=?, content=?, status=?,
                                  published_at=?, updated_at=? WHERE id=?
            """,
            (
                data["sign"], data["period_type"], data["period_label"], data["title"], data["content"],
                data["status"], published_at, now, horoscope_id,
            ),
        )


def get_horoscope(horoscope_id: int) -> dict[str, Any] | None:
    with connection() as conn:
        return row_to_dict(conn.execute("SELECT * FROM horoscopes WHERE id = ?", (horoscope_id,)).fetchone())


def list_horoscopes(*, published_only: bool = False, sign: str | None = None, limit: int = 40) -> list[dict[str, Any]]:
    limit = max(1, min(limit, 80))
    clauses: list[str] = []
    params: list[Any] = []
    if published_only:
        clauses.append("status = 'published'")
    if sign:
        clauses.append("sign = ?")
        params.append(sign)
    where = f" WHERE {' AND '.join(clauses)}" if clauses else ""
    with connection() as conn:
        rows = conn.execute(
            f"SELECT * FROM horoscopes{where} ORDER BY published_at DESC, id DESC LIMIT ?",
            (*params, limit),
        ).fetchall()
    items = [dict(row) for row in rows]
    if published_only and not sign:
        latest: dict[str, dict[str, Any]] = {}
        for item in items:
            latest.setdefault(item["sign"], item)
        return list(latest.values())
    return items
