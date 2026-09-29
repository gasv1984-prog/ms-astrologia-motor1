from __future__ import annotations

import html
import re
import secrets
import sqlite3
import tempfile
from contextlib import asynccontextmanager
from datetime import date, time
from pathlib import Path
from urllib.parse import urlencode

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import FileResponse, HTMLResponse, JSONResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
import markdown
from markupsafe import Markup
import pytz

from . import db, delivery, geo
from .astrology import AstrologyError, build_aurita_prompt, build_horoscope_prompt, build_interpretation_prompt, calculate_natal_chart
from .config import settings
from .pdf_report import generate_reading_pdf
from .providers import ProviderError, generate_interpretation, list_models
from .security import (
    create_result_token,
    create_session,
    decrypt_secret,
    encrypt_secret,
    hash_password,
    read_session,
    validate_csrf,
    verify_result_token,
    verify_password,
)

BASE_DIR = Path(__file__).resolve().parent
SESSION_COOKIE = "msastro_session"
VALID_STATUSES = {"pending", "calculated", "completed", "cancelled"}
VALID_PAYMENT_METHODS = {"nequi", "daviplata", "llave"}
VALID_PAYMENT_STATUSES = {"pending", "paid", "rejected"}
ZODIAC_SIGNS = {
    "aries": ("Aries", "♈"), "tauro": ("Tauro", "♉"), "geminis": ("Géminis", "♊"),
    "cancer": ("Cáncer", "♋"), "leo": ("Leo", "♌"), "virgo": ("Virgo", "♍"),
    "libra": ("Libra", "♎"), "escorpio": ("Escorpio", "♏"), "sagitario": ("Sagitario", "♐"),
    "capricornio": ("Capricornio", "♑"), "acuario": ("Acuario", "♒"), "piscis": ("Piscis", "♓"),
}
HOROSCOPE_PERIODS = {"daily": "Diario", "weekly": "Semanal", "monthly": "Mensual"}


@asynccontextmanager
async def lifespan(_: FastAPI):
    db.init_db()
    (BASE_DIR / "static" / "generated").mkdir(parents=True, exist_ok=True)
    yield


app = FastAPI(title="msastrologia", version="0.1.0", lifespan=lifespan, docs_url=None, redoc_url=None)
app.mount("/static", StaticFiles(directory=BASE_DIR / "static"), name="static")
templates = Jinja2Templates(directory=BASE_DIR / "templates")


def render_markdown(value: str | None) -> Markup:
    safe_source = html.escape(value or "")
    rendered = markdown.markdown(safe_source, extensions=["sane_lists", "nl2br"])
    return Markup(rendered)


templates.env.filters["markdown"] = render_markdown


def render(request: Request, name: str, *, status_code: int = 200, **context):
    return templates.TemplateResponse(
        request=request,
        name=name,
        context={"request": request, **context},
        status_code=status_code,
    )


def redirect_with_message(path: str, *, message: str | None = None, error: str | None = None) -> RedirectResponse:
    params = {key: value for key, value in (("message", message), ("error", error)) if value}
    separator = "&" if "?" in path else "?"
    target = f"{path}{separator}{urlencode(params)}" if params else path
    return RedirectResponse(target, status_code=303)


def current_admin(request: Request) -> tuple[dict, dict] | None:
    session = read_session(request.cookies.get(SESSION_COOKIE))
    if not session:
        return None
    admin = db.get_admin(int(session.get("admin_id", 0)))
    return (session, admin) if admin else None


def require_admin(request: Request) -> tuple[dict, dict]:
    auth = current_admin(request)
    if not auth:
        raise HTTPException(status_code=401, detail="Sesion requerida")
    return auth


def require_ready_result(public_id: str, token: str | None) -> dict:
    record = db.get_service_request_by_public_id(public_id)
    if not record or not verify_result_token(public_id, token):
        raise HTTPException(404, "Resultado no encontrado")
    if not delivery.is_ready(record):
        raise HTTPException(403, "El resultado aun no esta habilitado")
    return record


