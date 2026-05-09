"""Главный пайплайн анализа кадра.

Процесс:
1. Декодируем base64 → BGR-кадр.
2. Проверяем качество кадра (brightness, blur). Если плохо — возвращаем
   результаты с `face_detected=False, not_detected_reason=<причина>`.
3. FaceDetection (BlazeFace) — bbox-ы всех лиц.
4. StudentTracker сопоставляет bbox со student_id (устойчивый ID).
5. Для каждого матча: FaceMesh по кропу → solvePnP → relative gaze →
   эмоция (DeepFace, опц.) → Pose (под лицом) → scorer.compute().
6. Сглаживаем engagement_score через EngagementSmoother (по 30 сек).
7. Студенты, для которых нет матча, помечаются face_detected=False.

Если модели недоступны и `ml_dev_mode=True` — возвращаем рандомные данные
(только для разработки). В production без моделей возвращаем 503.
"""

from __future__ import annotations

import base64
import time
from typing import Dict, List, Optional, Tuple

import cv2
import numpy as np
import structlog

from app.config import settings
from app.ml.model_manager import ModelManager
from app.ml.pose_estimator import estimate_gaze, estimate_head_pose
from app.ml.posture_estimator import estimate_posture
from app.ml.scorer import EngagementScorer, EngagementSmoother, FaceAnalysis
from app.ml.tracker import StudentTracker

logger = structlog.get_logger()

scorer = EngagementScorer()
smoother = EngagementSmoother(window_seconds=settings.smooth_window_seconds)

# По одному трекеру на сессию
_trackers: Dict[str, StudentTracker] = {}


def get_tracker(session_id: str, student_ids: List[str]) -> StudentTracker:
    """Возвращает (или создаёт) трекер для сессии. Перезаписывается если
    список студентов изменился (например, переоткрыли сессию)."""
    existing = _trackers.get(session_id)
    if existing is None or existing.student_ids != list(student_ids):
        _trackers[session_id] = StudentTracker(student_ids=student_ids)
    return _trackers[session_id]


def reset_tracker(session_id: str) -> None:
    _trackers.pop(session_id, None)
    smoother.clear(session_id)


