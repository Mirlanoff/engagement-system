from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field, model_validator
from typing import List, Optional
import structlog

from app.services.capture_manager import CaptureManager

router = APIRouter()
logger = structlog.get_logger()
manager = CaptureManager()


class CameraConfig(BaseModel):
    id: str
    rtsp_url: Optional[str] = None
    source: Optional[str] = None
    device_index: Optional[int] = None
    position: str = "front"
    is_active: bool = True

    @model_validator(mode="after")
    def validate_source(self):
        if not self.rtsp_url and not self.source and self.device_index is None:
            raise ValueError("Camera requires rtsp_url, source, or device_index")
        return self


class StartCaptureRequest(BaseModel):
    session_id: str
    classroom_id: str
    cameras: List[CameraConfig]
    student_ids: List[str] = Field(default_factory=list)


class SessionRequest(BaseModel):
    session_id: str


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