async def require_form_csrf(request: Request, session: dict) -> dict:
    form = dict(await request.form())
    if not validate_csrf(session, form.get("csrf")):
        raise HTTPException(status_code=403, detail="Token de seguridad invalido")
    return form


@app.exception_handler(401)
async def unauthorized_handler(request: Request, exc: HTTPException):
    if request.url.path.startswith("/admin/api/"):
        return JSONResponse({"error": exc.detail}, status_code=401)
    return RedirectResponse("/admin/login", status_code=303)


@app.get("/", response_class=HTMLResponse)
async def landing(request: Request):
    return render(
        request,
        "landing.html",
        horoscope_preview=db.list_horoscopes(published_only=True, limit=3),
        zodiac_signs=ZODIAC_SIGNS,
    )


@app.get("/horoscopo", response_class=HTMLResponse)
async def public_horoscope(request: Request, sign: str = ""):
    selected = sign if sign in ZODIAC_SIGNS else ""
    return render(
        request,
        "horoscope.html",
        selected=selected,
        zodiac_signs=ZODIAC_SIGNS,
        items=db.list_horoscopes(published_only=True, sign=selected or None, limit=20 if selected else 40),
    )


@app.get("/solicitar", response_class=HTMLResponse)
async def home(request: Request):
    return render(request, "home.html", geo_ready=geo.stats()["places"] > 0)


@app.get("/api/ubicaciones/paises")
async def location_countries():
    return {"items": geo.list_countries()}


@app.get("/api/ubicaciones/departamentos")
async def location_admin1(country: str):
    if not re.fullmatch(r"[A-Za-z]{2}", country):
        raise HTTPException(400, "Codigo de pais invalido")
    return {"items": geo.list_admin1(country)}


@app.get("/api/ubicaciones/municipios")
async def location_places(country: str, admin1: str):
    if not re.fullmatch(r"[A-Za-z]{2}", country) or not admin1 or len(admin1) > 20:
        raise HTTPException(400, "Division geografica invalida")
    return {"items": geo.list_places(country, admin1)}


@app.post("/api/v5/chart/birth-chart")
async def compatible_birth_chart_api(request: Request):
    """Motor gratuito para conectar la edición PHP con la edición VPS."""
    if settings.astrology_api_key:
        supplied = request.headers.get("X-MS-Astrology-Key", "")
        if not secrets.compare_digest(supplied, settings.astrology_api_key):
            raise HTTPException(401, "Clave del motor astrológico no válida")
    try:
        payload = await request.json()
        subject = payload["subject"]
        record = {
            "id": "api",
            "full_name": str(subject["name"])[:120],
            "birth_date": f"{int(subject['year']):04d}-{int(subject['month']):02d}-{int(subject['day']):02d}",
            "birth_time": f"{int(subject['hour']):02d}:{int(subject['minute']):02d}:00",
            "longitude": float(subject["longitude"]),
            "latitude": float(subject["latitude"]),
            "timezone": str(subject["timezone"]),
        }
    except (KeyError, TypeError, ValueError) as exc:
        raise HTTPException(422, "Faltan datos válidos de nacimiento en subject") from exc
    try:
        with tempfile.TemporaryDirectory(prefix="msastro-chart-") as temporary:
            output_dir = Path(temporary)
            chart_data, _ = calculate_natal_chart(record, output_dir)
            svg = (output_dir / "carta-api.svg").read_text(encoding="utf-8")
    except (AstrologyError, OSError) as exc:
        raise HTTPException(422, str(exc)) from exc
    return {"status": "OK", "chart": svg, "chart_data": chart_data, "language": "ES"}


