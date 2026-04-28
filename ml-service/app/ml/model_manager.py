import structlog
logger = structlog.get_logger()


class ModelManager:
    _face_detector = None

    @classmethod
    def warmup(cls):
        logger.info("Loading ML models...")
        cls._load_face_detector()
        logger.info("Models ready")

    @classmethod
    def get_face_detector(cls):
        if cls._face_detector is None:
            cls._load_face_detector()
        return cls._face_detector

    @classmethod
    def get_emotion_model(cls):
        # DeepFace опционален — без него используем заглушку
        return None

    @classmethod
    def _load_face_detector(cls):
        try:
            import mediapipe as mp
            cls._face_detector = mp.solutions.face_mesh.FaceMesh(
                static_image_mode=False,
                max_num_faces=25,
                refine_landmarks=True,
                min_detection_confidence=0.5,
                min_tracking_confidence=0.5,
            )
            logger.info("Face detector loaded (MediaPipe)")
        except Exception as e:
            logger.warning("MediaPipe not available, using stub", error=str(e))
            cls._face_detector = None
