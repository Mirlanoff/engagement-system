"""Тесты EngagementScorer и Smoother."""

from datetime import datetime, timedelta

from app.ml.scorer import EngagementScorer, EngagementSmoother, FaceAnalysis


def _make(face_detected=True, **kwargs) -> FaceAnalysis:
    base = FaceAnalysis(
        student_id="s1",
        camera_id="cam1",
        captured_at="2024-01-01T00:00:00Z",
        face_detected=face_detected,
        face_confidence=0.9 if face_detected else 0.0,
    )
    for k, v in kwargs.items():
        setattr(base, k, v)
    return base


def test_face_not_detected_yields_zero_score_and_breakdown():
    s = EngagementScorer()
    a = _make(face_detected=False, not_detected_reason="too_dark")
    a = s.compute(a)
    assert a.engagement_score == 0
    assert a.attention_state == "absent"
    assert a.score_breakdown is not None
    # Reason должен попасть в текст
    assert "тёмн" in a.score_breakdown["presence"]["reason"].lower()


def test_focused_student_high_score():
    s = EngagementScorer()
    a = _make(
        gaze_yaw=2.0,
        gaze_pitch=1.0,
        head_yaw=3.0,
        head_pitch=2.0,
        emotion="happy",
        emotion_confidence=0.85,
        posture_state="upright",
    )
    a = s.compute(a)
    assert a.engagement_score >= 80
    assert a.attention_state == "focused"


def test_distracted_when_gaze_far():
    s = EngagementScorer()
    a = _make(
        gaze_yaw=45.0,
        gaze_pitch=0.0,
        head_yaw=10.0,
        head_pitch=0.0,
        emotion="neutral",
        emotion_confidence=0.7,
        posture_state="upright",
    )
    a = s.compute(a)
    assert a.attention_state == "distracted"


def test_drowsy_when_head_pitch_low():
    s = EngagementScorer()
    a = _make(
        head_yaw=0.0,
        head_pitch=35.0,  # голова сильно вниз
        gaze_yaw=0.0,
        gaze_pitch=0.0,
        emotion="neutral",
        emotion_confidence=0.6,
        posture_state="slouched",
    )
    a = s.compute(a)
    assert a.attention_state == "drowsy"


def test_emotion_unavailable_renormalizes_weights():
    """Если эмоция None — её вес не должен тянуть итог к 50% neutral."""
    s = EngagementScorer()
    a_with = _make(
        gaze_yaw=0, gaze_pitch=0, head_yaw=0, head_pitch=0,
        emotion=None, emotion_confidence=None,
        posture_state="upright",
    )
    a_with = s.compute(a_with)
    # Без эмоции отличный студент должен получить высокий балл,
    # а не "размытый" из-за нейтрального placeholder=50%.
    assert a_with.engagement_score >= 85
    assert "emotion" not in a_with.score_breakdown


def test_posture_unavailable_renormalizes_weights():
    s = EngagementScorer()
    a = _make(
        gaze_yaw=0, gaze_pitch=0, head_yaw=0, head_pitch=0,
        emotion="happy", emotion_confidence=0.9,
        posture_state=None,
    )
    a = s.compute(a)
    assert a.engagement_score >= 85
    assert "posture" not in a.score_breakdown


def test_breakdown_contains_human_reasons():
    s = EngagementScorer()
    a = _make(
        gaze_yaw=0, gaze_pitch=0, head_yaw=0, head_pitch=0,
        emotion="happy", emotion_confidence=0.9,
        posture_state="upright",
    )
    a = s.compute(a)
    for key, comp in a.score_breakdown.items():
        assert isinstance(comp["reason"], str) and len(comp["reason"]) > 0


def test_hand_raised_bumps_posture():
    s = EngagementScorer()
    base = s.compute(_make(
        gaze_yaw=0, gaze_pitch=0, head_yaw=0, head_pitch=0,
        emotion="neutral", emotion_confidence=0.7,
        posture_state="leaning_back",
    ))
    raised = s.compute(_make(
        gaze_yaw=0, gaze_pitch=0, head_yaw=0, head_pitch=0,
        emotion="neutral", emotion_confidence=0.7,
        posture_state="leaning_back",
        hand_raised=True,
    ))
    assert raised.posture_score > base.posture_score


def test_smoother_returns_running_average():
    sm = EngagementSmoother(window_seconds=30)
    now = datetime.utcnow()
    sm.smooth("sess", "stud", 50.0, now)
    sm.smooth("sess", "stud", 70.0, now + timedelta(seconds=1))
    avg = sm.smooth("sess", "stud", 90.0, now + timedelta(seconds=2))
    assert avg == 70.0


def test_smoother_drops_old_samples():
    sm = EngagementSmoother(window_seconds=10)
    base = datetime.utcnow()
    sm.smooth("sess", "stud", 0.0, base)
    later = sm.smooth("sess", "stud", 100.0, base + timedelta(seconds=20))
    # Старая 0 должна была вылететь из окна
    assert later == 100.0
