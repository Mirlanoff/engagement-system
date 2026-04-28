import hashlib
import hmac
import json
import time
from datetime import datetime, timezone

import httpx
import structlog
from celery import shared_task

from app.worker import celery_app
from app.config import settings
from app.ml.face_analyzer import FaceAnalyzer
from app.ml.scorer import FaceAnalysis

logger = structlog.get_logger()
analyzer = FaceAnalyzer()


@celery_app.task(
    name="app.tasks.frame_analysis.process_frame_batch",
    bind=True,
    max_retries=2,
    default_retry_delay=3,
    queue="frame_analysis",
)
def process_frame_batch(self, payload: dict):
    """
    Celery задача: анализирует кадр → считает scores → отправляет в Laravel.

    payload = {
        session_id, classroom_id, camera_id,
        frame_bytes (base64),
        student_ids (список UUID студентов в классе)
    }
    """
    session_id   = payload["session_id"]
    classroom_id = payload["classroom_id"]
    camera_id    = payload["camera_id"]
    frame_b64    = payload["frame_bytes"]
    student_ids  = payload.get("student_ids", [])

    t_start = time.time()

    try:
        # 1. Анализируем кадр
        captured_at = datetime.now(timezone.utc).isoformat()
        analyses = analyzer.analyze_frame(
            frame_bytes_b64=frame_b64,
            session_id=session_id,
            camera_id=camera_id,
            student_ids=student_ids,
            captured_at=captured_at,
        )

        if not analyses:
            return {"status": "no_faces", "session_id": session_id}

        # 2. Формируем payload для Laravel
        snapshots = [_analysis_to_dict(a) for a in analyses]

        # 3. Отправляем в Laravel
        _push_to_laravel(session_id, snapshots)

        elapsed = round((time.time() - t_start) * 1000, 1)
        logger.debug(
            "Frame processed",
            session_id=session_id,
            faces=len(analyses),
            elapsed_ms=elapsed,
        )
        return {"status": "ok", "faces": len(analyses), "elapsed_ms": elapsed}

    except Exception as exc:
        logger.error("Frame processing failed", error=str(exc), session_id=session_id)
        raise self.retry(exc=exc)


def _analysis_to_dict(a: FaceAnalysis) -> dict:
    return {
        "student_id":          a.student_id,
        "camera_id":           a.camera_id,
        "captured_at":         a.captured_at,
        "engagement_score":    a.engagement_score,
        "gaze_score":          a.gaze_score,
        "emotion_score":       a.emotion_score,
        "head_pose_score":     a.head_pose_score,
        "presence_score":      a.presence_score,
        "emotion":             a.emotion,
        "emotion_confidence":  a.emotion_confidence,
        "gaze_yaw":            a.gaze_yaw,
        "gaze_pitch":          a.gaze_pitch,
        "head_yaw":            a.head_yaw,
        "head_pitch":          a.head_pitch,
        "head_roll":           a.head_roll,
        "face_detected":       a.face_detected,
        "face_confidence":     a.face_confidence,
        "face_bbox_x":         a.face_bbox_x,
        "face_bbox_y":         a.face_bbox_y,
        "face_bbox_w":         a.face_bbox_w,
        "face_bbox_h":         a.face_bbox_h,
        "processing_time_ms":  a.processing_time_ms,
    }


def _push_to_laravel(session_id: str, snapshots: list):
    """Отправляем снэпшоты в Laravel с HMAC подписью."""
    body = json.dumps({
        "session_id": session_id,
        "snapshots":  snapshots,
    })
    ts  = str(int(time.time()))
    sig = hmac.new(
        settings.laravel_api_secret.encode(),
        (ts + body).encode(),
        hashlib.sha256,
    ).hexdigest()

    try:
        resp = httpx.post(
            f"{settings.laravel_api_url}/snapshots",
            content=body,
            headers={
                "Content-Type":         "application/json",
                "X-Internal-Signature": sig,
                "X-Internal-Timestamp": ts,
            },
            timeout=10,
        )
        resp.raise_for_status()
    except httpx.HTTPStatusError as e:
        logger.error("Laravel rejected snapshots",
                     status=e.response.status_code,
                     body=e.response.text[:200])
        raise
    except Exception as e:
        logger.error("Failed to push to Laravel", error=str(e))
        raise
