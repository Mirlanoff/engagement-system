"""Загрузка и кэш ML-моделей для анализа кадра.

Двухступенчатый пайплайн:
1. FaceDetection (BlazeFace full-range) — находит bbox всех лиц.
2. FaceMesh с max_num_faces=1 — запускается по кропу каждого bbox для landmarks.

Pose — MediaPipe Pose, по одному инстансу (тяжёлый, кешируется).
Эмоции — DeepFace (опционально). Если веса не подгружаются — отключаемся явно
и не возвращаем фейковую "neutral".
"""

from __future__ import annotations

import structlog

logger = structlog.get_logger()


class ModelManager:
    _face_detector = None       # mediapipe FaceDetection
    _face_mesh = None           # mediapipe FaceMesh (для одного лица)
    _pose = None                # mediapipe Pose
    _emotion_model = None       # callable analyze(...)
    _emotion_available: bool | None = None
    _initialized = False

    @classmethod
    def warmup(cls) -> None:
        """Прогрев всех моделей. Вызывается из FastAPI lifespan и при первом обращении."""
        if cls._initialized:
            return
        logger.info("Loading ML models...")
        cls._load_face_detector()
        cls._load_face_mesh()
        cls._load_pose()
        cls._load_emotion()
        cls._initialized = True
        logger.info(
            "Models loaded",
            face_detector=cls._face_detector is not None,
            face_mesh=cls._face_mesh is not None,
            pose=cls._pose is not None,
            emotion=cls._emotion_available,
        )

    # ── Face detector (BlazeFace full-range) ─────────────────────────

    @classmethod
    def get_face_detector(cls):
        if cls._face_detector is None:
            cls._load_face_detector()
        return cls._face_detector

    @classmethod
    def _load_face_detector(cls) -> None:
        try:
            import mediapipe as mp

            cls._face_detector = mp.solutions.face_detection.FaceDetection(
                model_selection=1,  # full-range, лучше для лиц чуть дальше от камеры
                min_detection_confidence=0.5,
            )
            logger.info("Face detector loaded (MediaPipe FaceDetection full-range)")
        except Exception as e:
            logger.warning("Face detector unavailable", error=str(e))
            cls._face_detector = None

    # ── Face mesh для одного лица (запускается по кропу) ──────────────

    @classmethod
    def get_face_mesh(cls):
        if cls._face_mesh is None:
            cls._load_face_mesh()
        return cls._face_mesh

    @classmethod
    def _load_face_mesh(cls) -> None:
        try:
            import mediapipe as mp

            cls._face_mesh = mp.solutions.face_mesh.FaceMesh(
                static_image_mode=False,
                max_num_faces=1,
                refine_landmarks=True,
                min_detection_confidence=0.5,
                min_tracking_confidence=0.5,
            )
            logger.info("Face mesh loaded (MediaPipe FaceMesh, single-face)")
        except Exception as e:
            logger.warning("Face mesh unavailable", error=str(e))
            cls._face_mesh = None

    # ── Pose ─────────────────────────────────────────────────────────

    @classmethod
    def get_pose(cls):
        if cls._pose is None:
            cls._load_pose()
        return cls._pose

    @classmethod
    def _load_pose(cls) -> None:
        try:
            import mediapipe as mp

            cls._pose = mp.solutions.pose.Pose(
                static_image_mode=False,
                model_complexity=1,
                smooth_landmarks=True,
                enable_segmentation=False,
                min_detection_confidence=0.5,
                min_tracking_confidence=0.5,
            )
            logger.info("Pose detector loaded (MediaPipe Pose)")
        except Exception as e:
            logger.warning("Pose detector unavailable", error=str(e))
            cls._pose = None

    # ── Emotion (DeepFace) ───────────────────────────────────────────

    @classmethod
    def get_emotion_model(cls):
        """Возвращает callable analyze(face_crop_bgr) -> dict или None если недоступно.

        В отличие от старой реализации, **не возвращаем заглушку**.
        Если модель не загрузилась — face_analyzer корректно исключит
        emotion из формулы вместо фейкового "neutral".
        """
        if cls._emotion_available is None:
            cls._load_emotion()
        return cls._emotion_model

    @classmethod
    def is_emotion_available(cls) -> bool:
        if cls._emotion_available is None:
            cls._load_emotion()
        return bool(cls._emotion_available)

    @classmethod
    def _load_emotion(cls) -> None:
        try:
            from deepface import DeepFace  # type: ignore

            # Прогрев — создаём детектор эмоции, чтобы скачать веса при первом вызове.
            # Если интернета нет и веса не закэшированы — упадёт здесь, и мы
            # явно отметим эмоцию как недоступную.
            DeepFace.build_model("Emotion")
            cls._emotion_model = DeepFace
            cls._emotion_available = True
            logger.info("Emotion model loaded (DeepFace Emotion)")
        except Exception as e:
            logger.warning(
                "Emotion model unavailable — emotion will be excluded from score",
                error=str(e),
            )
            cls._emotion_model = None
            cls._emotion_available = False
