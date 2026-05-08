import hashlib
import hmac
import json
import time
from datetime import datetime, timezone
from typing import List, Optional

import httpx
import structlog
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from app.config import settings
from app.ml.face_analyzer import FaceAnalyzer
from app.services.capture_manager import CaptureManager

router = APIRouter()
logger = structlog.get_logger()
manager = CaptureManager()
analyzer = FaceAnalyzer()


class CameraConfig(BaseModel):
    id: str
    rtsp_url: str
    position: str = "front"
    is_active: bool = True


class StartCaptureRequest(BaseModel):
    session_id: str
    classroom_id: str
    cameras: List[CameraConfig]
    student_ids: List[str] = Field(default_factory=list)


class SessionRequest(BaseModel):
    session_id: str


class AnalyzeFrameRequest(BaseModel):
    """Кадр, пришедший из браузера через Laravel."""
    session_id: str
    classroom_id: Optional[str] = None
    camera_id: str = "browser"
    frame_b64: str
    student_ids: List[str] = Field(default_factory=list)


@router.post("/start")
async def start_capture(req: StartCaptureRequest):
    try:
        await manager.start(
            session_id=req.session_id,
            classroom_id=req.classroom_id,
            cameras=[c.model_dump() for c in req.cameras if c.is_active],
            student_ids=req.student_ids,
        )
        logger.info("Capture started", session_id=req.session_id)
        return {"status": "started", "session_id": req.session_id}
    except Exception as e:
        logger.error("Failed to start capture", error=str(e))
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/stop")
async def stop_capture(req: SessionRequest):
    await manager.stop(req.session_id)
    logger.info("Capture stopped", session_id=req.session_id)
    return {"status": "stopped", "session_id": req.session_id}


@router.post("/pause")
async def pause_capture(req: SessionRequest):
    await manager.pause(req.session_id)
    return {"status": "paused", "session_id": req.session_id}


@router.post("/resume")
async def resume_capture(req: SessionRequest):
    await manager.resume(req.session_id)
    return {"status": "resumed", "session_id": req.session_id}


@router.post("/analyze-frame")
async def analyze_frame(req: AnalyzeFrameRequest):
    """
    Синхронный анализ одного кадра, пришедшего из браузера учителя.
    Вызывается из Laravel при ingest /api/v1/sessions/{id}/frames.
    Сразу анализирует кадр и шлёт снэпшоты обратно в Laravel.
    """
    captured_at = datetime.now(timezone.utc).isoformat()
    student_ids = req.student_ids or [req.session_id]

    try:
        analyses = analyzer.analyze_frame(
            frame_bytes_b64=req.frame_b64,
            session_id=req.session_id,
            camera_id=req.camera_id,
            student_ids=student_ids,
            captured_at=captured_at,
        )
    except Exception as exc:
        logger.error("analyze_frame failed", error=str(exc), session_id=req.session_id)
        raise HTTPException(status_code=500, detail=f"Frame analysis failed: {exc}")

    if not analyses:
        return {"status": "no_faces", "session_id": req.session_id, "snapshots": 0}

    snapshots = [_analysis_to_snapshot(a) for a in analyses]
    pushed = _push_to_laravel(req.session_id, snapshots)

    return {
        "status": "ok",
        "session_id": req.session_id,
        "faces": sum(1 for a in analyses if a.face_detected),
        "snapshots": len(snapshots),
        "pushed_to_laravel": pushed,
    }


def _analysis_to_snapshot(a) -> dict:
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
        "processing_time_ms":  a.processing_time_ms,
    }


def _push_to_laravel(session_id: str, snapshots: list) -> bool:
    """Отправляем снэпшоты в Laravel с HMAC подписью."""
    body = json.dumps({"session_id": session_id, "snapshots": snapshots})
    ts   = str(int(time.time()))
    sig  = hmac.new(
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
        return True
    except Exception as e:
        logger.warning("Laravel push failed", error=str(e), session_id=session_id)
        return False
