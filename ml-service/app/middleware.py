import hashlib
import hmac
import time
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse

from app.config import settings

EXEMPT_PATHS = {"/health", "/metrics"}


class InternalAuthMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        if request.url.path in EXEMPT_PATHS or (
            settings.expose_docs and request.url.path in {"/docs", "/openapi.json"}
        ):
            return await call_next(request)

        signature = request.headers.get("X-Internal-Signature")
        timestamp  = request.headers.get("X-Internal-Timestamp")

        if not signature or not timestamp:
            return JSONResponse({"error": "Missing auth headers"}, status_code=401)

        # Защита от replay-атак — запрос не старше 30 секунд
        try:
            ts = int(timestamp)
        except ValueError:
            return JSONResponse({"error": "Invalid timestamp"}, status_code=401)

        if abs(time.time() - ts) > 30:
            return JSONResponse({"error": "Request expired"}, status_code=401)

        # Проверка HMAC подписи
        body = await request.body()
        expected = hmac.new(
            settings.laravel_api_secret.encode(),
            (timestamp + body.decode("utf-8", errors="")).encode(),
            hashlib.sha256,
        ).hexdigest()

        if not hmac.compare_digest(expected, signature):
            return JSONResponse({"error": "Invalid signature"}, status_code=401)

        return await call_next(request)
