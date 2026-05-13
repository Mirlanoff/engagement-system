"""
Shared face-embedding utilities.

This module is the single source of truth for the face embedding used by
the engagement system. Both `/embeddings/generate` (one-shot registration
from a static photo) and `/capture/analyze-frame` (per-frame live
recognition) route through :func:`compute_embedding`, so the two code
paths always produce vectors in the same space.

Primary path — DeepFace Facenet (128-dim). The embedding is computed from
the raw face crop (BGR numpy array) using ``DeepFace.represent(model_name="Facenet",
detector_backend="skip")`` — we hand DeepFace an already-detected face so
it doesn't try to detect it again. Facenet returns a 128-dim vector that
is highly discriminative: same person ≳ 0.7 cosine similarity, different
people ≲ 0.4. Models are cached in ``DEEPFACE_HOME`` (set to
``/app/models_cache`` in the Dockerfile, which is mounted as a docker
volume so weights survive restarts).

Fallback path — geometric. Used only when DeepFace cannot be imported
(e.g. minimal CI containers without TensorFlow). Builds a deterministic
128-dim L2-normalised vector from MediaPipe FaceMesh landmark distances
and nose-relative angles. NOT identity-discriminative — kept only so the
endpoint stays callable when DeepFace isn't installed.
"""

import threading
from typing import Iterable, List, Optional, Sequence

import cv2
import numpy as np
import structlog

logger = structlog.get_logger()

EMBEDDING_DIM = 128
FACENET_INPUT_SIZE = 160  # Facenet expects 160x160 inputs.

# Representative subset of FaceMesh landmark indices used for the fallback
# geometric embedding. Symmetric across both halves of the face so the
# embedding stays stable if a handful of landmarks are missing.
_KEY_INDICES = [1, 33, 61, 133, 152, 159, 234, 263, 291, 362, 386, 454]

# Lazy-loaded DeepFace state. "facenet" = ready to call, "fallback" =
# DeepFace import failed and we should use the geometric path, None =
# untouched.
_facenet_state: Optional[str] = None
_facenet_lock = threading.Lock()


def _get_facenet_model() -> str:
    """
    Resolve the DeepFace Facenet model lazily. Returns ``"facenet"`` when
    the library is importable and a warm-up call succeeds, or
    ``"fallback"`` when we have to use the geometric embedding instead.

    Heavy by design: the first call downloads the Facenet weights
    (~90 MB) into ``DEEPFACE_HOME``. Subsequent calls return immediately.
    Thread-safe — only one warm-up runs even under concurrent requests.
    """
    global _facenet_state
    if _facenet_state is not None:
        return _facenet_state

    with _facenet_lock:
        if _facenet_state is not None:
            return _facenet_state
        try:
            from deepface import DeepFace  # noqa: F401  # import probe

            logger.info(
                "embedding: warming up DeepFace Facenet "
                "(first call may download ~90MB to DEEPFACE_HOME)"
            )
            dummy = np.zeros(
                (FACENET_INPUT_SIZE, FACENET_INPUT_SIZE, 3),
                dtype=np.uint8,
            )
            DeepFace.represent(
                img_path=dummy,
                model_name="Facenet",
                enforce_detection=False,
                detector_backend="skip",
            )
            _facenet_state = "facenet"
            logger.info("embedding: Facenet model ready")
        except Exception as exc:
            logger.warning(
                "embedding: DeepFace unavailable, falling back to geometric embedding",
                error=str(exc),
            )
            _facenet_state = "fallback"

    return _facenet_state


def warmup() -> None:
    """Trigger DeepFace warm-up. Safe to call from FastAPI lifespan."""
    _get_facenet_model()


def compute_embedding(
    face_image: Optional[np.ndarray] = None,
    landmarks: Optional[Sequence] = None,
) -> List[float]:
    """
    Compute a 128-dim face embedding.

    * When ``face_image`` (a BGR numpy face crop) is provided and DeepFace
      is installed, use Facenet — the real, identity-discriminative path.
    * Otherwise fall back to the geometric embedding derived from
      ``landmarks`` (each element must expose ``.x``, ``.y``, ``.z``).
    * If neither is usable, return a zero vector.

    The returned vector is L2-normalised in both branches so cosine
    similarity reduces to a plain dot product.
    """
    model = _get_facenet_model()

    if model == "facenet" and face_image is not None and face_image.size > 0:
        emb = _deepface_embedding(face_image)
        if emb and any(abs(v) > 1e-12 for v in emb):
            return emb
        # Real-model embedding failed; fall through to geometric so the
        # caller still gets a usable vector for non-recognition paths.

    if landmarks is not None:
        return _geometric_embedding(landmarks)

    return [0.0] * EMBEDDING_DIM


