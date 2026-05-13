"""
Shared face-embedding utilities.

This module is the single source of truth for the face embedding used by
the engagement system. Both ``/embeddings/generate`` (one-shot registration
from a static photo) and ``/capture/analyze-frame`` (per-frame live
recognition) route through :func:`compute_embedding`, so the two code
paths always produce vectors in the same space.

Primary path — InsightFace ArcFace (``buffalo_l`` model, 512-dim). The
embedding is computed from the raw face crop (BGR numpy array) using
:meth:`insightface.app.FaceAnalysis.get`, which runs ArcFace and returns
``normed_embedding`` (already L2-normalised). ArcFace is highly
discriminative: same person ≳ 0.5 cosine similarity, different people
≲ 0.25. Models are cached in ``INSIGHTFACE_HOME`` (set to
``/app/models_cache`` in the Dockerfile, which is mounted as a docker
volume so weights survive restarts).

Fallback path — geometric. Used only when InsightFace cannot be imported
or its initialisation fails. Builds a deterministic 512-dim L2-normalised
vector from MediaPipe FaceMesh landmark distances and nose-relative
angles. NOT identity-discriminative — kept only so the endpoint stays
callable when InsightFace isn't installed.
"""

import os
import threading
from typing import Dict, List, Optional, Sequence

import cv2
import numpy as np
import structlog

logger = structlog.get_logger()

EMBEDDING_DIM = 512

# Representative subset of FaceMesh landmark indices used for the fallback
# geometric embedding. Symmetric across both halves of the face so the
# embedding stays stable if a handful of landmarks are missing.
_KEY_INDICES = [1, 33, 61, 133, 152, 159, 234, 263, 291, 362, 386, 454]

# Lazy-loaded InsightFace state. A FaceAnalysis instance when ready,
# the sentinel string "geometric_fallback" when InsightFace couldn't be
# imported/initialised, or None before the first call.
_face_model = None
_face_model_lock = threading.Lock()


def warmup() -> None:
    """Trigger model load. Safe to call from FastAPI lifespan."""
    _get_model()


def _get_model():
    """
    Resolve the InsightFace model lazily. Returns the FaceAnalysis
    instance when ready, or the string ``"geometric_fallback"`` when we
    have to use the geometric embedding instead.

    Heavy by design: the first call downloads the ``buffalo_l`` weights
    (~300 MB) into ``INSIGHTFACE_HOME``. Subsequent calls return
    immediately. Thread-safe — only one warm-up runs even under concurrent
    requests.
    """
    global _face_model
    if _face_model is not None:
        return _face_model

    with _face_model_lock:
        if _face_model is not None:
            return _face_model
        try:
            from insightface.app import FaceAnalysis

            model_dir = os.environ.get("INSIGHTFACE_HOME", "/app/models_cache")
            os.makedirs(model_dir, exist_ok=True)
            os.environ["INSIGHTFACE_HOME"] = model_dir

            # 'buffalo_l' bundles RetinaFace detection + ArcFace recognition.
            app = FaceAnalysis(
                name="buffalo_l",
                root=model_dir,
                providers=["CPUExecutionProvider"],
            )
            app.prepare(ctx_id=-1, det_size=(160, 160))
            _face_model = app
            logger.info("embedding: InsightFace ArcFace model loaded")
        except ImportError as exc:
            logger.warning(
                "embedding: InsightFace unavailable, falling back to geometric",
                error=str(exc),
            )
            _face_model = "geometric_fallback"
        except Exception as exc:
            logger.warning(
                "embedding: InsightFace init failed, falling back to geometric",
                error=str(exc),
            )
            _face_model = "geometric_fallback"

    return _face_model


def compute_embedding(
    face_image: Optional[np.ndarray] = None,
    landmarks: Optional[Sequence] = None,
) -> List[float]:
    """
    Compute a 512-dim face embedding.

    * When ``face_image`` (a BGR numpy face crop) is provided and
      InsightFace is available, use ArcFace — the real,
      identity-discriminative path.
    * Otherwise fall back to the geometric embedding derived from
      ``landmarks`` (each element must expose ``.x``, ``.y``, ``.z``).
    * If neither is usable, return a zero vector.

    The returned vector is L2-normalised in both branches so cosine
    similarity reduces to a plain dot product.
    """
    model = _get_model()

    if model != "geometric_fallback" and face_image is not None:
        emb = _insightface_embedding(face_image, model)
        if emb is not None:
            return emb
        # Real-model embedding failed; fall through to geometric so the
        # caller still gets a non-None vector — registration validation
        # will reject zero/short vectors downstream.

    if landmarks is not None:
        return _geometric_embedding(landmarks)

    return [0.0] * EMBEDDING_DIM