@app.post("/solicitudes")
async def submit_request(request: Request):
    form = dict(await request.form())
    errors: list[str] = []
    required = ("full_name", "email", "phone", "birth_date", "birth_time", "payment_method", "request_password")
    for field in required:
        if not str(form.get(field, "")).strip():
            errors.append(f"El campo {field} es obligatorio.")
    if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", str(form.get("email", ""))):
        errors.append("El correo electronico no es valido.")
    phone_digits = re.sub(r"\D", "", str(form.get("phone", "")))
    if len(phone_digits) < 7 or len(phone_digits) > 15:
        errors.append("El numero de telefono no es valido.")
    if form.get("payment_method") not in VALID_PAYMENT_METHODS:
        errors.append("Selecciona Nequi, Daviplata o Llave como medio de pago.")
    if len(str(form.get("request_password", ""))) < 8:
        errors.append("La contrasena de seguimiento debe tener al menos 8 caracteres.")
    place = None
    city_id = str(form.get("city_id", "")).strip()
    if city_id:
        try:
            place = geo.get_place(int(city_id))
        except ValueError:
            place = None
        if not place:
            errors.append("La localidad seleccionada no existe en la base geografica.")
    if place:
        latitude = float(place["latitude"])
        longitude = float(place["longitude"])
        form["timezone"] = place["timezone"]
        form["birthplace"] = f"{place['name']}, {place['admin1_name']}, {place['country_name']}"
    else:
        for field in ("birthplace", "latitude", "longitude", "timezone"):
            if not str(form.get(field, "")).strip():
                errors.append(f"El campo {field} es obligatorio.")
        try:
            latitude = float(form.get("latitude", ""))
            longitude = float(form.get("longitude", ""))
            if not -90 <= latitude <= 90 or not -180 <= longitude <= 180:
                raise ValueError
        except ValueError:
            errors.append("Las coordenadas no son validas.")
            latitude = longitude = 0.0
        try:
            pytz.timezone(str(form.get("timezone", "")))
        except pytz.UnknownTimeZoneError:
            errors.append("Usa una zona horaria IANA valida, por ejemplo America/Bogota.")
    try:
        date.fromisoformat(str(form.get("birth_date", "")))
        time.fromisoformat(str(form.get("birth_time", "")))
    except ValueError:
        errors.append("La fecha o la hora de nacimiento no es valida.")
    if form.get("consent") != "yes":
        errors.append("Debes autorizar el uso de los datos para elaborar la carta.")
    if errors:
        return render(request, "home.html", status_code=422, errors=errors, values=form, geo_ready=geo.stats()["places"] > 0)
    record = db.create_service_request({**form, "phone": phone_digits, "latitude": latitude, "longitude": longitude})
    return RedirectResponse(f"/solicitudes/recibida/{record['public_id']}", status_code=303)


@app.get("/solicitudes/recibida/{public_id}", response_class=HTMLResponse)
async def request_received(request: Request, public_id: str):
    return render(request, "received.html", public_id=public_id)


@app.get("/mi-solicitud", response_class=HTMLResponse)
async def track_request(request: Request):
    return render(request, "track.html")


@app.post("/mi-solicitud", response_class=HTMLResponse)
async def track_request_submit(request: Request):
    form = dict(await request.form())
    record = db.get_service_request_by_public_id(str(form.get("public_id", "")))
    if not record or not record.get("request_password_hash") or not verify_password(
        str(form.get("password", "")), record["request_password_hash"]
    ):
        return render(request, "track.html", status_code=401, error="Codigo o contrasena incorrectos.")
    safe_record = {key: value for key, value in record.items() if key != "request_password_hash"}
    private_result_url = delivery.result_url(record) if delivery.is_ready(record) else None
    return render(request, "track.html", item=safe_record, result_url=private_result_url)


@app.get("/resultado/{public_id}", response_class=HTMLResponse)
async def client_result(request: Request, public_id: str, token: str):
    record = require_ready_result(public_id, token)
    return render(
        request,
        "result.html",
        item=record,
        token=token,
        messages=db.list_aurita_messages(record["id"]),
        aurita_available=bool(db.list_ai_configs()),
    )