def _deepface_embedding(face_image: np.ndarray) -> List[float]:
    """Compute the 128-dim Facenet embedding from a BGR face crop."""
    try:
        from deepface import DeepFace
    except Exception as exc:  # pragma: no cover - guarded by _get_facenet_model
        logger.warning("embedding: deepface import failed at call site", error=str(exc))
        return []

    if face_image is None or face_image.size == 0:
        return []
    if face_image.shape[0] < 10 or face_image.shape[1] < 10:
        return []

    try:
        resized = cv2.resize(face_image, (FACENET_INPUT_SIZE, FACENET_INPUT_SIZE))
        result = DeepFace.represent(
            img_path=resized,
            model_name="Facenet",
            enforce_detection=False,
            detector_backend="skip",
        )
    except Exception as exc:
        logger.warning("embedding: DeepFace.represent failed", error=str(exc))
        return []

    if not result:
        return []

    raw = result[0].get("embedding") if isinstance(result, list) else None
    if not raw or len(raw) != EMBEDDING_DIM:
        return []

    vec = np.asarray(raw, dtype=np.float64)
    norm = float(np.linalg.norm(vec))
    if norm > 0:
        vec = vec / norm
    return [float(x) for x in vec]


def _geometric_embedding(
    landmarks: Sequence,
    indices_count: int = 478,
) -> List[float]:
    """
    Deterministic 128-dim geometric embedding from MediaPipe FaceMesh
    landmarks. Inter-landmark distances on a fixed key subset, plus
    nose-relative angles, then L2-normalised. Not identity-discriminative
    — used only when DeepFace is unavailable.
    """
    if not landmarks or len(landmarks) < 10:
        return [0.0] * EMBEDDING_DIM

    coords = [
        (lm.x, lm.y, lm.z)
        for lm in landmarks[: min(len(landmarks), indices_count)]
    ]
    available = [i for i in _KEY_INDICES if i < len(coords)]

    features: List[float] = []
    for i in range(len(available)):
        if len(features) >= 100:
            break
        for j in range(i + 1, len(available)):
            if len(features) >= 100:
                break
            p1 = coords[available[i]]
            p2 = coords[available[j]]
            dist = float(np.sqrt(sum((a - b) ** 2 for a, b in zip(p1, p2))))
            features.append(dist)

    nose = coords[1] if len(coords) > 1 else (0.5, 0.5, 0.0)
    for idx in available[:28]:
        if len(features) >= EMBEDDING_DIM:
            break
        p = coords[idx]
        angle = float(np.arctan2(p[1] - nose[1], p[0] - nose[0]))
        features.append(angle)

    features = features[:EMBEDDING_DIM]
    if len(features) < EMBEDDING_DIM:
        features.extend([0.0] * (EMBEDDING_DIM - len(features)))

    vec = np.asarray(features, dtype=np.float64)
    norm = float(np.linalg.norm(vec))
    if norm > 0:
        vec = vec / norm
    return [float(x) for x in vec]


# ── Matching helpers ───────────────────────────────────────────────────────


def cosine_similarity(a: Sequence[float], b: Sequence[float]) -> float:
    """Cosine similarity between two equal-length float vectors."""
    va = np.asarray(a, dtype=np.float64)
    vb = np.asarray(b, dtype=np.float64)
    if va.size == 0 or vb.size == 0 or va.size != vb.size:
        return -1.0
    na = float(np.linalg.norm(va))
    nb = float(np.linalg.norm(vb))
    if na == 0.0 or nb == 0.0:
        return -1.0
    return float(np.dot(va, vb) / (na * nb))


def match_face_to_student(
    face_embedding: Sequence[float],
    known_embeddings: dict,
    threshold: float = 0.6,
    exclude: Optional[Iterable[str]] = None,
) -> Optional[str]:
    """
    Return the student_id whose registered embedding is most cosine-similar
    to ``face_embedding`` above ``threshold``, or None if no candidate
    clears it. ``exclude`` lets the caller skip already-matched students so
    a single student can't be claimed by two faces in the same frame.
    """
    if not known_embeddings or not face_embedding:
        return None

    skip = set(exclude or ())
    best_id: Optional[str] = None
    best_score = -1.0

    for student_id, known_emb in known_embeddings.items():
        if student_id in skip or not known_emb:
            continue
        # Treat zero/near-zero vectors as "not registered".
        if all(abs(v) < 1e-12 for v in known_emb[:5]):
            continue
        score = cosine_similarity(face_embedding, known_emb)
        if score > best_score:
            best_score = score
            best_id = student_id

    if best_id is not None and best_score >= threshold:
        return best_id
    return None


def face_bbox_from_landmarks(
    landmarks: Sequence,
    frame_w: int,
    frame_h: int,
    padding: float = 0.2,
) -> tuple:
    """
    Compute a (x_min, y_min, x_max, y_max) pixel bbox from normalised
    landmarks, clipped to frame bounds and expanded by ``padding`` on each
    side. Returned as a tuple of ints so it can be used directly for numpy
    slicing.
    """
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


# Backwards-compat re-export for any caller that imported the old name.
compute_geometric_embedding = _geometric_embedding
