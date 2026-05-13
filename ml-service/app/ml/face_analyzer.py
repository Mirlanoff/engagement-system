import base64
import os
import time
from typing import Dict, List, Optional
import numpy as np
import cv2
import structlog

from app.ml.embedding_utils import (
    compute_geometric_embedding,
    match_face_to_student,
)
from app.ml.model_manager import ModelManager
from app.ml.scorer import FaceAnalysis, EngagementScorer

logger = structlog.get_logger()
scorer = EngagementScorer()

# Recognition threshold for live frame analysis. Lenient on purpose: live
# webcam frames have very different lighting / pose than the registration
# photo, so 0.5 is a good starting point. Tunable via env var.
FACE_RECOGNITION_THRESHOLD = float(
    os.environ.get("FACE_RECOGNITION_THRESHOLD", "0.5")
)


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
        student_embeddings: Optional[Dict[str, List[float]]] = None,
    ) -> List[FaceAnalysis]:
        """
        Возвращает список FaceAnalysis — по одному на каждого студента.

        Сопоставление лица → студент:
        • Если для студента есть face embedding (в ``student_embeddings``) —
          ищем совпадение по косинусной близости с ``FACE_RECOGNITION_THRESHOLD``.
        • Оставшиеся (нераспознанные) лица распределяем по горизонтальной
          позиции между студентами без фото (обратная совместимость).

        student_ids — все студенты класса, student_embeddings — подмножество
        тех, у кого зарегистрировано лицо.
        """
        t_start = time.time()
        student_embeddings = student_embeddings or {}

        frame = self._decode_frame(frame_bytes_b64)
        if frame is None:
            return []

        face_mesh = ModelManager.get_face_detector()

        if face_mesh is None:
            # Нет моделей — возвращаем заглушки (для тестов)
            return self._stub_results(student_ids, camera_id, captured_at)

        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        mesh_result = face_mesh.process(rgb)

        if not mesh_result.multi_face_landmarks:
            # Никого не обнаружено
            return [
                FaceAnalysis(
                    student_id=sid,
                    camera_id=camera_id,
                    captured_at=captured_at,
                    face_detected=False,
                )
                for sid in student_ids
            ]

        h, w = frame.shape[:2]
        faces = mesh_result.multi_face_landmarks

        # Сортируем лица слева направо для fallback по позиции.
        face_positions = []
        for i, face_landmarks in enumerate(faces):
            cx = float(np.mean([lm.x for lm in face_landmarks.landmark]) * w)
            face_positions.append((cx, i, face_landmarks))
        face_positions.sort(key=lambda x: x[0])

        # Этап 1: для каждого лица считаем embedding и пытаемся
        # найти student_id в known embeddings. Студент не может быть
        # привязан к двум лицам в одном кадре.
        face_to_student: Dict[int, Optional[str]] = {}
        matched_students: set[str] = set()
        if student_embeddings:
            known_in_class = {
                sid: emb
                for sid, emb in student_embeddings.items()
                if sid in student_ids and emb
            }
            for idx_in_order, (_, _, face_landmarks) in enumerate(face_positions):
                face_emb = compute_geometric_embedding(face_landmarks.landmark)
                matched = match_face_to_student(
                    face_emb,
                    known_in_class,
                    threshold=FACE_RECOGNITION_THRESHOLD,
                    exclude=matched_students,
                )
                face_to_student[idx_in_order] = matched
                if matched:
                    matched_students.add(matched)

        # Этап 2: оставшиеся лица распределяем по позиции между
        # студентами БЕЗ фото. Зарегистрированных, но не совпавших по
        # embedding, считаем отсутствующими в кадре — не присваиваем им
        # случайное лицо по позиции, чтобы не возвращать ту же ошибку,
        # которую этот PR и должен был исправить.
        unregistered_students = [
            sid for sid in student_ids if sid not in student_embeddings
        ]
        position_iter = iter(unregistered_students)
        for idx_in_order in range(len(face_positions)):
            if face_to_student.get(idx_in_order) is None:
                face_to_student[idx_in_order] = next(position_iter, None)

        # Этап 3: строим FaceAnalysis для всех студентов класса.
        student_to_face_idx: Dict[str, int] = {
            sid: idx
            for idx, sid in face_to_student.items()
            if sid is not None
        }

        results: List[FaceAnalysis] = []
        for student_id in student_ids:
            face_idx = student_to_face_idx.get(student_id)
            if face_idx is None:
                # Студент отсутствует в кадре
                results.append(FaceAnalysis(
                    student_id=student_id,
                    camera_id=camera_id,
                    captured_at=captured_at,
                    face_detected=False,
                ))
                continue

            _, _, face_landmarks = face_positions[face_idx]
            results.append(self._analyse_face(
                face_landmarks=face_landmarks,
                frame=frame,
                student_id=student_id,
                camera_id=camera_id,
                captured_at=captured_at,
                t_start=t_start,
                w=w,
                h=h,
            ))

        return results

    def _analyse_face(
        self,
        *,
        face_landmarks,
        frame: np.ndarray,
        student_id: str,
        camera_id: str,
        captured_at: str,
        t_start: float,
        w: int,
        h: int,
    ) -> FaceAnalysis:
        analysis = FaceAnalysis(
            student_id=student_id,
            camera_id=camera_id,
            captured_at=captured_at,
            face_detected=True,
            face_confidence=0.85,
        )

        xs = [lm.x * w for lm in face_landmarks.landmark]
        ys = [lm.y * h for lm in face_landmarks.landmark]
        analysis.face_bbox_x = int(min(xs))
        analysis.face_bbox_y = int(min(ys))
        analysis.face_bbox_w = int(max(xs) - min(xs))
        analysis.face_bbox_h = int(max(ys) - min(ys))

        self._estimate_head_pose(analysis, face_landmarks, w, h)
        self._estimate_gaze(analysis, face_landmarks)
        self._detect_emotion(
            analysis, frame,
            analysis.face_bbox_x, analysis.face_bbox_y,
            analysis.face_bbox_w, analysis.face_bbox_h,
        )

        analysis = scorer.compute(analysis)
        analysis.processing_time_ms = round((time.time() - t_start) * 1000, 2)
        return analysis

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
        """Тестовые данные когда нет реальных моделей."""
        import random
        results = []
        for sid in student_ids:
            a = FaceAnalysis(
                student_id=sid,
                camera_id=camera_id,
                captured_at=captured_at,
                face_detected=True,
                face_confidence=0.9,
                gaze_yaw=random.uniform(-15, 15),
                gaze_pitch=random.uniform(-10, 10),
                head_yaw=random.uniform(-20, 20),
                head_pitch=random.uniform(-15, 15),
                head_roll=random.uniform(-5, 5),
                emotion=random.choice(["neutral", "happy", "neutral", "neutral"]),
                emotion_confidence=random.uniform(0.6, 0.95),
            )
            a = scorer.compute(a)
            results.append(a)
        return results