class FaceAnalyzer:
    """Анализирует один кадр и возвращает FaceAnalysis на каждого студента."""

    def analyze_frame(
        self,
        frame_bytes_b64: str,
        session_id: str,
        camera_id: str,
        student_ids: List[str],
        captured_at: str,
    ) -> List[FaceAnalysis]:
        t_start = time.time()

        frame = self._decode_frame(frame_bytes_b64)
        if frame is None:
            return self._all_not_detected(
                student_ids, camera_id, captured_at, "frame_decode_error"
            )

        # ── Диагностика кадра ────────────────────────────────────
        quality = self._frame_quality(frame)
        if quality["brightness"] < settings.min_brightness:
            return self._all_not_detected(
                student_ids, camera_id, captured_at, "too_dark", quality
            )
        if quality["blur"] < settings.min_blur_variance:
            return self._all_not_detected(
                student_ids, camera_id, captured_at, "too_blurry", quality
            )

        face_detector = ModelManager.get_face_detector()
        face_mesh = ModelManager.get_face_mesh()

        # ── Если ML-моделей нет ──────────────────────────────────
        if face_detector is None or face_mesh is None:
            if settings.ml_dev_mode:
                return self._stub_results(student_ids, camera_id, captured_at)
            return self._all_not_detected(
                student_ids, camera_id, captured_at, "model_unavailable", quality
            )

        # ── Шаг 1: FaceDetection ─────────────────────────────────
        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        det_result = face_detector.process(rgb)
        bboxes_with_score: List[Tuple[Tuple[int, int, int, int], float]] = []
        if det_result and det_result.detections:
            h, w = frame.shape[:2]
            for det in det_result.detections:
                rb = det.location_data.relative_bounding_box
                x = max(0, int(rb.xmin * w))
                y = max(0, int(rb.ymin * h))
                bw = max(1, int(rb.width * w))
                bh = max(1, int(rb.height * h))
                if bw < settings.min_face_bbox_size_px or bh < settings.min_face_bbox_size_px:
                    continue
                score = float(det.score[0]) if det.score else 0.5
                bboxes_with_score.append(((x, y, bw, bh), score))

        if not bboxes_with_score:
            reason = "face_too_small" if (det_result and det_result.detections) else "no_faces_in_fov"
            return self._all_not_detected(
                student_ids, camera_id, captured_at, reason, quality
            )

        # ── Шаг 2: трекер сопоставляет bbox → student_id ─────────
        tracker = get_tracker(session_id, student_ids)
        bboxes = [bb for bb, _ in bboxes_with_score]
        score_by_bbox = {bb: sc for bb, sc in bboxes_with_score}
        sid_to_bbox = tracker.update(bboxes)

        # ── Шаг 3: для каждого студента — анализ его кропа ───────
        results: List[FaceAnalysis] = []
        for sid in student_ids:
            if sid not in sid_to_bbox:
                a = FaceAnalysis(
                    student_id=sid,
                    camera_id=camera_id,
                    captured_at=captured_at,
                    face_detected=False,
                    not_detected_reason="no_faces_in_fov",
                    frame_quality=quality,
                )
                a = scorer.compute(a)
                results.append(a)
                continue

            bb = sid_to_bbox[sid]
            face_conf = score_by_bbox.get(bb, 0.5)
            a = self._analyze_one_face(
                frame=frame,
                rgb=rgb,
                bbox=bb,
                face_conf=face_conf,
                student_id=sid,
                camera_id=camera_id,
                captured_at=captured_at,
                face_mesh=face_mesh,
            )
            a.frame_quality = quality
            a = scorer.compute(a)
            # Сглаживание
            smoothed = smoother.smooth(session_id, sid, a.engagement_score)
            a.engagement_score = smoothed
            a.processing_time_ms = round((time.time() - t_start) * 1000, 2)
            results.append(a)

        return results

    # ── Анализ одного лица ───────────────────────────────────────

    def _analyze_one_face(
        self,
        frame: np.ndarray,
        rgb: np.ndarray,
        bbox: Tuple[int, int, int, int],
        face_conf: float,
        student_id: str,
        camera_id: str,
        captured_at: str,
        face_mesh,
    ) -> FaceAnalysis:
        x, y, w, h = bbox
        a = FaceAnalysis(
            student_id=student_id,
            camera_id=camera_id,
            captured_at=captured_at,
            face_detected=True,
            face_confidence=round(face_conf, 3),
            face_bbox_x=x,
            face_bbox_y=y,
            face_bbox_w=w,
            face_bbox_h=h,
        )

        # FaceMesh по кропу
        pad = int(0.15 * max(w, h))
        x1 = max(0, x - pad)
        y1 = max(0, y - pad)
        x2 = min(frame.shape[1], x + w + pad)
        y2 = min(frame.shape[0], y + h + pad)
        face_rgb = rgb[y1:y2, x1:x2]
        if face_rgb.size == 0:
            return a

        try:
            mesh_result = face_mesh.process(face_rgb)
        except Exception as e:
            logger.debug("FaceMesh failed", error=str(e))
            return a

        if mesh_result and mesh_result.multi_face_landmarks:
            landmarks = mesh_result.multi_face_landmarks[0].landmark
            crop_h, crop_w = face_rgb.shape[:2]

            head_yaw, head_pitch, head_roll = estimate_head_pose(
                landmarks, crop_w, crop_h
            )
            a.head_yaw = head_yaw
            a.head_pitch = head_pitch
            a.head_roll = head_roll

            gaze_yaw, gaze_pitch = estimate_gaze(landmarks, head_yaw, head_pitch)
            a.gaze_yaw = gaze_yaw
            a.gaze_pitch = gaze_pitch

        # Эмоция (опционально)
        if ModelManager.is_emotion_available():
            self._detect_emotion(a, frame, x, y, w, h)

        # Поза тела
        pose_model = ModelManager.get_pose()
        posture, hand_raised = estimate_posture(pose_model, frame, bbox)
        if posture is not None:
            a.posture_state = posture
            a.hand_raised = hand_raised

        return a

    def _detect_emotion(self, a: FaceAnalysis, frame: np.ndarray,
                        x: int, y: int, w: int, h: int) -> None:
        emotion_model = ModelManager.get_emotion_model()
        if emotion_model is None:
            return
        try:
            padding = 10
            x1 = max(0, x - padding)
            y1 = max(0, y - padding)
            face_crop = frame[y1:y + h + padding, x1:x + w + padding]
            if face_crop.size == 0:
                return
            result = emotion_model.analyze(
                face_crop,
                actions=["emotion"],
                enforce_detection=False,
                silent=True,
            )
            if isinstance(result, list):
                result = result[0]
            emotions = result.get("emotion", {})
            dominant = result.get("dominant_emotion", "neutral")
            a.emotion = dominant
            a.emotion_confidence = round(
                emotions.get(dominant, 50.0) / 100.0, 3
            )
        except Exception as e:
            logger.debug("Emotion detection failed", error=str(e))

    # ── Утилиты ──────────────────────────────────────────────────

    def _frame_quality(self, frame: np.ndarray) -> Dict[str, float]:
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        brightness = float(gray.mean())
        blur = float(cv2.Laplacian(gray, cv2.CV_64F).var())
        ok = brightness >= settings.min_brightness and blur >= settings.min_blur_variance
        return {
            "brightness": round(brightness, 1),
            "blur": round(blur, 1),
            "ok": float(ok),
        }

    def _decode_frame(self, b64: str) -> Optional[np.ndarray]:
        try:
            data = base64.b64decode(b64)
            arr = np.frombuffer(data, dtype=np.uint8)
            return cv2.imdecode(arr, cv2.IMREAD_COLOR)
        except Exception as e:
            logger.error("Frame decode failed", error=str(e))
            return None

    def _all_not_detected(
        self,
        student_ids: List[str],
        camera_id: str,
        captured_at: str,
        reason: str,
        quality: Optional[Dict[str, float]] = None,
    ) -> List[FaceAnalysis]:
        out: List[FaceAnalysis] = []
        for sid in student_ids:
            a = FaceAnalysis(
                student_id=sid,
                camera_id=camera_id,
                captured_at=captured_at,
                face_detected=False,
                not_detected_reason=reason,
                frame_quality=quality,
            )
            a = scorer.compute(a)
            out.append(a)
        return out

    def _stub_results(
        self, student_ids: List[str], camera_id: str, captured_at: str
    ) -> List[FaceAnalysis]:
        """Только для ml_dev_mode=True — возвращает рандом для тестов."""
        import random
        results = []
        for sid in student_ids:
            a = FaceAnalysis(
                student_id=sid,
                camera_id=camera_id,
                captured_at=captured_at,
                face_detected=True,
                face_confidence=random.uniform(0.6, 0.95),
                gaze_yaw=random.uniform(-15, 15),
                gaze_pitch=random.uniform(-10, 10),
                head_yaw=random.uniform(-20, 20),
                head_pitch=random.uniform(-15, 15),
                head_roll=random.uniform(-5, 5),
                emotion=random.choice(["neutral", "happy", "neutral", "neutral"]),
                emotion_confidence=random.uniform(0.6, 0.95),
                posture_state=random.choice(["upright", "upright", "leaning_forward", "leaning_back"]),
            )
            a = scorer.compute(a)
            results.append(a)
        return results
