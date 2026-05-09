"""Подсчёт engagement score из компонентных метрик одного лица.

Изменения относительно старой версии:
- Добавлены поля posture_score, posture_state, hand_raised, attention_state,
  not_detected_reason, frame_quality, score_breakdown, confidence_overall.
- Веса перенормируются автоматически — если эмоция недоступна, её вес
  раскладывается на остальные компоненты, score не "проседает" из-за neutral=50.
- Добавлен короткий человекочитаемый reason на каждый компонент.

Сглаживание (скользящее среднее за 30 сек) — в `EngagementSmoother`.
"""

from __future__ import annotations

from collections import deque
from dataclasses import dataclass, field
from datetime import datetime
from typing import Deque, Dict, Optional

from app.config import settings


@dataclass
class FaceAnalysis:
    """Результат анализа одного лица для одного студента."""

    student_id: str
    camera_id: str
    captured_at: str

    # ── Детекция ─────────────────────────────────────────────────
    face_detected: bool = False
    face_confidence: float = 0.0
    face_bbox_x: Optional[int] = None
    face_bbox_y: Optional[int] = None
    face_bbox_w: Optional[int] = None
    face_bbox_h: Optional[int] = None

    # ── Взгляд / поза головы ─────────────────────────────────────
    gaze_yaw: Optional[float] = None
    gaze_pitch: Optional[float] = None

    head_yaw: Optional[float] = None
    head_pitch: Optional[float] = None
    head_roll: Optional[float] = None

    # ── Эмоция ───────────────────────────────────────────────────
    emotion: Optional[str] = None
    emotion_confidence: Optional[float] = None

    # ── Поза тела ────────────────────────────────────────────────
    posture_state: Optional[str] = None  # "upright" | "leaning_forward" |
    #                                       "leaning_back" | "slouched" | "absent"
    hand_raised: bool = False

    # ── Компонентные scores (0–100) ──────────────────────────────
    gaze_score: Optional[float] = None
    emotion_score: Optional[float] = None
    head_pose_score: Optional[float] = None
    presence_score: Optional[float] = None
    posture_score: Optional[float] = None
    engagement_score: float = 0.0

    # ── Производные метрики ──────────────────────────────────────
    attention_state: Optional[str] = None  # focused|distracted|drowsy|absent
    confidence_overall: Optional[float] = None  # 0..1

    # ── Диагностика ──────────────────────────────────────────────
    not_detected_reason: Optional[str] = None
    frame_quality: Optional[Dict[str, float]] = None
    score_breakdown: Optional[Dict[str, Dict[str, object]]] = field(default=None)

    processing_time_ms: Optional[float] = None


