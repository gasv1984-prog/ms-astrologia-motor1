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


def test_horoscope_generator_supports_all_signs_and_decanates():
    admin = (HOSTING / "admin" / "horoscopos.php").read_text(encoding="utf-8")
    public = (HOSTING / "horoscopo.php").read_text(encoding="utf-8")
    astrology = (HOSTING / "inc" / "astrology.php").read_text(encoding="utf-8")
    bootstrap = (HOSTING / "inc" / "bootstrap.php").read_text(encoding="utf-8")

    assert '<option value="all">' in admin
    assert "$sign === 'all' ? array_keys($signs)" in admin
    assert "PRIMER DECANATO · 0°00′ A 9°59′" in admin
    assert "SEGUNDO DECANATO · 10°00′ A 19°59′" in admin
    assert "TERCER DECANATO · 20°00′ A 29°59′" in admin
    assert "Los tres decanatos de cada signo" in public
    assert "DECANATO SOLAR VERIFICADO" in astrology
    assert "Integra expresamente el DECANATO SOLAR VERIFICADO" in astrology
    assert "function zodiac_decanate" in bootstrap


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
    assert 'decanate_range' in source
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


def test_unified_home_uses_new_domain_and_keeps_both_consultation_paths():
    home = (HOSTING / "index.php").read_text(encoding="utf-8")
    bootstrap = (HOSTING / "inc" / "bootstrap.php").read_text(encoding="utf-8")
    schema = (HOSTING / "database" / "schema.sql").read_text(encoding="utf-8")
    engine = (ROOT / "github-pages" / "motor" / "engine.js").read_text(encoding="utf-8")

    assert "Consulta de numerología" in home
    assert "Consulta de carta astral" in home
    assert "+100.000" in home
    assert "Seguidores en la comunidad" in home
    assert "Rituales de limpieza" in home
    assert "Estudios numerológicos" in home
    assert "Números del día" in home
    assert "Historias de transformación" in home
    assert "Consultar mi horóscopo" in home
    assert "service-numerologia-v1.png" in home
    assert "service-carta-astral-v1.png" in home
    assert "lottery-results" in home
    assert "logo-ms-numerologia.png" in home
    assert "youtube.com/embed/wum8hs6AV2w" in home
    assert 'data-unique-id="msnumerologia"' in home
    assert 'data-embed-from="oembed"' in home
    assert 'style="max-width: 780px; min-width: 288px;"' in home
    assert "data-tiktok-retry" in home
    assert "https://www.tiktok.com/embed.js" in home
    assert "data-theme-toggle" in bootstrap
    assert "localStorage.getItem('ms-theme')" in bootstrap
    styles = (HOSTING / "assets" / "styles.css").read_text(encoding="utf-8")
    assert 'html[data-theme="light"] .lottery-card strong' in styles
    browser_js = (HOSTING / "assets" / "app.js").read_text(encoding="utf-8")
    assert "data-tiktok-embed-wrap" in home
    assert "msTiktokEmbed" in browser_js
    assert "miguelsalazarastrologia.com" in bootstrap
    assert "https://miguelsalazarastrologia.com" in engine
    assert "https://www.miguelsalazarastrologia.com" in engine
    assert "INSERT IGNORE INTO astrology_configs" in schema
    assert "https://gasv1984-prog.github.io/ms-astrologia-motor1/motor" in schema

    for name in (
        "logo-ms-numerologia.png",
        "miguel-banner.png",
        "miguel-pointing.png",
        "miguel-profile.png",
        "service-numerologia-v1.png",
        "service-carta-astral-v1.png",
    ):
        assert (HOSTING / "assets" / name).stat().st_size > 10_000
