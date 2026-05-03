from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
from typing import List, Optional
import structlog

from app.services.capture_manager import CaptureManager

router = APIRouter()
logger = structlog.get_logger()
manager = CaptureManager()


class CameraConfig(BaseModel):
    id: str
    rtsp_url: str
    position: str = "front"
    is_active: bool = True
    student_ids: List[str] = []


class StartCaptureRequest(BaseModel):
    session_id: str
    classroom_id: str
    cameras: List[CameraConfig]


class SessionRequest(BaseModel):
    session_id: str


@router.post("/start")
async def start_capture(req: StartCaptureRequest):
    try:
        await manager.start(
            session_id=req.session_id,
            classroom_id=req.classroom_id,
            cameras=[c.model_dump() for c in req.cameras if c.is_active],
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