@app.get("/resultado/{public_id}/pdf")
async def client_result_pdf(public_id: str, token: str):
    record = require_ready_result(public_id, token)
    path = generate_reading_pdf(record, settings.results_dir, BASE_DIR / "static")
    return FileResponse(path, media_type="application/pdf", filename=f"carta-natal-{record['public_id']}.pdf")


@app.post("/resultado/{public_id}/aurita")
async def client_aurita(request: Request, public_id: str):
    token = request.headers.get("X-Result-Token")
    record = require_ready_result(public_id, token)
    try:
        body = await request.json()
    except ValueError:
        return JSONResponse({"error": "Solicitud no valida."}, status_code=400)
    question = str(body.get("message", "")).strip()
    if not 2 <= len(question) <= 1200:
        return JSONResponse({"error": "La pregunta debe tener entre 2 y 1200 caracteres."}, status_code=400)
    config = db.get_ai_config(str(record.get("ai_provider") or ""))
    if not config:
        configs = db.list_ai_configs()
        config = configs[0] if configs else None
    if not config:
        return JSONResponse({"error": "Aurita no tiene un proveedor de IA configurado."}, status_code=503)
    history = db.list_aurita_messages(record["id"])
    try:
        prompt = build_aurita_prompt(record, history, question)
        answer = await generate_interpretation(
            config["provider"], decrypt_secret(config["encrypted_api_key"]), config["model"], prompt
        )
        db.add_aurita_message(record["id"], "user", question)
        db.add_aurita_message(record["id"], "assistant", answer)
        return {"answer": answer, "answer_html": str(render_markdown(answer))}
    except (ProviderError, AstrologyError, ValueError) as exc:
        return JSONResponse({"error": str(exc)}, status_code=502)


@app.get("/admin/login", response_class=HTMLResponse)
async def admin_login(request: Request):
    if current_admin(request):
        return RedirectResponse("/admin", status_code=303)
    return render(request, "login.html")


@app.post("/admin/login")
async def admin_login_submit(request: Request):
    form = dict(await request.form())
    admin = db.get_admin_by_username(str(form.get("username", "")))
    if not admin or not verify_password(str(form.get("password", "")), admin["password_hash"]):
        return render(request, "login.html", status_code=401, error="Usuario o contrasena incorrectos.")
    response = RedirectResponse("/admin", status_code=303)
    response.set_cookie(
        SESSION_COOKIE,
        create_session(admin["id"]),
        httponly=True,
        secure=settings.production,
        samesite="strict",
        max_age=8 * 60 * 60,
    )
    return response


@app.post("/admin/logout")
async def admin_logout(request: Request):
    session, _ = require_admin(request)
    await require_form_csrf(request, session)
    response = RedirectResponse("/admin/login", status_code=303)
    response.delete_cookie(SESSION_COOKIE)
    return response


@app.get("/admin", response_class=HTMLResponse)
async def admin_dashboard(request: Request, status: str = "all"):
    session, admin = require_admin(request)
    selected = status if status in VALID_STATUSES | {"all"} else "all"
    return render(
        request,
        "dashboard.html",
        admin=admin,
        session=session,
        selected=selected,
        requests=db.list_service_requests(selected),
        counts=db.dashboard_counts(),
    )


@app.get("/admin/solicitudes/{request_id}", response_class=HTMLResponse)
async def admin_request_detail(request: Request, request_id: int, message: str | None = None, error: str | None = None):
    session, admin = require_admin(request)
    record = db.get_service_request(request_id)
    if not record:
        raise HTTPException(404, "Solicitud no encontrada")
    configs = [{**item, "encrypted_api_key": None} for item in db.list_ai_configs()]
    deliveries = db.latest_deliveries(record["id"])
    return render(
        request,
        "request_detail.html",
        admin=admin,
        session=session,
        item=record,
        configs=configs,
        deliveries=deliveries,
        delivery_ready=delivery.is_ready(record),
        result_url=delivery.result_url(record),
        manual_email_url=delivery.manual_email_url(record),
        manual_whatsapp_url=delivery.manual_whatsapp_url(record),
        message=message,
        error=error,
    )