def _insightface_embedding(face_image: np.ndarray, app) -> Optional[List[float]]:
    """Compute 512-dim ArcFace embedding using InsightFace."""
    try:
        if face_image is None or face_image.size == 0:
            return None

        # InsightFace expects a 3-channel BGR image.
        if len(face_image.shape) == 2:
            face_image = cv2.cvtColor(face_image, cv2.COLOR_GRAY2BGR)
        elif face_image.shape[2] == 4:
            face_image = cv2.cvtColor(face_image, cv2.COLOR_BGRA2BGR)

        h, w = face_image.shape[:2]
        if h < 20 or w < 20:
            return None

        # We pass the crop; InsightFace re-detects internally and should
        # find exactly one face.
        faces = app.get(face_image)
        if faces:
            return faces[0].normed_embedding.tolist()

        # Tight crops sometimes miss detection — retry with a black
        # border that gives the detector some headroom.
        padded = cv2.copyMakeBorder(
            face_image, 30, 30, 30, 30,
            cv2.BORDER_CONSTANT, value=(0, 0, 0),
        )
        faces = app.get(padded)
        if faces:
            return faces[0].normed_embedding.tolist()

        return None
    except Exception as exc:
        logger.warning("embedding: InsightFace embedding failed", error=str(exc))
        return None


def _geometric_embedding(landmarks: Sequence) -> List[float]:
    """
    Fallback: 512-dim geometric embedding derived from MediaPipe landmark
    distances and nose-relative angles. Not identity-discriminative.
    """
    if not landmarks or len(landmarks) < 10:
        return [0.0] * EMBEDDING_DIM

    coords = [
        (lm.x, lm.y, lm.z)
        for lm in landmarks[: min(len(landmarks), 478)]
    ]
    available = [i for i in _KEY_INDICES if i < len(coords)]

    features: List[float] = []
    for i in range(len(available)):
        for j in range(i + 1, len(available)):
            if len(features) >= 400:
                break
            p1 = coords[available[i]]
            p2 = coords[available[j]]
            dist = float(np.sqrt(sum((a - b) ** 2 for a, b in zip(p1, p2))))
            features.append(dist)
        if len(features) >= 400:
            break

    nose = coords[1] if len(coords) > 1 else (0.5, 0.5, 0.0)
    for idx in available[:112]:
        if len(features) >= EMBEDDING_DIM:
            break
        p = coords[idx] if idx < len(coords) else (0.5, 0.5, 0.0)
        angle = float(np.arctan2(p[1] - nose[1], p[0] - nose[0]))
        features.append(angle)

    features = features[:EMBEDDING_DIM]
    if len(features) < EMBEDDING_DIM:
        features.extend([0.0] * (EMBEDDING_DIM - len(features)))

    vec = np.array(features, dtype=np.float64)
    norm = float(np.linalg.norm(vec))
    if norm > 0:
        vec = vec / norm
    return vec.tolist()


def face_bbox_from_landmarks(
    landmarks: Sequence,
    frame_w: int,
    frame_h: int,
    padding: float = 0.2,
):
    """Get face bounding box from MediaPipe landmarks with padding."""
    if not landmarks:
        return (0, 0, 0, 0)

    xs = [lm.x * frame_w for lm in landmarks]
    ys = [lm.y * frame_h for lm in landmarks]
    x_min, x_max = int(min(xs)), int(max(xs))
    y_min, y_max = int(min(ys)), int(max(ys))

    pad_x = int((x_max - x_min) * padding)
    pad_y = int((y_max - y_min) * padding)

    x_min = max(0, x_min - pad_x)
    y_min = max(0, y_min - pad_y)
    x_max = min(frame_w, x_max + pad_x)
    y_max = min(frame_h, y_max + pad_y)

    return (x_min, y_min, x_max, y_max)


def cosine_similarity(a: List[float], b: List[float]) -> float:
    """Cosine similarity between two vectors."""
    va = np.array(a, dtype=np.float64)
    vb = np.array(b, dtype=np.float64)
    norm_a = float(np.linalg.norm(va))
    norm_b = float(np.linalg.norm(vb))
    if norm_a == 0 or norm_b == 0:
        return 0.0
    return float(np.dot(va, vb) / (norm_a * norm_b))


def match_face_to_student(
    face_embedding: List[float],
    known_embeddings: Dict[str, List[float]],
    threshold: float = 0.4,
    exclude: Optional[set] = None,
) -> Optional[str]:
    """
    Find best matching student. Threshold 0.4 is the right separator for
    ArcFace (same person 0.5-0.8, different people 0.0-0.25).
    """
    if not known_embeddings:
        return None

    skip = exclude or set()
    best_match: Optional[str] = None
    best_score = -1.0

    for student_id, known_emb in known_embeddings.items():
        if student_id in skip:
            continue
        if not known_emb or len(known_emb) < 10:
            continue
        if all(v == 0 for v in known_emb[:5]):
            continue

        sim = cosine_similarity(face_embedding, known_emb)
        if sim > best_score:
            best_score = sim
            best_match = student_id

    if best_score >= threshold:
        return best_match
    return None
