"""
Face embedding extraction endpoint.

Used by Laravel to register a student's face from a single reference photo.
The embedding is later compared (cosine similarity) against per-frame
embeddings during a live session to identify which detected face belongs
to which student — replacing the legacy "left-to-right by position" mapping.
"""

import base64
from typing import List, Optional

import cv2
import numpy as np
import structlog
from fastapi import APIRouter
from pydantic import BaseModel

from app.ml.embedding_utils import EMBEDDING_DIM, compute_geometric_embedding
from app.ml.model_manager import ModelManager

router = APIRouter(prefix="/embeddings", tags=["embeddings"])
logger = structlog.get_logger()


class GenerateEmbeddingRequest(BaseModel):
    student_id: str
    image_b64: str


class BBox(BaseModel):
    x: int
    y: int
    width: int
    height: int


class EmbeddingResponse(BaseModel):
    status: str
    student_id: str
    embedding: Optional[List[float]] = None
    face_detected: bool = False
    faces_count: int = 0
    bbox: Optional[BBox] = None
    message: Optional[str] = None


@router.post("/generate", response_model=EmbeddingResponse)
async def generate_embedding(req: GenerateEmbeddingRequest) -> EmbeddingResponse:
    image = _decode_image(req.image_b64)
    if image is None:
        return EmbeddingResponse(
            status="error",
            student_id=req.student_id,
            faces_count=0,
            face_detected=False,
            message="Invalid image data",
        )

    detections = _detect_faces(image)
    faces_count = len(detections)

    if faces_count == 0:
        return EmbeddingResponse(
            status="error",
            student_id=req.student_id,
            faces_count=0,
            face_detected=False,
            message="No face detected in image",
        )

    if faces_count > 1:
        return EmbeddingResponse(
            status="error",
            student_id=req.student_id,
            faces_count=faces_count,
            face_detected=True,
            message="Multiple faces detected in image",
        )

    bbox, landmarks = detections[0]
    embedding = compute_geometric_embedding(landmarks)

    if not embedding or len(embedding) != EMBEDDING_DIM or _is_zero_vector(embedding):
        return EmbeddingResponse(
            status="error",
            student_id=req.student_id,
            faces_count=1,
            face_detected=True,
            bbox=BBox(**bbox),
            message="Failed to compute face embedding",
        )

    return EmbeddingResponse(
        status="ok",
        student_id=req.student_id,
        embedding=embedding,
        face_detected=True,
        faces_count=1,
        bbox=BBox(**bbox),
    )


# ── Internals ──────────────────────────────────────────────────────────────


def _decode_image(image_b64: str) -> Optional[np.ndarray]:
    """Decode a base64-encoded image into a BGR numpy array."""
    try:
        # Tolerate `data:image/jpeg;base64,...` prefixes.
        if "," in image_b64 and image_b64.lstrip().startswith("data:"):
            image_b64 = image_b64.split(",", 1)[1]
        raw = base64.b64decode(image_b64, validate=False)
    except Exception as exc:
        logger.warning("embedding: base64 decode failed", error=str(exc))
        return None

    arr = np.frombuffer(raw, dtype=np.uint8)
    if arr.size == 0:
        return None

    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        return None
    return img


def _detect_faces(image_bgr: np.ndarray):
    """
    Detect faces in the image using the same MediaPipe FaceMesh used for live
    analysis. Returns a list of ``(bbox_dict, landmarks)`` tuples — bbox in
    pixel coordinates, landmarks as the raw MediaPipe landmark sequence
    (each element exposes ``.x``, ``.y``, ``.z``) so callers can pass them
    straight to :func:`compute_geometric_embedding`.
    """
    face_mesh = ModelManager.get_face_detector()
    if face_mesh is None:
        logger.warning("embedding: face detector unavailable")
        return []

    h, w = image_bgr.shape[:2]
    rgb = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2RGB)
    result = face_mesh.process(rgb)

    if not result.multi_face_landmarks:
        return []

    detections = []
    for face_landmarks in result.multi_face_landmarks:
        xs = [lm.x * w for lm in face_landmarks.landmark]
        ys = [lm.y * h for lm in face_landmarks.landmark]
        x0, x1 = max(0, int(min(xs))), min(w, int(max(xs)))
        y0, y1 = max(0, int(min(ys))), min(h, int(max(ys)))
        bw, bh = max(1, x1 - x0), max(1, y1 - y0)
        bbox = {"x": x0, "y": y0, "width": bw, "height": bh}
        detections.append((bbox, face_landmarks.landmark))

    return detections


def _is_zero_vector(vec: List[float]) -> bool:
    return all(abs(v) < 1e-12 for v in vec)
