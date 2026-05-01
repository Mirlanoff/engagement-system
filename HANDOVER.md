# Handover

## Current Progress

- PR #3 is open: https://github.com/Mirlanoff/engagement-system/pull/3
- Working branch: `devin/1777660614-production-readiness`
- Production-readiness hardening is mostly complete for P0/pre-production:
  - Removed tracked runtime artifacts and sensitive files from git tracking:
    `.env`, `full_backup.sql`, `frontend/node_modules`, `frontend/dist`.
  - Expanded ignore/dockerignore coverage for secrets, build outputs, backups,
    dependencies, and generated TLS files.
  - Moved runtime secrets/config to environment variables:
    `ML_SERVICE_SECRET`, `CLAUDE_API_KEY`, database/Redis/Pusher/Grafana/Flower
    secrets, `SEED_DEMO_DATA`, and `ML_EXPOSE_DOCS`.
  - Added HMAC protection for internal ML API routes.
  - Added login throttling.
  - Added Laravel broadcast config/channels and configurable frontend WebSocket
    settings.
  - Removed hardcoded demo login hints from the frontend login view.
  - Disabled FastAPI docs by default in production with `ML_EXPOSE_DOCS=false`.
  - Hardened Docker Compose/nginx monitoring stack:
    HTTPS redirect, security headers, SPA routing, WebSocket proxy, Grafana
    route, Prometheus exporters for Postgres/Redis/node/nginx, `alerts.yml`,
    and TLS placeholder/autogeneration.
  - Removed invalid production scheduler/Prometheus checks for missing
    commands/endpoints.
  - Hardened the legacy/reference `backend-our-code` demo seeder so it no longer
    seeds fixed `password` credentials by default.
  - Aligned `backend/.env.example` with production-style PostgreSQL/Redis
    placeholders instead of local debug/sqlite defaults.
  - Updated README production-readiness checklist and deployment guidance.

## Verified Locally

- `docker compose -f docker-compose.yml config` passed.
- Frontend build passed.
- Backend production Docker build passed.
- Frontend Docker build passed.
- ML Docker build passed.
- ML Python compile passed.
- Laravel Pint on touched files passed.
- `php artisan test` passed with one existing warning.
- Latest small checks:
  - `docker compose -f docker-compose.yml config`
  - Pint for `backend/app/Console/Kernel.php`
  - PHP syntax check for `backend-our-code/database/seeders/DatabaseSeeder.php`
  - `git diff --check`

## Pending Tasks

Work in small chunks:

1. Review status
   - Open PR #3.
   - Check Devin Review status.
   - If still failing, run/inspect Devin Review directly. The web UI showed
     "2 Potential bugs" but required sign-in/CLI access; GitHub comments did not
     expose the concrete findings.

2. Fix only concrete review findings
   - Do not make broad refactors.
   - Apply one small fix at a time.
   - After each fix, run the smallest relevant local check.
   - Commit and push each completed chunk.

3. Re-run production-readiness quick checks
   - `docker compose -f docker-compose.yml config`
   - `npm run build --prefix frontend`
   - ML compile: `python -m compileall ml-service/app`
   - Laravel Pint for touched PHP files.

4. End-to-end smoke test after review is green
   - Provision real `.env` values.
   - Start Docker Compose.
   - Run migrations.
   - Create a real admin user.
   - Test login.
   - Test classroom/session lifecycle.
   - Test ML snapshot ingestion with HMAC.
   - Test WebSocket updates.
   - Test Prometheus/Grafana targets.

5. Before production merge/deploy
   - Rotate any secrets that were ever committed in `.env`.
   - Replace self-signed TLS with real certificates.
   - Configure real backup retention and external backup copy.
   - Configure Grafana/Prometheus alerts for CPU/RAM/disk, PostgreSQL, Redis,
     ML latency, queue failures, and service health.

## Context / Files Recently Edited

- `docker-compose.yml`
  - Monitoring exporters, nginx exporter, TLS placeholder generation, and
    production environment variables.
- `docker/nginx/conf.d/engagement.conf`
  - HTTPS, SPA routing, API proxy, WebSocket proxy, Grafana proxy,
    `/nginx_status`.
- `docker/prometheus/prometheus.yml`
  - Prometheus scrape jobs. Removed invalid Laravel `/metrics` target.
- `docker/prometheus/alerts.yml`
  - Empty valid rule file placeholder.
- `docker/nginx/ssl/.gitkeep`
  - Keeps TLS directory present without committing generated certs.
- `.gitignore`
  - Keeps generated TLS files ignored while preserving `.gitkeep`.
- `backend/app/Console/Kernel.php`
  - Removed schedules for commands that are not implemented in the active code.
- `backend/.env.example`
  - Changed from local debug/sqlite defaults to production-style placeholders.
- `backend-our-code/database/seeders/DatabaseSeeder.php`
  - Gated demo seeding with `SEED_DEMO_DATA` and removed fixed `password`.
- PR description has been updated several times, but may need one final update
  after this handover commit if desired.

## Known Blocker

- `Devin Review` status was failing on PR #3, but `git view_pr` did not show
  concrete review comments.
- Opening the review page in browser required sign-in/CLI access and showed
  "2 Potential bugs" in the sidebar, but the details were not accessible in this
  session before handover.
- Next agent should start by reading this file, then inspect Devin Review.
