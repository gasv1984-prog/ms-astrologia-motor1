from __future__ import annotations

import asyncio
import smtplib
from email.message import EmailMessage
from html import escape
from pathlib import Path
from urllib.parse import quote, quote_plus

import httpx

from . import db
from .config import settings
from .pdf_report import generate_reading_pdf
from .security import create_result_token


def result_url(record: dict) -> str:
    token = quote(create_result_token(record["public_id"]), safe="")
    base = settings.public_base_url.rstrip("/")
    return f"{base}/resultado/{record['public_id']}?token={token}"


def pdf_url(record: dict) -> str:
    token = quote(create_result_token(record["public_id"]), safe="")
    base = settings.public_base_url.rstrip("/")
    return f"{base}/resultado/{record['public_id']}/pdf?token={token}"


def normalized_whatsapp_number(phone: str) -> str:
    digits = "".join(character for character in phone if character.isdigit())
    if len(digits) == 10 and digits.startswith("3"):
        return f"{settings.default_country_dial_code}{digits}"
    return digits


def notification_text(record: dict) -> str:
    return (
        f"Hola {record['full_name']}. Tu lectura de MS Astrologia ya esta lista. "
        f"Puedes verla, descargar el PDF y conversar con Aurita desde este enlace privado: {result_url(record)}"
    )


def manual_whatsapp_url(record: dict) -> str:
    return f"https://wa.me/{normalized_whatsapp_number(record['phone'])}?text={quote_plus(notification_text(record))}"


def manual_email_url(record: dict) -> str:
    subject = quote_plus("Tu lectura astrologica esta lista")
    body = quote_plus(notification_text(record))
    return f"mailto:{quote(record['email'])}?subject={subject}&body={body}"


def is_ready(record: dict) -> bool:
    return bool(record.get("payment_status") == "paid" and record.get("chart_json") and record.get("ai_interpretation"))


def _send_email(record: dict, pdf_path: Path) -> None:
    message = EmailMessage()
    message["Subject"] = "Tu lectura astrologica esta lista"
    message["From"] = settings.smtp_from_email
    message["To"] = record["email"]
    text = notification_text(record)
    message.set_content(text)
    message.add_alternative(
        f"""
        <html><body style="font-family:Arial,sans-serif;color:#201a27">
          <h1 style="color:#9b762f">Tu lectura esta lista</h1>
          <p>Hola {escape(record['full_name'])}, hemos confirmado tu pago y completado tu carta.</p>
          <p><a href="{escape(result_url(record))}">Abrir resultado y conversar con Aurita</a></p>
          <p>Tambien adjuntamos el PDF de tu lectura.</p>
        </body></html>
        """,
        subtype="html",
    )
    message.add_attachment(pdf_path.read_bytes(), maintype="application", subtype="pdf", filename=pdf_path.name)
    if settings.smtp_security == "ssl":
        with smtplib.SMTP_SSL(settings.smtp_host, settings.smtp_port, timeout=25) as client:
            if settings.smtp_username:
                client.login(settings.smtp_username, settings.smtp_password)
            client.send_message(message)
    else:
        with smtplib.SMTP(settings.smtp_host, settings.smtp_port, timeout=25) as client:
            if settings.smtp_security == "starttls":
                client.starttls()
            if settings.smtp_username:
                client.login(settings.smtp_username, settings.smtp_password)
            client.send_message(message)


async def _send_whatsapp(record: dict) -> None:
    async with httpx.AsyncClient(timeout=httpx.Timeout(25, connect=10)) as client:
        response = await client.post(
            settings.whatsapp_api_url,
            headers={
                "Authorization": f"Bearer {settings.whatsapp_access_token}",
                "Content-Type": "application/json",
            },
            json={
                "messaging_product": "whatsapp",
                "recipient_type": "individual",
                "to": normalized_whatsapp_number(record["phone"]),
                "type": "text",
                "text": {"preview_url": True, "body": notification_text(record)},
            },
        )
        response.raise_for_status()


async def deliver_result(record: dict, static_dir: Path, *, force: bool = False) -> dict[str, str]:
    if not is_ready(record):
        return {"ready": "no"}
    pdf_path = generate_reading_pdf(record, settings.results_dir, static_dir)
    latest = db.latest_deliveries(record["id"])
    outcome: dict[str, str] = {"ready": "yes", "pdf": str(pdf_path)}

    if force or latest.get("email", {}).get("status") != "sent":
        if settings.smtp_host and settings.smtp_from_email:
            try:
                await asyncio.to_thread(_send_email, record, pdf_path)
                db.record_delivery(record["id"], "email", "sent", record["email"])
                outcome["email"] = "sent"
            except Exception as exc:
                detail = f"{type(exc).__name__}: {exc}"[:500]
                db.record_delivery(record["id"], "email", "error", detail)
                outcome["email"] = "error"
        else:
            db.record_delivery(record["id"], "email", "manual", "SMTP no configurado")
            outcome["email"] = "manual"

    if force or latest.get("whatsapp", {}).get("status") != "sent":
        if settings.whatsapp_api_url and settings.whatsapp_access_token:
            try:
                await _send_whatsapp(record)
                db.record_delivery(record["id"], "whatsapp", "sent", normalized_whatsapp_number(record["phone"]))
                outcome["whatsapp"] = "sent"
            except Exception as exc:
                detail = f"{type(exc).__name__}: {exc}"[:500]
                db.record_delivery(record["id"], "whatsapp", "error", detail)
                outcome["whatsapp"] = "error"
        else:
            db.record_delivery(record["id"], "whatsapp", "manual", "WhatsApp Cloud API no configurada")
            outcome["whatsapp"] = "manual"
    return outcome