@app.post("/admin/solicitudes/{request_id}/estado")
async def admin_request_status(request: Request, request_id: int):
    session, _ = require_admin(request)
    form = await require_form_csrf(request, session)
    status = str(form.get("status", ""))
    if status not in VALID_STATUSES:
        raise HTTPException(400, "Estado invalido")
    db.update_request_status(request_id, status)
    return redirect_with_message(f"/admin/solicitudes/{request_id}", message="Estado actualizado")


@app.post("/admin/solicitudes/{request_id}/pago")
async def admin_payment_status(request: Request, request_id: int):
    session, _ = require_admin(request)
    form = await require_form_csrf(request, session)
    status = str(form.get("payment_status", ""))
    if status not in VALID_PAYMENT_STATUSES:
        raise HTTPException(400, "Estado de pago invalido")
    db.update_payment_status(request_id, status)
    record = db.get_service_request(request_id)
    outcome = await delivery.deliver_result(record, BASE_DIR / "static") if record else {"ready": "no"}
    message = "Estado del pago actualizado"
    if outcome.get("ready") == "yes":
        message += ". Entrega procesada; revisa el estado de correo y WhatsApp."
    elif status == "paid":
        message += ". Se enviara el resultado cuando la interpretacion este lista."
    return redirect_with_message(f"/admin/solicitudes/{request_id}", message=message)


@app.post("/admin/solicitudes/{request_id}/calcular")
async def admin_calculate(request: Request, request_id: int):
    session, _ = require_admin(request)
    await require_form_csrf(request, session)
    record = db.get_service_request(request_id)
    if not record:
        raise HTTPException(404, "Solicitud no encontrada")
    try:
        chart, svg_path = calculate_natal_chart(record, BASE_DIR / "static" / "generated")
        db.save_chart(request_id, chart, svg_path)
        return redirect_with_message(f"/admin/solicitudes/{request_id}", message="Carta calculada")
    except AstrologyError as exc:
        return redirect_with_message(f"/admin/solicitudes/{request_id}", error=str(exc))


@app.post("/admin/solicitudes/{request_id}/interpretar")
async def admin_interpret(request: Request, request_id: int):
    session, _ = require_admin(request)
    form = await require_form_csrf(request, session)
    provider = str(form.get("provider", ""))
    config = db.get_ai_config(provider)
    record = db.get_service_request(request_id)
    if not config or not record:
        return redirect_with_message(f"/admin/solicitudes/{request_id}", error="Falta configurar el proveedor")
    try:
        prompt = build_interpretation_prompt(record)
        text = await generate_interpretation(provider, decrypt_secret(config["encrypted_api_key"]), config["model"], prompt)
        db.save_interpretation(request_id, text, provider, config["model"])
        updated = db.get_service_request(request_id)
        outcome = await delivery.deliver_result(updated, BASE_DIR / "static") if updated else {"ready": "no"}
        message = "Interpretacion generada"
        if outcome.get("ready") == "yes":
            message += ". La entrega al cliente fue procesada."
        return redirect_with_message(f"/admin/solicitudes/{request_id}", message=message)
    except (ProviderError, AstrologyError, ValueError) as exc:
        return redirect_with_message(f"/admin/solicitudes/{request_id}", error=str(exc))


