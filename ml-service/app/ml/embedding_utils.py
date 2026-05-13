"""
Shared face-embedding utilities.

Both `/embeddings/generate` (one-shot registration from a static photo) and
`/capture/analyze-frame` (per-frame live recognition) must produce embeddings
in the same vector space — otherwise cosine similarity has no meaning. To
guarantee that, both code paths route through `compute_geometric_embedding`
defined here.

The embedding is a deterministic, 128-dim vector built from MediaPipe
FaceMesh landmarks: pairwise inter-landmark distances on a handful of
canonical points (eyes, nose, mouth, ears, chin) plus angles from the nose
to those points. It is then L2-normalised so that cosine similarity ≡ dot
product. It is not as discriminative as Facenet/ArcFace, but it is stable
across photos of the same face under similar pose / lighting and good
enough to validate the recognition pipeline end-to-end without pulling in
DeepFace/TensorFlow.
"""

from typing import Iterable, List, Optional, Sequence

import numpy as np

EMBEDDING_DIM = 128

# A representative subset of FaceMesh landmark indices. These cover both
# halves of the face symmetrically, which keeps the embedding distinctive
# even if a few points are missing.
_KEY_INDICES = [1, 33, 61, 133, 152, 159, 234, 263, 291, 362, 386, 454]


def compute_geometric_embedding(
    landmarks: Sequence,
    indices_count: int = 478,
) -> List[float]:
    """
    Compute a deterministic 128-dim geometric embedding from a sequence of
    landmarks (objects with ``.x``, ``.y``, ``.z`` attributes — typically
    ``face_landmarks.landmark`` from MediaPipe FaceMesh).

    Returns a zero vector of length 128 when input is too sparse to be useful.
    """
    if not landmarks or len(landmarks) < 10:
        return [0.0] * EMBEDDING_DIM

    coords = [
        (lm.x, lm.y, lm.z)
        for lm in landmarks[: min(len(landmarks), indices_count)]
    ]
    available = [i for i in _KEY_INDICES if i < len(coords)]

    features: List[float] = []

    # Pairwise distances between key points (3-D).
    for i in range(len(available)):
        if len(features) >= 100:
            break
        for j in range(i + 1, len(available)):
            if len(features) >= 100:
                break
            p1 = coords[available[i]]
            p2 = coords[available[j]]
            dist = float(
                np.sqrt(sum((a - b) ** 2 for a, b in zip(p1, p2)))
            )
            features.append(dist)

    # Angles from the nose tip (landmark 1) to each key point in the XY plane.
    nose = coords[1] if len(coords) > 1 else (0.5, 0.5, 0.0)
    for idx in available[:28]:
        if len(features) >= EMBEDDING_DIM:
            break
        p = coords[idx]
        angle = float(np.arctan2(p[1] - nose[1], p[0] - nose[0]))
        features.append(angle)

    # Pad or truncate to exactly EMBEDDING_DIM.
    features = features[:EMBEDDING_DIM]
    if len(features) < EMBEDDING_DIM:
        features.extend([0.0] * (EMBEDDING_DIM - len(features)))

    vec = np.asarray(features, dtype=np.float64)
    norm = float(np.linalg.norm(vec))
    if norm > 0:
        vec = vec / norm

    return [float(x) for x in vec]


def cosine_similarity(a: Sequence[float], b: Sequence[float]) -> float:
    """Cosine similarity between two equally-sized float vectors."""
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
    threshold: float = 0.5,
    exclude: Optional[Iterable[str]] = None,
) -> Optional[str]:
    """
    Return the student_id whose registered embedding is most cosine-similar
    to ``face_embedding`` above ``threshold``, or None if no candidate clears
    the threshold. ``exclude`` lets the caller skip already-matched students
    so two faces in the same frame can't be claimed by the same student.
    """
    if not known_embeddings or not face_embedding:
        return None

    skip = set(exclude or ())
    best_id: Optional[str] = None
    best_score = -1.0

    for student_id, known_emb in known_embeddings.items():
        if student_id in skip or not known_emb:
            continue
        score = cosine_similarity(face_embedding, known_emb)
        if score > best_score:
            best_score = score
            best_id = student_id

    if best_id is not None and best_score >= threshold:
        return best_id
    return None
