#!/usr/bin/env python3
"""
Симуляция ML сервиса — отправляет тестовые данные в Laravel.
Запускай пока нет реальной камеры чтобы проверить весь поток.

Использование:
  python simulate.py --session SESSION_ID --token LARAVEL_TOKEN
"""

import argparse
import hashlib
import hmac
import json
import random
import time
import uuid
import requests
from datetime import datetime, timezone

# ── Настройки ────────────────────────────────────────────────────
API_URL       = "http://localhost"
ML_SECRET     = "MlSecret2024"   # ML_SERVICE_SECRET из .env
INTERVAL_SEC  = 5                # каждые 5 секунд

# 20 тестовых студентов (UUIDs берём из БД)
STUDENT_IDS = []  # заполним из API

EMOTIONS = ["neutral", "happy", "neutral", "neutral", "sad", "surprised"]

# ── HMAC подпись для internal API ────────────────────────────────
def sign(body: str) -> tuple[str, str]:
    ts  = str(int(time.time()))
    sig = hmac.new(ML_SECRET.encode(), (ts + body).encode(), hashlib.sha256).hexdigest()
    return ts, sig

# ── Получить список студентов класса ─────────────────────────────
def get_students(token: str, classroom_id: str) -> list:
    r = requests.get(
        f"{API_URL}/api/v1/sessions/active",
        headers={"Authorization": f"Bearer {token}"},
        timeout=5,
    )
    if r.status_code != 200:
        print(f"[ERR] Не могу получить сессии: {r.status_code}")
        return []

    sessions = r.json().get("data", [])
    if not sessions:
        print("[WARN] Нет активных сессий. Сначала начни урок в дашборде!")
        return []

    print(f"[OK] Нашёл {len(sessions)} активных сессий")
    return sessions

# ── Генерация одного снэпшота студента ──────────────────────────
def fake_snapshot(student_id: str, camera_id: str = "cam_front") -> dict:
    # Реалистичное распределение: большинство ~65-80, некоторые низкие
    base = random.gauss(68, 18)
    base = max(5.0, min(98.0, base))

    gaze_yaw   = random.gauss(0, 20)
    gaze_pitch = random.gauss(-5, 12)
    emotion    = random.choices(EMOTIONS, weights=[40, 20, 20, 10, 5, 5])[0]

    # Считаем компоненты
    gaze_score     = max(0, 100 - abs(gaze_yaw) * 2 - abs(gaze_pitch) * 1.5)
    emotion_scores = {"happy": 95, "neutral": 70, "surprised": 60, "sad": 25, "fearful": 30}
    emotion_score  = emotion_scores.get(emotion, 50) + random.gauss(0, 5)
    head_pose_score = max(0, 100 - abs(gaze_yaw) * 1.5)
    presence_score = random.choices([95.0, 0.0], weights=[92, 8])[0]

    face_detected = presence_score > 0

    engagement_score = (
        0.30 * gaze_score +
        0.30 * max(0, emotion_score) +
        0.20 * head_pose_score +
        0.20 * presence_score
    )
    engagement_score = round(max(0, min(100, engagement_score)), 2)

    return {
        "student_id":          student_id,
        "camera_id":           camera_id,
        "captured_at":         datetime.now(timezone.utc).isoformat(),
        "engagement_score":    engagement_score,
        "gaze_score":          round(gaze_score, 2),
        "emotion_score":       round(max(0, emotion_score), 2),
        "head_pose_score":     round(head_pose_score, 2),
        "presence_score":      presence_score,
        "emotion":             emotion if face_detected else None,
        "emotion_confidence":  round(random.uniform(0.6, 0.95), 3),
        "gaze_yaw":            round(gaze_yaw, 2),
        "gaze_pitch":          round(gaze_pitch, 2),
        "head_yaw":            round(gaze_yaw + random.gauss(0, 3), 2),
        "head_pitch":          round(gaze_pitch + random.gauss(0, 2), 2),
        "head_roll":           round(random.gauss(0, 5), 2),
        "face_detected":       face_detected,
        "face_confidence":     round(random.uniform(0.7, 0.98), 3) if face_detected else None,
        "processing_time_ms":  round(random.uniform(80, 250), 1),
    }

# ── Отправка батча снэпшотов в Laravel ──────────────────────────
def send_snapshots(session_id: str, student_ids: list) -> bool:
    snapshots = [fake_snapshot(sid) for sid in student_ids]

    payload = json.dumps({
        "session_id": session_id,
        "snapshots":  snapshots,
    })

    ts, sig = sign(payload)

    try:
        r = requests.post(
            f"{API_URL}/api/internal/snapshots",
            data=payload,
            headers={
                "Content-Type":         "application/json",
                "X-Internal-Signature": sig,
                "X-Internal-Timestamp": ts,
            },
            timeout=10,
        )

        if r.status_code == 202:
            avg = sum(s["engagement_score"] for s in snapshots) / len(snapshots)
            print(f"[{datetime.now().strftime('%H:%M:%S')}] "
                  f"✓ {len(snapshots)} снэпшотов | avg: {avg:.1f}%")
            return True
        else:
            print(f"[ERR] {r.status_code}: {r.text[:200]}")
            return False

    except Exception as e:
        print(f"[ERR] {e}")
        return False

# ── Главный цикл ─────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--token",      required=True,  help="Bearer токен из логина")
    parser.add_argument("--session",    required=False, help="ID сессии (если уже есть)")
    parser.add_argument("--classroom",  default=None,   help="ID класса для создания сессии")
    parser.add_argument("--students",   default=20,     type=int)
    parser.add_argument("--interval",   default=5,      type=int)
    args = parser.parse_args()

    print("=" * 50)
    print("  Симуляция ML сервиса — Engagement Monitor")
    print("=" * 50)

    # Получаем активные сессии
    sessions = get_students(args.token, args.classroom)

    if args.session:
        session_id = args.session
        print(f"[INFO] Используем сессию: {session_id}")
    elif sessions:
        session_id = sessions[0]["id"]
        print(f"[INFO] Используем первую активную сессию: {session_id}")
    else:
        print("[ERR] Укажи --session ID или начни урок в дашборде")
        return

    # Генерируем тестовые UUID студентов если не знаем реальные
    student_ids = [str(uuid.uuid4()) for _ in range(args.students)]
    print(f"[INFO] Симулируем {len(student_ids)} студентов")
    print(f"[INFO] Интервал: {args.interval} сек")
    print(f"[INFO] Открой http://localhost в браузере")
    print(f"[INFO] Ctrl+C для остановки\n")

    iteration = 0
    try:
        while True:
            iteration += 1
            send_snapshots(session_id, student_ids)
            time.sleep(args.interval)

    except KeyboardInterrupt:
        print(f"\n[INFO] Остановлено после {iteration} итераций")


if __name__ == "__main__":
    main()
