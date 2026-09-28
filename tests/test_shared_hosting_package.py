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
