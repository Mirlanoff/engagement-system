from fastapi import APIRouter
from app.services.capture_manager import CaptureManager

router = APIRouter()
manager = CaptureManager()


@router.get("/status")
async def status():
    return {
        "active_sessions": manager.active_sessions(),
        "total_processed_frames": manager.total_frames(),
    }