@app.post("/admin/solicitudes/{request_id}/notificar")
async def admin_notify_result(request: Request, request_id: int):
    session, _ = require_admin(request)
    await require_form_csrf(request, session)
    record = db.get_service_request(request_id)
    if not record:
        raise HTTPException(404, "Solicitud no encontrada")
    if not delivery.is_ready(record):
        return redirect_with_message(
            f"/admin/solicitudes/{request_id}",
            error="Confirma el pago y genera la interpretacion antes de notificar.",
        )
    await delivery.deliver_result(record, BASE_DIR / "static", force=True)
    return redirect_with_message(
        f"/admin/solicitudes/{request_id}", message="Entrega reintentada; revisa el estado de cada canal."
    )


@app.get("/admin/ia", response_class=HTMLResponse)
async def admin_ai_settings(request: Request, message: str | None = None, error: str | None = None):
    session, admin = require_admin(request)
    configs = [{**item, "encrypted_api_key": None} for item in db.list_ai_configs()]
    return render(request, "ai_settings.html", admin=admin, session=session, configs=configs, message=message, error=error)


@app.get("/admin/horoscopos", response_class=HTMLResponse)
async def admin_horoscopes(request: Request, edit: int = 0, message: str | None = None, error: str | None = None):
    session, admin = require_admin(request)
    configs = [{**item, "encrypted_api_key": None} for item in db.list_ai_configs()]
    return render(
        request,
        "horoscopes_admin.html",
        session=session,
        admin=admin,
        configs=configs,
        items=db.list_horoscopes(limit=40),
        edit_id=edit,
        zodiac_signs=ZODIAC_SIGNS,
        periods=HOROSCOPE_PERIODS,
        default_period=f"Semana del {date.today().strftime('%d/%m/%Y')}",
        message=message,
        error=error,
    )


@app.post("/admin/horoscopos/generar")
async def admin_horoscope_generate(request: Request):
    session, _ = require_admin(request)
    form = await require_form_csrf(request, session)
    sign = str(form.get("sign", ""))
    period_type = str(form.get("period_type", "weekly"))
    period_label = str(form.get("period_label", "")).strip()
    provider = str(form.get("provider", ""))
    config = db.get_ai_config(provider)
    if (sign != "all" and sign not in ZODIAC_SIGNS) or period_type not in HOROSCOPE_PERIODS or not period_label or not config:
        return redirect_with_message("/admin/horoscopos", error="Revisa el signo, el periodo y el proveedor de IA.")
    try:
        targets = list(ZODIAC_SIGNS) if sign == "all" else [sign]
        api_key = decrypt_secret(config["encrypted_api_key"])
        drafts = []
        last_item = None
        for target_sign in targets:
            prompt = build_horoscope_prompt(
                ZODIAC_SIGNS[target_sign][0], HOROSCOPE_PERIODS[period_type], period_label,
                str(form.get("focus", "")).strip(),
            )
            content = await generate_interpretation(provider, api_key, config["model"], prompt)
            drafts.append((target_sign, content))
        for target_sign, content in drafts:
            last_item = db.create_horoscope(
                {
                    "sign": target_sign, "period_type": period_type, "period_label": period_label,
                    "title": f"{ZODIAC_SIGNS[target_sign][0]} · {period_label} · Tres decanatos", "content": content,
                    "ai_provider": provider, "ai_model": config["model"],
                }
            )
        destination = "/admin/horoscopos" if len(targets) == 12 else f"/admin/horoscopos?edit={last_item['id']}"
        message = "Se generaron 12 borradores con sus tres decanatos. Revísalos antes de publicar." if len(targets) == 12 else "Borrador generado con sus tres decanatos. Revísalo antes de publicar."
        return redirect_with_message(destination, message=message)
    except (ProviderError, ValueError) as exc:
        return redirect_with_message("/admin/horoscopos", error=str(exc))


