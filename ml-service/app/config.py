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
    frame_interval_seconds: int = 2
    max_workers: int = 4

    # ML модели
    model_backend: Literal["opencv", "mediapipe", "deepface"] = "mediapipe"

    # Если True — при отсутствии моделей возвращать рандомные результаты (только для разработки!).
    # В production должен быть False — пайплайн вернёт 503/ошибку, а не фейк.
    ml_dev_mode: bool = False

    # Веса для подсчёта engagement score.
    # Если эмоция или поза недоступны — их вес перенормируется на остальные.
    weight_presence: float = 0.15
    weight_gaze: float = 0.30
    weight_emotion: float = 0.20
    weight_head_pose: float = 0.15
    weight_posture: float = 0.20

    # Сглаживание engagement_score
    smooth_window_seconds: int = 30

    # Пороги диагностики кадра
    min_brightness: float = 40.0          # ниже — too_dark
    min_blur_variance: float = 50.0       # ниже — too_blurry
    min_face_bbox_size_px: int = 40       # bbox <40px → face_too_small

    # Логирование
    log_level: str = "INFO"

    class Config:
        env_file = ".env"
        case_sensitive = False


settings = Settings()
