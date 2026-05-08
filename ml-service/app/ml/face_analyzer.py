import base64
import time
from typing import List, Optional
import numpy as np
import cv2
import structlog

from app.ml.model_manager import ModelManager
from app.ml.scorer import FaceAnalysis, EngagementScorer

logger = structlog.get_logger()
scorer = EngagementScorer()


class FaceAnalyzer:
    """
    Анализирует один кадр: находит лица, определяет взгляд,
    позу головы и эмоцию для каждого лица.
    """

    def analyze_frame(
        self,
        frame_bytes_b64: str,
        session_id: str,
        camera_id: str,
        student_ids: List[str],
        captured_at: str,
    ) -> List[FaceAnalysis]:
        """
        Возвращает список FaceAnalysis — по одному на каждого студента.
        student_ids — порядок студентов по позициям в классе (слева направо).
        """
        t_start = time.time()

        frame = self._decode_frame(frame_bytes_b64)
        if frame is None:
            return []

        results = []
        face_mesh = ModelManager.get_face_detector()

        if face_mesh is None:
            # Нет моделей — возвращаем заглушки (для тестов)
            return self._stub_results(student_ids, camera_id, captured_at)

        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        mesh_result = face_mesh.process(rgb)

        if not mesh_result.multi_face_landmarks:
            # Никого не обнаружено
            for sid in student_ids:
                results.append(FaceAnalysis(
                    student_id=sid,
                    camera_id=camera_id,
                    captured_at=captured_at,
                    face_detected=False,
                ))
            return results

        h, w = frame.shape[:2]
        faces = mesh_result.multi_face_landmarks

        # Сопоставляем найденные лица со студентами по горизонтальной позиции
        # (сортируем лица слева направо — как сидят студенты)
        face_positions = []
        for i, landmarks in enumerate(faces):
            cx = np.mean([lm.x for lm in landmarks.landmark]) * w
            face_positions.append((cx, i, landmarks))

        face_positions.sort(key=lambda x: x[0])  # слева направо

        for j, student_id in enumerate(student_ids):
            if j >= len(face_positions):
                # Студента нет в кадре
                results.append(FaceAnalysis(
                    student_id=student_id,
                    camera_id=camera_id,
                    captured_at=captured_at,
                    face_detected=False,
                ))
                continue

            _, _, landmarks = face_positions[j]

            analysis = FaceAnalysis(
                student_id=student_id,
                camera_id=camera_id,
                captured_at=captured_at,
                face_detected=True,
                face_confidence=0.85,
            )

            # Bbox
            xs = [lm.x * w for lm in landmarks.landmark]
            ys = [lm.y * h for lm in landmarks.landmark]
            analysis.face_bbox_x = int(min(xs))
            analysis.face_bbox_y = int(min(ys))
            analysis.face_bbox_w = int(max(xs) - min(xs))
            analysis.face_bbox_h = int(max(ys) - min(ys))

            # Поза головы и взгляд из landmarks
            self._estimate_head_pose(analysis, landmarks, w, h)
            self._estimate_gaze(analysis, landmarks)

            # Эмоция
            self._detect_emotion(
                analysis, frame,
                analysis.face_bbox_x, analysis.face_bbox_y,
                analysis.face_bbox_w, analysis.face_bbox_h,
            )

            # Итоговый score
            analysis = scorer.compute(analysis)
            analysis.processing_time_ms = round((time.time() - t_start) * 1000, 2)

            results.append(analysis)

        return results

    def _estimate_head_pose(self, analysis: FaceAnalysis, landmarks, w: int, h: int):
        """
        Приближённая оценка позы головы по ключевым точкам FaceMesh.
        """
        lm = landmarks.landmark

        # Ключевые точки: нос (1), подбородок (152), левое ухо (234), правое (454)
        nose   = np.array([lm[1].x * w,   lm[1].y * h,   lm[1].z * w])
        chin   = np.array([lm[152].x * w, lm[152].y * h, lm[152].z * w])
        l_ear  = np.array([lm[234].x * w, lm[234].y * h, lm[234].z * w])
        r_ear  = np.array([lm[454].x * w, lm[454].y * h, lm[454].z * w])

        # Вертикальная ось (pitch) — наклон вперёд/назад
        face_height = np.linalg.norm(chin - nose)
        vertical    = chin - nose
        analysis.head_pitch = round(
            np.degrees(np.arctan2(vertical[2], vertical[1])), 2
        )

        # Горизонтальная ось (yaw) — поворот влево/вправо
        horizontal  = r_ear - l_ear
        analysis.head_yaw = round(
            np.degrees(np.arctan2(horizontal[2], horizontal[0])), 2
        )

        # Roll — наклон головы в сторону
        analysis.head_roll = round(
            np.degrees(np.arctan2(l_ear[1] - r_ear[1], l_ear[0] - r_ear[0])), 2
        )

    def _estimate_gaze(self, analysis: FaceAnalysis, landmarks):
        """
        Оценка направления взгляда по радужке глаза (MediaPipe refinement).
        Точки 468-473 — левый глаз, 473-478 — правый.
        """
        lm = landmarks.landmark
        if len(lm) < 478:
            # refine_landmarks не включён
            analysis.gaze_yaw   = analysis.head_yaw
            analysis.gaze_pitch = analysis.head_pitch
            return

        # Центр радужки левого глаза
        left_iris  = np.mean([[lm[i].x, lm[i].y] for i in range(468, 473)], axis=0)
        right_iris = np.mean([[lm[i].x, lm[i].y] for i in range(473, 478)], axis=0)

        # Угловое отклонение от центра (0.5, 0.5)
        avg_x = (left_iris[0] + right_iris[0]) / 2
        avg_y = (left_iris[1] + right_iris[1]) / 2

        # Переводим смещение от центра в градусы (грубая оценка)
        analysis.gaze_yaw   = round((avg_x - 0.5) * 90, 2)
        analysis.gaze_pitch = round((avg_y - 0.5) * 60, 2)

    def _detect_emotion(
        self, analysis: FaceAnalysis,
        frame, x: int, y: int, w: int, h: int
    ):
        """Обрезаем лицо и прогоняем через DeepFace."""
        emotion_model = ModelManager.get_emotion_model()
        if emotion_model is None:
            analysis.emotion = "neutral"
            analysis.emotion_confidence = 0.5
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

            analysis.emotion = dominant
            analysis.emotion_confidence = round(
                emotions.get(dominant, 50.0) / 100.0, 3
            )

        except Exception as e:
            logger.debug("Emotion detection failed", error=str(e))
            analysis.emotion = "neutral"
            analysis.emotion_confidence = 0.5

    def _decode_frame(self, b64: str) -> Optional[np.ndarray]:
        try:
            data  = base64.b64decode(b64)
            arr   = np.frombuffer(data, dtype=np.uint8)
            frame = cv2.imdecode(arr, cv2.IMREAD_COLOR)
            return frame
        except Exception as e:
            logger.error("Frame decode failed", error=str(e))
            return None

    def _stub_results(
        self, student_ids: List[str], camera_id: str, captured_at: str
    ) -> List[FaceAnalysis]:
        """Тестовые данные когда нет реальных моделей.

        Раскладываем «лица» по горизонтали в кадре 640×480, чтобы на дашборде
        были видны рамки и эмоции и без полноценного ML.
        """
        import random
        results = []
        n        = max(1, len(student_ids))
        frame_w  = 640
        frame_h  = 480
        face_w   = max(80, min(160, frame_w // (n + 1)))
        face_h   = int(face_w * 1.25)
        gap      = (frame_w - face_w * n) // (n + 1)
        top      = (frame_h - face_h) // 2

        for i, sid in enumerate(student_ids):
            x = gap + i * (face_w + gap) + random.randint(-6, 6)
            y = top + random.randint(-8, 8)
            a = FaceAnalysis(
                student_id=sid,
                camera_id=camera_id,
                captured_at=captured_at,
                face_detected=True,
                face_confidence=0.9,
                face_bbox_x=int(max(0, x)),
                face_bbox_y=int(max(0, y)),
                face_bbox_w=int(face_w),
                face_bbox_h=int(face_h),
                gaze_yaw=random.uniform(-15, 15),
                gaze_pitch=random.uniform(-10, 10),
                head_yaw=random.uniform(-20, 20),
                head_pitch=random.uniform(-15, 15),
                head_roll=random.uniform(-5, 5),
                emotion=random.choice(["neutral", "happy", "neutral", "surprise"]),
                emotion_confidence=random.uniform(0.6, 0.95),
            )
            a = scorer.compute(a)
            results.append(a)
        return results
