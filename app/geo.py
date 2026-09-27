from __future__ import annotations

import sqlite3
from typing import Any

from .config import settings


def _connect() -> sqlite3.Connection | None:
    if not settings.geodata_path.exists():
        return None
    conn = sqlite3.connect(f"file:{settings.geodata_path.resolve()}?mode=ro", uri=True)
    conn.row_factory = sqlite3.Row
    return conn


def list_countries() -> list[dict[str, Any]]:
    conn = _connect()
    if not conn:
        return []
    try:
        rows = conn.execute(
            """
            SELECT c.code, c.name, COUNT(p.id) AS city_count
            FROM countries c
            JOIN places p ON p.country_code = c.code
            GROUP BY c.code, c.name
            ORDER BY c.name COLLATE NOCASE
            """
        ).fetchall()
        return [dict(row) for row in rows]
    finally:
        conn.close()


def list_admin1(country_code: str) -> list[dict[str, Any]]:
    conn = _connect()
    if not conn:
        return []
    try:
        rows = conn.execute(
            """
            SELECT a.code,
                   CASE WHEN a.country_code = 'CO' THEN REPLACE(a.name, ' Department', '') ELSE a.name END AS name,
                   COUNT(p.id) AS city_count
            FROM admin1 a
            JOIN places p ON p.country_code = a.country_code AND p.admin1_code = a.code
            WHERE a.country_code = ?
            GROUP BY a.code, a.name
            ORDER BY name COLLATE NOCASE
            """,
            (country_code.upper(),),
        ).fetchall()
        return [dict(row) for row in rows]
    finally:
        conn.close()


def list_places(country_code: str, admin1_code: str) -> list[dict[str, Any]]:
    conn = _connect()
    if not conn:
        return []
    try:
        rows = conn.execute(
            """
            SELECT id, name, latitude, longitude, timezone, population
            FROM places
            WHERE country_code = ? AND admin1_code = ?
            ORDER BY name COLLATE NOCASE, population DESC
            """,
            (country_code.upper(), admin1_code),
        ).fetchall()
        return [dict(row) for row in rows]
    finally:
        conn.close()


def get_place(place_id: int) -> dict[str, Any] | None:
    conn = _connect()
    if not conn:
        return None
    try:
        row = conn.execute(
            """
            SELECT p.id, p.name, p.latitude, p.longitude, p.timezone,
                   p.country_code, p.admin1_code, c.name AS country_name,
                   CASE
                       WHEN a.country_code = 'CO' THEN REPLACE(a.name, ' Department', '')
                       ELSE COALESCE(a.name, p.admin1_code)
                   END AS admin1_name
            FROM places p
            JOIN countries c ON c.code = p.country_code
            LEFT JOIN admin1 a ON a.country_code = p.country_code AND a.code = p.admin1_code
            WHERE p.id = ?
            """,
            (place_id,),
        ).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def stats() -> dict[str, int]:
    conn = _connect()
    if not conn:
        return {"countries": 0, "admin1": 0, "places": 0}
    try:
        return {
            "countries": conn.execute("SELECT COUNT(*) FROM countries").fetchone()[0],
            "admin1": conn.execute("SELECT COUNT(*) FROM admin1").fetchone()[0],
            "places": conn.execute("SELECT COUNT(*) FROM places").fetchone()[0],
        }
    finally:
        conn.close()
