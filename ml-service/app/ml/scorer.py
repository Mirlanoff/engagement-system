from dataclasses import dataclass
from typing import Optional
from app.config import settings


@dataclass
class FaceAnalysis:
    """Результат анализа одного лица."""
    student_id: str
    camera_id: str
    captured_at: str

    # Детекция
    face_detected: bool = False
    face_confidence: float = 0.0
    face_bbox_x: Optional[int] = None
    face_bbox_y: Optional[int] = None
    face_bbox_w: Optional[int] = None
    face_bbox_h: Optional[int] = None

    # Взгляд
    gaze_yaw: Optional[float] = None
    gaze_pitch: Optional[float] = None

    # Поза головы
    head_yaw: Optional[float] = None
    head_pitch: Optional[float] = None
    head_roll: Optional[float] = None

    # Эмоция
    emotion: Optional[str] = None
    emotion_confidence: Optional[float] = None

    # Компонентные scores (0–100)
    gaze_score: Optional[float] = None
    emotion_score: Optional[float] = None
    head_pose_score: Optional[float] = None
    presence_score: Optional[float] = None
    engagement_score: float = 0.0

    processing_time_ms: Optional[float] = None


class EngagementScorer:
    """
    Подсчитывает итоговый engagement score (0–100) из компонентов.

    Формула:
        score = w_gaze×gaze + w_emotion×emotion + w_pose×pose + w_presence×presence
    """

    # Позитивные эмоции → высокий балл
    EMOTION_SCORES = {
        "happy":     100.0,
        "neutral":    70.0,
        "surprised":  60.0,
        "fearful":    35.0,
        "sad":        25.0,
        "angry":      15.0,
        "disgusted":  10.0,
    }

    def compute(self, analysis: FaceAnalysis) -> FaceAnalysis:
        if not analysis.face_detected:
            analysis.presence_score    = 0.0
            analysis.gaze_score        = 0.0
            analysis.emotion_score     = 0.0
            analysis.head_pose_score   = 0.0
            analysis.engagement_score  = 0.0
            return analysis

        analysis.presence_score  = self._presence_score(analysis)
        analysis.gaze_score      = self._gaze_score(analysis)
        analysis.emotion_score   = self._emotion_score(analysis)
        analysis.head_pose_score = self._head_pose_score(analysis)

        analysis.engagement_score = round(
            settings.weight_presence  * analysis.presence_score  +
            settings.weight_gaze      * analysis.gaze_score      +
            settings.weight_emotion   * analysis.emotion_score   +
            settings.weight_head_pose * analysis.head_pose_score,
            2,
        )
        return analysis

    def _presence_score(self, a: FaceAnalysis) -> float:
        """Лицо видно и уверенность детекции высокая."""
        if not a.face_detected:
            return 0.0
        conf = a.face_confidence or 0.5
        return round(min(conf * 100, 100.0), 2)

    def _gaze_score(self, a: FaceAnalysis) -> float:
        """
        Студент смотрит на доску — взгляд близок к центру (yaw≈0, pitch≈0).
        Допуск: yaw ±25°, pitch ±20°.
        """
        if a.gaze_yaw is None or a.gaze_pitch is None:
            return 50.0  # нет данных → нейтральный балл

        yaw_norm   = max(0.0, 1.0 - abs(a.gaze_yaw)   / 25.0)
        pitch_norm = max(0.0, 1.0 - abs(a.gaze_pitch)  / 20.0)
        return round(((yaw_norm + pitch_norm) / 2.0) * 100.0, 2)

    def _emotion_score(self, a: FaceAnalysis) -> float:
        """Эмоция → балл по таблице."""
        if not a.emotion:
            return 50.0
        base = self.EMOTION_SCORES.get(a.emotion, 50.0)
        conf = a.emotion_confidence or 0.5
        # Чем ниже уверенность → тянем к нейтральному (50)
        return round(base * conf + 50.0 * (1.0 - conf), 2)

    def _head_pose_score(self, a: FaceAnalysis) -> float:
        """
        Голова направлена на доску.
        Допуск: yaw ±30°, pitch ±25°.
        """
        if a.head_yaw is None or a.head_pitch is None:
            return 50.0

        yaw_norm   = max(0.0, 1.0 - abs(a.head_yaw)   / 30.0)
        pitch_norm = max(0.0, 1.0 - abs(a.head_pitch)  / 25.0)
        return round(((yaw_norm + pitch_norm) / 2.0) * 100.0, 2)
