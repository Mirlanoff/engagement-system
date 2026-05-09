"""Оценка позы головы через `cv2.solvePnP` + относительный gaze.

Заменяет старый способ "arctan2 от смешанных пиксельных и нормированных координат".
"""

from __future__ import annotations

import math
from typing import Optional, Tuple

import cv2
import numpy as np


# Приблизительная 3D-модель лица (мм) — стандартные точки.
_MODEL_3D = np.array([
    [   0.0,    0.0,    0.0],   # 0: кончик носа
    [   0.0,  -63.6,  -12.5],   # 1: подбородок
    [-43.3,   32.7,  -26.0],    # 2: левый внешний угол глаза
    [ 43.3,   32.7,  -26.0],    # 3: правый внешний угол глаза
    [-28.9,  -28.9,  -24.1],    # 4: левый угол рта
    [ 28.9,  -28.9,  -24.1],    # 5: правый угол рта
], dtype=np.float64)


# Индексы соответствующих landmarks в FaceMesh (468 точек).
# Подбор стандартный, проверен на mediapipe FaceMesh.
_LANDMARK_IDX = (
    1,    # nose tip
    152,  # chin
    263,  # left eye outer (зеркальный — у MediaPipe лево/право относительно картинки)
    33,   # right eye outer
    287,  # left mouth corner
    57,   # right mouth corner
)


def estimate_head_pose(landmarks, frame_w: int, frame_h: int
                       ) -> Tuple[Optional[float], Optional[float], Optional[float]]:
    """Возвращает (yaw_deg, pitch_deg, roll_deg) или (None, None, None) если не удалось."""
    pts_2d = []
    for idx in _LANDMARK_IDX:
        if idx >= len(landmarks):
            return None, None, None
        lm = landmarks[idx]
        pts_2d.append([lm.x * frame_w, lm.y * frame_h])
    pts_2d = np.array(pts_2d, dtype=np.float64)

    focal = float(frame_w)
    center = (frame_w / 2.0, frame_h / 2.0)
    camera_matrix = np.array([
        [focal,     0, center[0]],
        [    0, focal, center[1]],
        [    0,     0,         1],
    ], dtype=np.float64)
    dist = np.zeros((4, 1))

    success, rvec, _tvec = cv2.solvePnP(
        _MODEL_3D, pts_2d, camera_matrix, dist, flags=cv2.SOLVEPNP_ITERATIVE
    )
    if not success:
        return None, None, None

    rmat, _ = cv2.Rodrigues(rvec)
    sy = math.sqrt(rmat[0, 0] ** 2 + rmat[1, 0] ** 2)
    if sy >= 1e-6:
        pitch = math.atan2(-rmat[2, 0], sy)
        yaw   = math.atan2(rmat[1, 0], rmat[0, 0])
        roll  = math.atan2(rmat[2, 1], rmat[2, 2])
    else:
        pitch = math.atan2(-rmat[2, 0], sy)
        yaw   = 0.0
        roll  = math.atan2(-rmat[1, 2], rmat[1, 1])

    # Переводим в "интуитивные" градусы относительно фронтальной позы:
    # yaw < 0 — голова повёрнута влево от камеры (для смотрящего на камеру).
    return (
        round(math.degrees(yaw), 2),
        round(math.degrees(pitch), 2),
        round(math.degrees(roll), 2),
    )


def estimate_gaze(landmarks, head_yaw: Optional[float], head_pitch: Optional[float]
                  ) -> Tuple[Optional[float], Optional[float]]:
    """Оценка направления взгляда.

    Использует положение центра радужки (idx 468–472 / 473–477)
    относительно углов глаза (а не относительно центра кадра).
    Складываем с углом поворота головы — получаем "куда реально смотрит".

    Возвращает (gaze_yaw, gaze_pitch) в градусах.
    """
    if len(landmarks) < 478:
        # refine_landmarks не включён — fallback на head pose.
        return head_yaw, head_pitch

    # Радужки
    left_iris = _avg_xy(landmarks, range(468, 473))
    right_iris = _avg_xy(landmarks, range(473, 478))

    # Углы глаз: внутренний / внешний — индексы для левого и правого
    # глаза соответственно (стандарт mediapipe).
    left_outer = _xy(landmarks[33])
    left_inner = _xy(landmarks[133])
    right_inner = _xy(landmarks[362])
    right_outer = _xy(landmarks[263])

    # Положение радужки между внутренним и внешним углом, нормировано на ширину глаза.
    # 0 — у внутреннего угла, 1 — у внешнего; 0.5 — взгляд прямо.
    def relative_x(iris, inner, outer):
        denom = (outer[0] - inner[0])
        if abs(denom) < 1e-6:
            return 0.5
        return (iris[0] - inner[0]) / denom

    rx_left = relative_x(left_iris, left_inner, left_outer)
    rx_right = relative_x(right_iris, right_inner, right_outer)
    rel_x = (rx_left + rx_right) / 2.0

    # Аналогично для вертикали — между верхним и нижним веком.
    left_top = _xy(landmarks[159])
    left_bot = _xy(landmarks[145])
    right_top = _xy(landmarks[386])
    right_bot = _xy(landmarks[374])

    def relative_y(iris, top, bot):
        denom = (bot[1] - top[1])
        if abs(denom) < 1e-6:
            return 0.5
        return (iris[1] - top[1]) / denom

    ry_left = relative_y(left_iris, left_top, left_bot)
    ry_right = relative_y(right_iris, right_top, right_bot)
    rel_y = (ry_left + ry_right) / 2.0

    # rel_x ∈ [0..1]: 0.5 — центр; смещение ±0.3 ≈ ±20° взгляда относительно лица.
    eye_yaw = (rel_x - 0.5) * 60.0   # max ~30° eye rotation
    eye_pitch = (rel_y - 0.5) * 40.0

    # Полный взгляд = поза головы + eye-rotation
    yaw = (head_yaw or 0.0) + eye_yaw
    pitch = (head_pitch or 0.0) + eye_pitch
    return round(yaw, 2), round(pitch, 2)


def _xy(lm) -> Tuple[float, float]:
    return float(lm.x), float(lm.y)


def _avg_xy(landmarks, indices) -> Tuple[float, float]:
    xs, ys = [], []
    for i in indices:
        if i >= len(landmarks):
            continue
        xs.append(landmarks[i].x)
        ys.append(landmarks[i].y)
    if not xs:
        return 0.5, 0.5
    return sum(xs) / len(xs), sum(ys) / len(ys)
