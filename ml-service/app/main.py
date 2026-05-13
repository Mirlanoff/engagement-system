from fastapi import FastAPI, HTTPException, Depends, Request
from fastapi.middleware.cors import CORSMiddleware
from contextlib import asynccontextmanager
import structlog
import time

from app.config import settings
from app.routers import capture, embeddings, status
from app.middleware import InternalAuthMiddleware
from app.worker import celery_app

logger = structlog.get_logger()


@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("ML Service starting", version="1.0.0")
    # Проверяем что модели загружены
    from app.ml.model_manager import ModelManager
    ModelManager.warmup()
    # Прогреваем Facenet — первая загрузка веса (~90MB) пройдёт здесь,
    # а не на первом запросе клиента (иначе бы он висел 30+ секунд).
    from app.ml import embedding_utils
    embedding_utils.warmup()
    logger.info("Models loaded and ready")
    yield
    logger.info("ML Service shutting down")


app = FastAPI(
    title="Engagement ML Service",
    description="Сервис анализа вовлечённости студентов",
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/docs",
    redoc_url=None,
)

# CORS — только внутренняя сеть
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://laravel", "http://nginx"],
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)

# HMAC аутентификация для всех роутов кроме /health и /docs
app.add_middleware(InternalAuthMiddleware)

# Роутеры
app.include_router(capture.router, prefix="/capture", tags=["capture"])
app.include_router(status.router, tags=["status"])
app.include_router(embeddings.router)


@app.get("/health")
async def health():
    return {
        "status": "ok",
        "timestamp": time.time(),
        "celery": _check_celery(),
    }


def _check_celery() -> str:
    try:
        celery_app.control.ping(timeout=1)
        return "ok"
    except Exception:
        return "unavailable"
