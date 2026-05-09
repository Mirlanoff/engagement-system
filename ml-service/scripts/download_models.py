"""Прогрев моделей при сборке Docker образа.

Скачиваем веса MediaPipe (FaceDetection, FaceMesh, Pose) и опционально
DeepFace Emotion. Если интернета нет в build-окружении — `|| true`
в Dockerfile позволит образу собраться, а warmup при старте сервиса
сделает повторную попытку.
"""

print("Checking ML models...")

try:
    import mediapipe as mp

    _ = mp.solutions.face_detection.FaceDetection(model_selection=1)
    print("  MediaPipe FaceDetection: OK")
except Exception as e:
    print(f"  MediaPipe FaceDetection: SKIP ({e})")

try:
    import mediapipe as mp

    _ = mp.solutions.face_mesh.FaceMesh(static_image_mode=True, max_num_faces=1)
    print("  MediaPipe FaceMesh: OK")
except Exception as e:
    print(f"  MediaPipe FaceMesh: SKIP ({e})")

try:
    import mediapipe as mp

    _ = mp.solutions.pose.Pose(static_image_mode=True, model_complexity=1)
    print("  MediaPipe Pose: OK")
except Exception as e:
    print(f"  MediaPipe Pose: SKIP ({e})")

try:
    from deepface import DeepFace  # type: ignore

    DeepFace.build_model("Emotion")
    print("  DeepFace Emotion: OK")
except Exception as e:
    print(f"  DeepFace Emotion: SKIP ({e})")

print("Done.")
