from pydantic_settings import BaseSettings
from typing import Literal


class Settings(BaseSettings):
    # Laravel API
    laravel_api_url: str = "http://laravel/api/internal"
    laravel_api_secret: str = "secret"

    # Redis
    redis_url: str = "redis://redis:6379/1"
    celery_broker_url: str = "redis://redis:6379/2"
    celery_result_backend: str = "redis://redis:6379/2"

    # Захват кадров
    frame_interval_seconds: int = 10
    max_workers: int = 4

    # ML модели
    model_backend: Literal["opencv", "mediapipe", "deepface"] = "mediapipe"

    # Веса для подсчёта engagement score
    weight_gaze: float = 0.30
    weight_emotion: float = 0.30
    weight_head_pose: float = 0.20
    weight_presence: float = 0.20

    # Логирование
    log_level: str = "INFO"

    class Config:
        env_file = ".env"
        case_sensitive = False


settings = Settings()
