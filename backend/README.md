# Engagement System Backend

Laravel API for sessions, classrooms, engagement snapshots, alerts, recommendations, Sanctum auth, queues and WebSocket broadcasting.

## Local commands

```bash
composer install
php artisan test
./vendor/bin/pint
```

In the full stack, run commands from the repository root through Docker Compose:

```bash
./scripts/manage.sh install
./scripts/manage.sh artisan migrate
./scripts/manage.sh test
```

## Production notes

- `/api/internal/*` is protected by HMAC middleware using `ML_SERVICE_SECRET`.
- Broadcasting uses Soketi through the Pusher driver (`BROADCAST_CONNECTION=pusher`).
- Demo seed data is skipped in production unless `SEED_DEMO_DATA=true`.