class EngagementScorer:
    """Подсчёт engagement_score (0–100) с авто-перенормировкой весов."""

    EMOTION_SCORES = {
        "happy":     100.0,
        "neutral":    70.0,
        "surprise":   65.0,  # DeepFace использует "surprise", не "surprised"
        "surprised":  65.0,
        "fear":       35.0,
        "fearful":    35.0,
        "sad":        25.0,
        "angry":      15.0,
        "disgust":    10.0,
        "disgusted":  10.0,
    }

    POSTURE_SCORES = {
        "upright":          100.0,
        "leaning_forward":   85.0,  # обычно — внимателен, но может быть усталость
        "leaning_back":      55.0,
        "slouched":          25.0,
        "absent":             0.0,
    }

    POSTURE_REASON = {
        "upright":          "сидит прямо",
        "leaning_forward":  "наклон корпуса вперёд",
        "leaning_back":     "откинулся назад",
        "slouched":         "сильно ссутулился",
        "absent":           "вне кадра",
    }

    EMOTION_REASON_RU = {
        "happy":      "включён, позитивная реакция",
        "neutral":    "спокойное сосредоточенное выражение",
        "surprise":   "удивление — реакция на материал",
        "surprised":  "удивление — реакция на материал",
        "fear":       "признаки тревоги",
        "fearful":    "признаки тревоги",
        "sad":        "признаки уныния",
        "angry":      "признаки раздражения",
        "disgust":    "негативная реакция",
        "disgusted":  "негативная реакция",
    }

    def compute(self, analysis: FaceAnalysis) -> FaceAnalysis:
        """Считает engagement_score и заполняет score_breakdown."""
        if not analysis.face_detected:
            analysis.presence_score = 0.0
            analysis.gaze_score = 0.0
            analysis.emotion_score = 0.0
            analysis.head_pose_score = 0.0
            analysis.posture_score = 0.0
            analysis.engagement_score = 0.0
            analysis.attention_state = "absent"
            analysis.confidence_overall = 0.0
            analysis.score_breakdown = self._empty_breakdown(analysis.not_detected_reason)
            return analysis

        analysis.presence_score = self._presence_score(analysis)
        analysis.gaze_score, gaze_reason = self._gaze_score(analysis)
        analysis.head_pose_score, head_reason = self._head_pose_score(analysis)
        analysis.emotion_score, emotion_reason = self._emotion_score(analysis)
        analysis.posture_score, posture_reason = self._posture_score(analysis)

        # Перенормировка весов: если каких-то компонент нет, их веса
        # раскладываются пропорционально на доступные.
        weights = {
            "presence":  settings.weight_presence,
            "gaze":      settings.weight_gaze,
            "emotion":   settings.weight_emotion if analysis.emotion is not None else 0.0,
            "head_pose": settings.weight_head_pose,
            "posture":   settings.weight_posture if analysis.posture_state is not None else 0.0,
        }
        total = sum(weights.values()) or 1.0
        weights = {k: v / total for k, v in weights.items()}

        analysis.engagement_score = round(
            weights["presence"]  * (analysis.presence_score or 0)  +
            weights["gaze"]      * (analysis.gaze_score or 0)      +
            weights["emotion"]   * (analysis.emotion_score or 0)   +
            weights["head_pose"] * (analysis.head_pose_score or 0) +
            weights["posture"]   * (analysis.posture_score or 0),
            2,
        )

        analysis.score_breakdown = {
            "presence": {
                "value":  round(analysis.presence_score or 0, 1),
                "weight": round(weights["presence"], 2),
                "reason": self._presence_reason(analysis),
            },
            "gaze": {
                "value":  round(analysis.gaze_score or 0, 1),
                "weight": round(weights["gaze"], 2),
                "reason": gaze_reason,
            },
            "head_pose": {
                "value":  round(analysis.head_pose_score or 0, 1),
                "weight": round(weights["head_pose"], 2),
                "reason": head_reason,
            },
        }
        if analysis.emotion is not None:
            analysis.score_breakdown["emotion"] = {
                "value":  round(analysis.emotion_score or 0, 1),
                "weight": round(weights["emotion"], 2),
                "reason": emotion_reason,
            }
        if analysis.posture_state is not None:
            analysis.score_breakdown["posture"] = {
                "value":  round(analysis.posture_score or 0, 1),
                "weight": round(weights["posture"], 2),
                "reason": posture_reason,
            }

        analysis.attention_state = self._attention_state(analysis)
        analysis.confidence_overall = self._overall_confidence(analysis)
        return analysis

    # ── Компоненты ───────────────────────────────────────────────

    def _presence_score(self, a: FaceAnalysis) -> float:
        conf = a.face_confidence or 0.0
        return round(min(conf * 100, 100.0), 2)

    def _presence_reason(self, a: FaceAnalysis) -> str:
        conf = a.face_confidence or 0.0
        if conf >= 0.8:
            return "лицо чёткое, в кадре"
        if conf >= 0.5:
            return "лицо в кадре, но детекция не уверена"
        return "лицо едва различимо"

    def _gaze_score(self, a: FaceAnalysis) -> tuple[float, str]:
        if a.gaze_yaw is None or a.gaze_pitch is None:
            return 50.0, "взгляд оценить не удалось"
        yaw_norm = max(0.0, 1.0 - abs(a.gaze_yaw) / 25.0)
        pitch_norm = max(0.0, 1.0 - abs(a.gaze_pitch) / 20.0)
        score = round(((yaw_norm + pitch_norm) / 2.0) * 100.0, 2)
        if score >= 80:
            reason = "взгляд направлен на доску"
        elif score >= 50:
            reason = f"взгляд слегка в сторону (~{int(abs(a.gaze_yaw))}° по горизонтали)"
        elif a.gaze_pitch < -10:
            reason = "взгляд вниз — возможно смотрит в тетрадь или телефон"
        else:
            reason = "взгляд далеко от доски"
        return score, reason

    def _head_pose_score(self, a: FaceAnalysis) -> tuple[float, str]:
        if a.head_yaw is None or a.head_pitch is None:
            return 50.0, "позу головы оценить не удалось"
        yaw_norm = max(0.0, 1.0 - abs(a.head_yaw) / 30.0)
        pitch_norm = max(0.0, 1.0 - abs(a.head_pitch) / 25.0)
        score = round(((yaw_norm + pitch_norm) / 2.0) * 100.0, 2)
        if score >= 80:
            reason = "голова направлена прямо"
        elif abs(a.head_yaw) > 25:
            reason = f"голова повёрнута в сторону (~{int(abs(a.head_yaw))}°)"
        elif a.head_pitch and a.head_pitch > 20:
            reason = "голова опущена вниз"
        else:
            reason = "лёгкий поворот головы"
        return score, reason

    def _emotion_score(self, a: FaceAnalysis) -> tuple[float, str]:
        if not a.emotion:
            return 50.0, "эмоцию определить не удалось"
        base = self.EMOTION_SCORES.get(a.emotion, 50.0)
        conf = a.emotion_confidence or 0.5
        score = round(base * conf + 50.0 * (1.0 - conf), 2)
        reason = self.EMOTION_REASON_RU.get(a.emotion, f"эмоция: {a.emotion}")
        return score, reason

    def _posture_score(self, a: FaceAnalysis) -> tuple[float, str]:
        if not a.posture_state:
            return 50.0, "позу оценить не удалось"
        score = self.POSTURE_SCORES.get(a.posture_state, 50.0)
        reason = self.POSTURE_REASON.get(a.posture_state, a.posture_state)
        if a.hand_raised:
            score = min(100.0, score + 10.0)
            reason = "поднял руку — активное участие"
        return score, reason

    # ── Производные ──────────────────────────────────────────────

    def _attention_state(self, a: FaceAnalysis) -> str:
        if not a.face_detected:
            return "absent"
        # drowsy: голова сильно вниз
        if a.head_pitch is not None and a.head_pitch > 25:
            return "drowsy"
        # distracted: взгляд далеко или поза леньгая
        gaze_far = (
            (a.gaze_yaw is not None and abs(a.gaze_yaw) > 30)
            or (a.head_yaw is not None and abs(a.head_yaw) > 35)
        )
        bad_posture = a.posture_state in ("leaning_back", "slouched")
        if gaze_far or bad_posture:
            return "distracted"
        return "focused"

    def _overall_confidence(self, a: FaceAnalysis) -> float:
        parts = []
        if a.face_confidence is not None:
            parts.append(a.face_confidence)
        if a.emotion_confidence is not None:
            parts.append(a.emotion_confidence)
        return round(sum(parts) / len(parts), 3) if parts else 0.5

    def _empty_breakdown(self, reason: Optional[str]) -> Dict[str, Dict[str, object]]:
        message = {
            "too_dark":           "кадр слишком тёмный — включите свет",
            "too_blurry":         "кадр размыт — стабилизируйте камеру",
            "no_faces_in_fov":    "лиц не видно в кадре",
            "face_too_small":     "лицо слишком далеко или мелкое",
            "model_unavailable":  "модель распознавания недоступна",
        }.get(reason or "", "лицо не обнаружено")
        return {
            "presence": {"value": 0, "weight": 1.0, "reason": message},
        }


class EngagementSmoother:
    """Скользящее среднее engagement_score за `window_seconds` секунд.

    По одному инстансу на (session_id, student_id). Хранит (timestamp, score).
    Возвращает сглаженный score, выкидывая записи старше окна.
    """

    def __init__(self, window_seconds: int = 30) -> None:
        self.window = window_seconds
        self._buffers: Dict[tuple[str, str], Deque[tuple[float, float]]] = {}

    def smooth(self, session_id: str, student_id: str, score: float, ts: Optional[datetime] = None) -> float:
        ts = ts or datetime.utcnow()
        epoch = ts.timestamp()
        key = (session_id, student_id)
        buf = self._buffers.setdefault(key, deque())
        buf.append((epoch, score))
        # выбрасываем старое
        cutoff = epoch - self.window
        while buf and buf[0][0] < cutoff:
            buf.popleft()
        avg = sum(s for _, s in buf) / len(buf)
        return round(avg, 2)

    def clear(self, session_id: str) -> None:
        for k in list(self._buffers.keys()):
            if k[0] == session_id:
                self._buffers.pop(k, None)
