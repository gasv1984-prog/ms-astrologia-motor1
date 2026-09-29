from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
HOSTING = ROOT / "shared-hosting"


def test_mysql_connections_force_one_utf8mb4_collation():
    bootstrap = (HOSTING / "inc" / "bootstrap.php").read_text(encoding="utf-8")
    installer = (HOSTING / "install.php").read_text(encoding="utf-8")

    statement = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    assert statement in bootstrap
    assert statement in installer


def test_existing_tables_receive_collation_migration():
    bootstrap = (HOSTING / "inc" / "bootstrap.php").read_text(encoding="utf-8")

    assert "utf8mb4_unicode_ci_v1" in bootstrap
    assert "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4" in bootstrap
    assert "schema_migrations" in bootstrap


def test_horoscope_publish_query_does_not_compare_mixed_parameters():
    source = (HOSTING / "admin" / "horoscopos.php").read_text(encoding="utf-8")

    assert "IF(?='published'" not in source
    assert "$status === 'published'" in source


def test_github_engine_uses_official_ephemerides_and_counterclockwise_houses():
    source = (ROOT / "github-pages" / "motor" / "engine.js").read_text(encoding="utf-8")

    assert "CalculationFlag.SwissEphemeris | CalculationFlag.Speed" in source
    assert "sepl_18.se1" in source
    assert "semo_18.se1" in source
    assert "seas_18.se1" in source
    assert "180 + normalize(longitude - ascendant)" in source
    assert "zodiac_direction: 'contrario a las manecillas del reloj'" in source
    assert "Nodo Sur verdadero" in source
    assert "southLongitude = normalize(north.longitude + 180)" in source
    assert "['DSC', normalize(houses.ascendant + 180)]" in source
    assert "['IC', normalize(houses.mc + 180)]" in source
    assert "[1, 4, 7, 10].includes(house)" in source
    assert "Nunca alterar la longitud" in source
    assert 'data-house=' in source
    assert 'UBICACIÓN' in source
    assert 'COORDENADAS' in source

    admin_request = (HOSTING / "admin" / "solicitud.php").read_text(encoding="utf-8")
    public_result = (HOSTING / "resultado.php").read_text(encoding="utf-8")
    browser_js = (HOSTING / "assets" / "app.js").read_text(encoding="utf-8")
    assert "data-chart-expand" in admin_request
    assert "data-chart-expand" in public_result
    assert "<dt>Ubicación</dt>" in public_result
    assert "chart-zoom-dialog" in browser_js

    ephemeris = ROOT / "github-pages" / "motor" / "ephe"
    assert (ephemeris / "sepl_18.se1").stat().st_size > 400_000
    assert (ephemeris / "semo_18.se1").stat().st_size > 1_000_000
    assert (ephemeris / "seas_18.se1").stat().st_size > 200_000


def test_personal_horoscope_keeps_natal_calculation_and_transits():
    schema = (HOSTING / "database" / "schema.sql").read_text(encoding="utf-8")
    form = (HOSTING / "solicitar.php").read_text(encoding="utf-8")
    admin = (HOSTING / "admin" / "solicitud.php").read_text(encoding="utf-8")
    astrology = (HOSTING / "inc" / "astrology.php").read_text(encoding="utf-8")

    assert "personal_horoscope" in schema
    assert "horoscope_date" in schema
    assert "horoscopo_personalizado" in form
    assert "transit_date" in admin
    assert "service_interpretation_prompt" in admin
    assert "chart_context_for_ai" in admin
    assert "No uses Markdown" in astrology
