"""Оценка позы тела через MediaPipe Pose.

Берёт кроп под лицом (плечи + торс) и определяет:
- наклон корпуса (leaning_forward / leaning_back / upright / slouched)
- поднята ли рука

Если Pose-модель недоступна или landmarks слишком низкого качества,
возвращает None — тогда posture исключается из engagement_score
(см. EngagementScorer.compute).
"""

from __future__ import annotations

from typing import Optional, Tuple

import cv2
import numpy as np

# Индексы landmarks MediaPipe Pose
_LEFT_SHOULDER = 11
_RIGHT_SHOULDER = 12
_LEFT_ELBOW = 13
_RIGHT_ELBOW = 14
_LEFT_WRIST = 15
_RIGHT_WRIST = 16
_NOSE = 0


def estimate_posture(pose_model, frame_bgr: np.ndarray, face_bbox: Tuple[int, int, int, int]
                     ) -> Tuple[Optional[str], bool]:
    """Возвращает (posture_state, hand_raised) или (None, False) если не удалось.

    `face_bbox` — (x, y, w, h) лица в исходном кадре, нужен чтобы взять
    правильную область торса под лицом.
    """
    if pose_model is None:
        return None, False

    fh, fw = frame_bgr.shape[:2]
    x, y, w, h = face_bbox
    # Берём вертикальную полосу: чуть выше лица — чтобы захватить голову,
    # и до 3*высоты лица вниз — корпус. По горизонтали — расширяем до 2.2*ширины.
    cx = x + w // 2
    pad_w = int(w * 1.1)
    pad_top = int(h * 0.4)
    pad_bot = int(h * 3.0)
    x1 = max(0, cx - pad_w)
    x2 = min(fw, cx + pad_w)
    y1 = max(0, y - pad_top)
    y2 = min(fh, y + pad_bot)
    crop = frame_bgr[y1:y2, x1:x2]
    if crop.size == 0:
        return None, False

    rgb = cv2.cvtColor(crop, cv2.COLOR_BGR2RGB)
    try:
        result = pose_model.process(rgb)
    except Exception:
        return None, False

    if not result.pose_landmarks:
        return None, False

    lm = result.pose_landmarks.landmark
    # Нужны хотя бы плечи
    if lm[_LEFT_SHOULDER].visibility < 0.4 or lm[_RIGHT_SHOULDER].visibility < 0.4:
        return None, False

    ch, cw = crop.shape[:2]
    ls = (lm[_LEFT_SHOULDER].x * cw, lm[_LEFT_SHOULDER].y * ch)
    rs = (lm[_RIGHT_SHOULDER].x * cw, lm[_RIGHT_SHOULDER].y * ch)
    nose = (lm[_NOSE].x * cw, lm[_NOSE].y * ch)

    shoulder_y = (ls[1] + rs[1]) / 2.0
    shoulder_width = abs(ls[0] - rs[0]) or 1.0
    nose_to_shoulders = shoulder_y - nose[1]   # > 0 если нос выше плеч

    # Высота крупного "от носа до плеч" в долях ширины плеч.
    head_height_ratio = nose_to_shoulders / shoulder_width

    # Положение носа относительно середины плеч по горизонтали — для leaning_forward
    mid_shoulder_x = (ls[0] + rs[0]) / 2.0
    nose_offset_x = (nose[0] - mid_shoulder_x) / shoulder_width

    posture: str
    if head_height_ratio < 0.2:
        # голова "осела" между плеч — слаучился или лёг
        posture = "slouched"
    elif head_height_ratio > 0.9 and abs(nose_offset_x) < 0.3:
        posture = "upright"
    elif nose_offset_x and abs(nose_offset_x) > 0.5:
        # сильно наклонился вбок — частный случай отвлечения
        posture = "leaning_back"
    else:
        # Эвристика по соотношению (нос относительно плеч + плечи относительно торса):
        # если "плечи широкие, нос далеко вверху" — upright;
        # если "плечи кажутся выше носа" — leaning_forward (нос ушёл вниз).
        if head_height_ratio < 0.55:
            posture = "leaning_forward"
        else:
            posture = "leaning_back"

    # Поднятая рука: запястье выше плеча
    hand_raised = False
    for wrist_idx, shoulder_idx in (
        (_LEFT_WRIST, _LEFT_SHOULDER),
        (_RIGHT_WRIST, _RIGHT_SHOULDER),
    ):
        wrist = lm[wrist_idx]
        sh = lm[shoulder_idx]
        if wrist.visibility >= 0.4 and sh.visibility >= 0.4:
            if wrist.y < sh.y - 0.05:
                hand_raised = True
                break

    return posture, hand_raised
