"""Тесты диагностики кадра в FaceAnalyzer.

Проверяем что too_dark / too_blurry / no_faces_in_fov корректно
формируются БЕЗ обращения к реальным MediaPipe моделям — мы патчим
ModelManager, чтобы детектор возвращал None или пустой результат.
"""

import base64
from unittest import mock

import cv2
import numpy as np

from app.ml.face_analyzer import FaceAnalyzer


def _b64_jpeg(img: np.ndarray) -> str:
    ok, buf = cv2.imencode(".jpg", img)
    assert ok
    return base64.b64encode(buf.tobytes()).decode()


def _dark_frame() -> str:
    return _b64_jpeg(np.zeros((480, 640, 3), dtype=np.uint8))


def _bright_uniform_frame() -> str:
    # Равномерное серое изображение — высокая яркость, нулевая дисперсия Лапласа → too_blurry
    img = np.full((480, 640, 3), 200, dtype=np.uint8)
    return _b64_jpeg(img)


def _ok_noisy_frame() -> str:
    rng = np.random.default_rng(0)
    img = rng.integers(80, 220, size=(480, 640, 3), dtype=np.uint8)
    return _b64_jpeg(img)


def test_too_dark_frame_returns_diagnostic_reason():
    analyzer = FaceAnalyzer()
    out = analyzer.analyze_frame(
        frame_bytes_b64=_dark_frame(),
        session_id="sess",
        camera_id="cam",
        student_ids=["s1", "s2"],
        captured_at="2024-01-01T00:00:00Z",
    )
    assert len(out) == 2
    for r in out:
        assert r.face_detected is False
        assert r.not_detected_reason == "too_dark"


def test_too_blurry_frame_returns_diagnostic_reason():
    analyzer = FaceAnalyzer()
    out = analyzer.analyze_frame(
        frame_bytes_b64=_bright_uniform_frame(),
        session_id="sess",
        camera_id="cam",
        student_ids=["s1"],
        captured_at="2024-01-01T00:00:00Z",
    )
    assert out[0].not_detected_reason == "too_blurry"


def test_no_faces_returns_no_faces_in_fov():
    analyzer = FaceAnalyzer()
    fake_detector = mock.MagicMock()
    fake_detector.process.return_value = mock.MagicMock(detections=None)
    fake_mesh = mock.MagicMock()

    with mock.patch(
        "app.ml.face_analyzer.ModelManager.get_face_detector", return_value=fake_detector
    ), mock.patch(
        "app.ml.face_analyzer.ModelManager.get_face_mesh", return_value=fake_mesh
    ):
        out = analyzer.analyze_frame(
            frame_bytes_b64=_ok_noisy_frame(),
            session_id="sess2",
            camera_id="cam",
            student_ids=["s1"],
            captured_at="2024-01-01T00:00:00Z",
        )
    assert out[0].not_detected_reason == "no_faces_in_fov"


def test_model_unavailable_returns_diagnostic_when_dev_mode_off():
    """С `ml_dev_mode=False` (default) — без моделей возвращаем причину."""
    analyzer = FaceAnalyzer()
    with mock.patch(
        "app.ml.face_analyzer.ModelManager.get_face_detector", return_value=None
    ), mock.patch(
        "app.ml.face_analyzer.ModelManager.get_face_mesh", return_value=None
    ):
        out = analyzer.analyze_frame(
            frame_bytes_b64=_ok_noisy_frame(),
            session_id="sess3",
            camera_id="cam",
            student_ids=["s1"],
            captured_at="2024-01-01T00:00:00Z",
        )
    assert out[0].not_detected_reason == "model_unavailable"
    assert out[0].face_detected is False
