from celery import Celery
from app.config import settings

celery_app = Celery(
    "engagement_ml",
    broker=settings.celery_broker_url,
    backend=settings.celery_result_backend,
    include=[
        "app.tasks.frame_analysis",
        "app.tasks.scoring",
    ],
)

celery_app.conf.update(
    task_serializer="json",
    result_serializer="json",
    accept_content=["json"],
    timezone="Asia/Bishkek",
    enable_utc=True,
    task_track_started=True,
    task_acks_late=True,
    worker_prefetch_multiplier=1,
    task_routes={
        "app.tasks.frame_analysis.*": {"queue": "frame_analysis"},
        "app.tasks.scoring.*":        {"queue": "scoring"},
    },
)
