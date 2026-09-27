from __future__ import annotations

from typing import Any

import httpx


class ProviderError(RuntimeError):
    pass


def normalize_api_key(value: str) -> str:
    key = value.strip().strip('"').strip("'")
    if "=" in key and key.split("=", 1)[0].strip().upper() in {"GEMINI_API_KEY", "GOOGLE_API_KEY", "OPENAI_API_KEY"}:
        key = key.split("=", 1)[1].strip().strip('"').strip("'")
    return key


async def list_models(provider: str, api_key: str) -> list[dict[str, str]]:
    api_key = normalize_api_key(api_key)
    if not api_key:
        raise ProviderError("La clave API esta vacia.")
    timeout = httpx.Timeout(20.0, connect=10.0)
    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            if provider == "openai":
                response = await client.get(
                    "https://api.openai.com/v1/models",
                    headers={"Authorization": f"Bearer {api_key}"},
                )
                response.raise_for_status()
                ids = [item.get("id", "") for item in response.json().get("data", [])]
                ids = [model for model in ids if model.startswith(("gpt-", "o1", "o3", "o4", "chatgpt-"))]
                return [{"id": model, "name": model} for model in sorted(set(ids))]
            if provider == "gemini":
                response = await client.get(
                    "https://generativelanguage.googleapis.com/v1beta/models",
                    params={"key": api_key, "pageSize": 1000},
                )
                response.raise_for_status()
                models = []
                for item in response.json().get("models", []):
                    methods = item.get("supportedGenerationMethods") or item.get("supportedActions") or []
                    model_id = item.get("name", "").removeprefix("models/")
                    if methods and "generateContent" not in methods:
                        continue
                    if model_id.startswith(("gemini-", "gemma-")):
                        models.append({"id": model_id, "name": item.get("displayName") or model_id})
                if not models:
                    raise ProviderError("La clave fue aceptada, pero Google no devolvio modelos Gemini con acceso a generateContent.")
                return sorted(models, key=lambda item: item["id"])
    except httpx.HTTPStatusError as exc:
        status = exc.response.status_code
        if status in (401, 403):
            raise ProviderError("La clave fue rechazada por el proveedor.") from exc
        detail = _response_error(exc.response)
        raise ProviderError(f"El proveedor respondio con error {status}: {detail}") from exc
    except httpx.HTTPError as exc:
        raise ProviderError("No fue posible conectar con el proveedor de IA.") from exc
    raise ProviderError("Proveedor de IA no compatible.")


def _response_error(response: httpx.Response) -> str:
    try:
        body: Any = response.json()
        error = body.get("error", {}) if isinstance(body, dict) else {}
        return str(error.get("message") or "respuesta no valida")[:240]
    except ValueError:
        return "respuesta no valida"


async def generate_interpretation(provider: str, api_key: str, model: str, prompt: str) -> str:
    api_key = normalize_api_key(api_key)
    timeout = httpx.Timeout(90.0, connect=15.0)
    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            if provider == "openai":
                response = await client.post(
                    "https://api.openai.com/v1/responses",
                    headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
                    json={"model": model, "input": prompt},
                )
                response.raise_for_status()
                body = response.json()
                if body.get("output_text"):
                    return str(body["output_text"]).strip()
                parts: list[str] = []
                for output in body.get("output", []):
                    for content in output.get("content", []):
                        if content.get("type") in ("output_text", "text") and content.get("text"):
                            parts.append(str(content["text"]))
                if parts:
                    return "\n".join(parts).strip()
            elif provider == "gemini":
                response = await client.post(
                    f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent",
                    params={"key": api_key},
                    headers={"Content-Type": "application/json"},
                    json={"contents": [{"role": "user", "parts": [{"text": prompt}]}]},
                )
                response.raise_for_status()
                parts = response.json().get("candidates", [{}])[0].get("content", {}).get("parts", [])
                text = "\n".join(str(part.get("text", "")) for part in parts).strip()
                if text:
                    return text
    except httpx.HTTPStatusError as exc:
        raise ProviderError(f"La IA respondio con error {exc.response.status_code}: {_response_error(exc.response)}") from exc
    except (httpx.HTTPError, IndexError) as exc:
        raise ProviderError("No fue posible obtener la interpretacion de la IA.") from exc
    raise ProviderError("El proveedor no devolvio texto interpretable.")