@app.post("/admin/horoscopos/{horoscope_id}")
async def admin_horoscope_save(request: Request, horoscope_id: int):
    session, _ = require_admin(request)
    form = await require_form_csrf(request, session)
    sign = str(form.get("sign", ""))
    period_type = str(form.get("period_type", "weekly"))
    period_label = str(form.get("period_label", "")).strip()
    title = str(form.get("title", "")).strip()
    content = str(form.get("content", "")).strip()
    status = "published" if form.get("status") == "published" else "draft"
    if not db.get_horoscope(horoscope_id):
        raise HTTPException(404, "Horóscopo no encontrado")
    if sign not in ZODIAC_SIGNS or period_type not in HOROSCOPE_PERIODS or not period_label or len(title) < 4 or len(content) < 80:
        return redirect_with_message(f"/admin/horoscopos?edit={horoscope_id}", error="El horóscopo está incompleto.")
    db.update_horoscope(
        horoscope_id,
        {"sign": sign, "period_type": period_type, "period_label": period_label, "title": title, "content": content, "status": status},
    )
    message = "Horóscopo publicado en la página." if status == "published" else "Borrador guardado."
    return redirect_with_message(f"/admin/horoscopos?edit={horoscope_id}", message=message)


@app.post("/admin/api/ia/{provider}/modelos")
async def admin_ai_models(request: Request, provider: str):
    session, _ = require_admin(request)
    if not validate_csrf(session, request.headers.get("X-CSRF-Token")):
        return JSONResponse({"error": "Token de seguridad invalido"}, status_code=403)
    if provider not in {"openai", "gemini"}:
        return JSONResponse({"error": "Proveedor no compatible"}, status_code=400)
    body = await request.json()
    api_key = str(body.get("api_key", "")).strip()
    if not api_key:
        config = db.get_ai_config(provider)
        if config:
            api_key = decrypt_secret(config["encrypted_api_key"])
    try:
        models = await list_models(provider, api_key)
        return {"models": models}
    except (ProviderError, ValueError) as exc:
        return JSONResponse({"error": str(exc)}, status_code=400)


@app.post("/admin/ia/{provider}")
async def admin_ai_save(request: Request, provider: str):
    session, _ = require_admin(request)
    form = await require_form_csrf(request, session)
    if provider not in {"openai", "gemini"}:
        raise HTTPException(400, "Proveedor no compatible")
    api_key = str(form.get("api_key", "")).strip()
    existing = db.get_ai_config(provider)
    if not api_key and existing:
        api_key = decrypt_secret(existing["encrypted_api_key"])
    model = str(form.get("model", "")).strip()
    try:
        models = await list_models(provider, api_key)
        allowed = {item["id"] for item in models}
        if model not in allowed:
            raise ProviderError("Selecciona un modelo disponible para esta clave.")
        db.save_ai_config(provider, encrypt_secret(api_key), model)
        return redirect_with_message("/admin/ia", message=f"{provider} verificado y guardado")
    except (ProviderError, ValueError) as exc:
        return redirect_with_message("/admin/ia", error=str(exc))


@app.get("/admin/perfil", response_class=HTMLResponse)
async def admin_profile(request: Request, message: str | None = None, error: str | None = None):
    session, admin = require_admin(request)
    return render(request, "profile.html", session=session, admin=admin, message=message, error=error)


@app.post("/admin/perfil")
async def admin_profile_save(request: Request):
    session, admin = require_admin(request)
    form = await require_form_csrf(request, session)
    current_password = str(form.get("current_password", ""))
    if not verify_password(current_password, admin["password_hash"]):
        return redirect_with_message("/admin/perfil", error="La contrasena actual no coincide")
    username = str(form.get("username", "")).strip()
    new_password = str(form.get("new_password", ""))
    if len(username) < 3:
        return redirect_with_message("/admin/perfil", error="El usuario es demasiado corto")
    try:
        password_hash = hash_password(new_password) if new_password else None
        db.update_admin(admin["id"], username, password_hash)
    except (ValueError, sqlite3.IntegrityError) as exc:
        return redirect_with_message("/admin/perfil", error=str(exc))
    return redirect_with_message("/admin/perfil", message="Perfil actualizado")


@app.get("/salud")
async def health():
    return {"status": "ok", "service": "msastrologia"}
