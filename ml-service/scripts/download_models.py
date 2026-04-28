"""Прогрев моделей при сборке Docker образа."""
print("Checking ML models...")
try:
    import mediapipe as mp
    _ = mp.solutions.face_mesh.FaceMesh(static_image_mode=True, max_num_faces=1)
    print("  MediaPipe: OK")
except Exception as e:
    print(f"  MediaPipe: SKIP ({e})")
print("Done.")
