from app.security import create_session, hash_password, read_session, verify_password


def test_password_hash_roundtrip():
    encoded = hash_password("una-clave-segura")
    assert verify_password("una-clave-segura", encoded)
    assert not verify_password("otra-clave", encoded)
    assert "una-clave-segura" not in encoded


def test_signed_session_rejects_tampering():
    token = create_session(7)
    assert read_session(token)["admin_id"] == 7
    changed = ("A" if token[0] != "A" else "B") + token[1:]
    assert read_session(changed) is None

