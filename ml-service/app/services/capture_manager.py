import asyncio
import threading
from typing import Dict, List
import structlog

from app.tasks.frame_analysis import process_frame_batch

logger = structlog.get_logger()


class CaptureSession:
    """Одна активная сессия захвата (один урок, один класс)."""

    def __init__(self, session_id: str, classroom_id: str, cameras: List[dict], student_ids: List[str]):
        self.session_id   = session_id
        self.classroom_id = classroom_id
        self.cameras      = cameras
        self.student_ids  = student_ids
        self.is_paused    = False
        self.is_running   = False
        self.frames_count = 0
        self._threads: List[threading.Thread] = []

    def start(self, frame_interval: int):
        self.is_running = True
        for camera in self.cameras:
            t = threading.Thread(
                target=self._capture_loop,
                args=(camera, frame_interval),
                daemon=True,
            )
            t.start()
            self._threads.append(t)
        logger.info("Capture session started",
                    session_id=self.session_id,
                    cameras=len(self.cameras))

    def stop(self):
        self.is_running = False
        logger.info("Capture session stopped", session_id=self.session_id)

    def pause(self):
        self.is_paused = True

    def resume(self):
        self.is_paused = False

    def _capture_loop(self, camera: dict, interval: int):
        """
        Основной цикл захвата кадров с одной камеры.
        Работает в отдельном потоке.
        """
        import cv2
        import time

        rtsp_url = camera["rtsp_url"]
        camera_id = camera["id"]

        # Опции для стабильного RTSP
        cap = cv2.VideoCapture(rtsp_url, cv2.CAP_FFMPEG)
        cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)  # минимальный буфер = меньше задержка

        if not cap.isOpened():
            logger.error("Cannot open camera", camera_id=camera_id, url=rtsp_url)
            self._notify_laravel_camera_error(camera_id, "Cannot open stream")
            return

        logger.info("Camera opened", camera_id=camera_id)

        while self.is_running:
            if self.is_paused:
                time.sleep(0.5)
                continue

            ret, frame = cap.read()
            if not ret:
                logger.warning("Frame read failed, reconnecting...", camera_id=camera_id)
                time.sleep(2)
                cap = cv2.VideoCapture(rtsp_url, cv2.CAP_FFMPEG)
                continue

            # Отправляем кадр в Celery для анализа
            process_frame_batch.apply_async(
                args=[{
                    "session_id":   self.session_id,
                    "classroom_id": self.classroom_id,
                    "camera_id":    camera_id,
                    "frame_bytes":  self._encode_frame(frame),
                    "student_ids":  self.student_ids,
                }],
                queue="frame_analysis",
            )
            self.frames_count += 1

            time.sleep(interval)

        cap.release()
        logger.info("Camera released", camera_id=camera_id)

    def _encode_frame(self, frame) -> str:
        """Кодируем кадр в base64 для передачи через Celery."""
        import cv2
        import base64
        _, buffer = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
        return base64.b64encode(buffer).decode("utf-8")

    def _notify_laravel_camera_error(self, camera_id: str, error: str):
        import httpx
        import hashlib, hmac, time, json
        from app.config import settings

        body = json.dumps({"camera_id": camera_id, "error": error})
        ts   = str(int(time.time()))
        sig  = hmac.new(
            settings.laravel_api_secret.encode(),
            (ts + body).encode(),
            hashlib.sha256,
        ).hexdigest()

        try:
            httpx.post(
                f"{settings.laravel_api_url}/sessions/{self.session_id}/camera-error",
                content=body,
                headers={
                    "Content-Type":          "application/json",
                    "X-Internal-Signature":  sig,
                    "X-Internal-Timestamp":  ts,
                },
                timeout=5,
            )
        except Exception:
            pass


class CaptureManager:
    """Синглтон — управляет всеми активными сессиями захвата."""

    _instance = None
    _sessions: Dict[str, CaptureSession] = {}

    def __new__(cls):
        if cls._instance is None:
            cls._instance = super().__new__(cls)
            cls._instance._sessions = {}
            cls._instance._total_frames = 0
        return cls._instance

    async def start(self, session_id: str, classroom_id: str, cameras: List[dict], student_ids: List[str]):
        if session_id in self._sessions:
            await self.stop(session_id)

        from app.config import settings
        session = CaptureSession(session_id, classroom_id, cameras, student_ids)
        session.start(settings.frame_interval_seconds)
        self._sessions[session_id] = session

    async def stop(self, session_id: str):
        if session := self._sessions.pop(session_id, None):
            session.stop()

    async def pause(self, session_id: str):
        if session := self._sessions.get(session_id):
            session.pause()

    async def resume(self, session_id: str):
        if session := self._sessions.get(session_id):
            session.resume()

    def active_sessions(self) -> List[str]:
        return list(self._sessions.keys())

    def total_frames(self) -> int:
        return sum(s.frames_count for s in self._sessions.values())
