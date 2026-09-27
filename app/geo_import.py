from __future__ import annotations

import argparse
import csv
import io
import os
import sqlite3
import tempfile
import urllib.request
import zipfile
from pathlib import Path

from .config import settings

BASE_URL = "https://download.geonames.org/export/dump"
SPANISH_COUNTRY_NAMES = {
    "AR": "Argentina", "BO": "Bolivia", "BR": "Brasil", "CL": "Chile",
    "CO": "Colombia", "CR": "Costa Rica", "CU": "Cuba", "DO": "Republica Dominicana",
    "EC": "Ecuador", "SV": "El Salvador", "ES": "Espana", "GT": "Guatemala",
    "HN": "Honduras", "MX": "Mexico", "NI": "Nicaragua", "PA": "Panama",
    "PY": "Paraguay", "PE": "Peru", "PR": "Puerto Rico", "US": "Estados Unidos",
    "UY": "Uruguay", "VE": "Venezuela",
}

SCHEMA = """
PRAGMA journal_mode = DELETE;
CREATE TABLE countries (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL
);
CREATE TABLE admin1 (
    country_code TEXT NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    geoname_id INTEGER,
    PRIMARY KEY(country_code, code)
);
CREATE TABLE places (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    ascii_name TEXT NOT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    country_code TEXT NOT NULL,
    admin1_code TEXT NOT NULL,
    population INTEGER NOT NULL DEFAULT 0,
    timezone TEXT NOT NULL
);
CREATE INDEX idx_places_country_admin ON places(country_code, admin1_code, name);
CREATE INDEX idx_places_name ON places(name COLLATE NOCASE);
"""


def download(url: str) -> bytes:
    request = urllib.request.Request(url, headers={"User-Agent": "msastrologia/0.1 (GeoNames import)"})
    with urllib.request.urlopen(request, timeout=120) as response:
        return response.read()


def country_rows(content: str):
    for line in content.splitlines():
        if not line or line.startswith("#"):
            continue
        columns = line.split("\t")
        code, name = columns[0], columns[4]
        yield code, SPANISH_COUNTRY_NAMES.get(code, name)


def admin_rows(content: str):
    for row in csv.reader(io.StringIO(content), delimiter="\t"):
        if len(row) < 4 or "." not in row[0]:
            continue
        country_code, code = row[0].split(".", 1)
        yield country_code, code, row[1], int(row[3])


def place_rows(zipped: bytes):
    with zipfile.ZipFile(io.BytesIO(zipped)) as archive:
        name = next(item for item in archive.namelist() if item.endswith(".txt"))
        with archive.open(name) as raw, io.TextIOWrapper(raw, encoding="utf-8") as text:
            for row in csv.reader(text, delimiter="\t"):
                if len(row) < 19:
                    continue
                yield (
                    int(row[0]), row[1], row[2], float(row[4]), float(row[5]),
                    row[8], row[10], int(row[14] or 0), row[17],
                )


def build_database(destination: Path) -> dict[str, int]:
    print("Descargando catalogos de GeoNames...")
    countries = download(f"{BASE_URL}/countryInfo.txt").decode("utf-8")
    admins = download(f"{BASE_URL}/admin1CodesASCII.txt").decode("utf-8")
    cities = download(f"{BASE_URL}/cities500.zip")

    destination.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(prefix="geonames-", suffix=".db", dir=destination.parent)
    os.close(fd)
    temporary = Path(temporary_name)
    try:
        conn = sqlite3.connect(temporary)
        conn.executescript(SCHEMA)
        conn.executemany("INSERT INTO countries(code, name) VALUES (?, ?)", country_rows(countries))
        conn.executemany(
            "INSERT INTO admin1(country_code, code, name, geoname_id) VALUES (?, ?, ?, ?)",
            admin_rows(admins),
        )
        batch = []
        for row in place_rows(cities):
            batch.append(row)
            if len(batch) >= 5000:
                conn.executemany(
                    "INSERT INTO places(id, name, ascii_name, latitude, longitude, country_code, admin1_code, population, timezone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    batch,
                )
                batch.clear()
        if batch:
            conn.executemany(
                "INSERT INTO places(id, name, ascii_name, latitude, longitude, country_code, admin1_code, population, timezone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                batch,
            )
        conn.commit()
        counts = {
            "countries": conn.execute("SELECT COUNT(*) FROM countries").fetchone()[0],
            "admin1": conn.execute("SELECT COUNT(*) FROM admin1").fetchone()[0],
            "places": conn.execute("SELECT COUNT(*) FROM places").fetchone()[0],
        }
        conn.execute("PRAGMA optimize")
        conn.close()
        os.replace(temporary, destination)
        return counts
    finally:
        if temporary.exists():
            temporary.unlink()


def main() -> None:
    parser = argparse.ArgumentParser(description="Descarga GeoNames cities500 y crea la base local de ubicaciones.")
    parser.add_argument("--output", type=Path, default=settings.geodata_path)
    args = parser.parse_args()
    counts = build_database(args.output)
    print(f"Base creada en {args.output}: {counts['countries']} paises, {counts['admin1']} divisiones, {counts['places']} localidades.")


if __name__ == "__main__":
    main()
