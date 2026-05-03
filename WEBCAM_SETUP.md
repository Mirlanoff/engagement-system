# Webcam capture setup

This project can use a local USB webcam as the ML capture source.

## Environment

Set the frame interval to 10 seconds:

```env
FRAME_INTERVAL=10
WEBCAM_DEVICE=/dev/video0
```

`docker-compose.override.yml` maps `${WEBCAM_DEVICE}` into the ML service as
`/dev/video0` for local development.

## Classroom camera config

For a USB webcam, set the classroom `camera_config` entry to use OpenCV device
index `0`:

```json
[
  {
    "id": "webcam_front",
    "rtsp_url": "0",
    "position": "front",
    "is_active": true
  }
]
```

For an IP/RTSP camera, keep a normal RTSP URL:

```json
[
  {
    "id": "cam_front",
    "rtsp_url": "rtsp://user:password@camera-ip:554/stream",
    "position": "front",
    "is_active": true
  }
]
```

## Runtime flow

1. Laravel starts a lesson session.
2. `App\Services\SessionService` calls the ML service `/capture/start` endpoint.
3. The ML service opens the webcam/RTSP stream with OpenCV.
4. A frame is captured every `FRAME_INTERVAL` seconds.
5. Celery analyzes face, gaze, emotion, head pose, and engagement.
6. The ML service sends signed snapshots to Laravel `/api/internal/snapshots`.
7. Laravel stores snapshots and broadcasts updates to the dashboard.

## Useful terminals

Terminal 1 — stack:

```bash
docker compose up -d postgres redis laravel nginx soketi ml-service celery-worker
```

Terminal 2 — ML logs:

```bash
docker compose logs -f ml-service celery-worker
```

Terminal 3 — app logs:

```bash
docker compose logs -f laravel nginx soketi
```

Terminal 4 — monitoring:

```bash
docker compose logs -f prometheus grafana flower
```

If `/dev/video0` does not exist on the machine, use a real RTSP camera URL or
attach a USB webcam to the host before starting `ml-service`.
