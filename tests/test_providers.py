from app.providers import normalize_api_key


def test_normalize_gemini_key_from_env_line():
    assert normalize_api_key("GEMINI_API_KEY='AIza-prueba'") == "AIza-prueba"


def test_normalize_plain_key():
    assert normalize_api_key('  "AIza-prueba"  ') == "AIza-prueba"
