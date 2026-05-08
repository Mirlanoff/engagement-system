#!/usr/bin/env bash
# ================================================================
#  ENGAGEMENT SYSTEM — Запуск одной командой
#  Использование:
#    ./start.sh           # старт (если уже установлено) или установка
#    ./start.sh stop      # остановить всё
#    ./start.sh restart   # перезапустить
#    ./start.sh status    # что сейчас работает
#    ./start.sh logs      # логи всех сервисов
#    ./start.sh rebuild   # пересобрать только фронтенд после правок
# ================================================================

set -euo pipefail

cd "$(dirname "$0")"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; BLUE='\033[0;34m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC}   $*"; }
info() { echo -e "${BLUE}[..]${NC}   $*"; }
warn() { echo -e "${YELLOW}[!!]${NC}   $*"; }
die()  { echo -e "${RED}[ERR]${NC}  $*"; exit 1; }

# ── 0. Проверки окружения ────────────────────────────────────────
need() { command -v "$1" >/dev/null 2>&1 || die "Не найден '$1'. Установи Docker: curl -fsSL https://get.docker.com | sh"; }
need docker
docker compose version >/dev/null 2>&1 || die "Не найден 'docker compose' v2. Обнови Docker до 25+."

# ── 1. .env: создать из примера и сгенерить случайные пароли ─────
ensure_env() {
  if [ ! -f .env ]; then
    info "Создаю .env из .env.example..."
    cp .env.example .env
  fi

  # Подставляем случайные значения вместо REPLACE_WITH_... (только если они ещё там)

  # APP_KEY генерим через php-cli в одноразовом контейнере
  if grep -qE "^APP_KEY=.*REPLACE" .env; then
    info "Генерирую APP_KEY (Laravel)..."
    local app_key
    app_key=$(docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32));")
    sed -i "s|^APP_KEY=.*|APP_KEY=${app_key}|" .env
    ok "  APP_KEY сгенерирован"
  fi

  _replace() {
    local var="$1" value="$2"
    if grep -qE "^${var}=.*REPLACE" .env; then
      sed -i "s|^${var}=.*|${var}=${value}|" .env
      ok "  ${var} сгенерирован"
    fi
  }

  _replace DB_PASSWORD       "$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
  _replace REDIS_PASSWORD    "$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
  _replace PUSHER_APP_SECRET "$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 40)"
  _replace ML_SERVICE_SECRET "$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 40)"
  _replace GRAFANA_PASSWORD  "$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"
  _replace FLOWER_PASSWORD   "$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"

  # CLAUDE_API_KEY оставляем как есть — он опциональный, рекомендации просто отключатся
  if grep -qE "^CLAUDE_API_KEY=.*REPLACE" .env; then
    sed -i "s|^CLAUDE_API_KEY=.*|CLAUDE_API_KEY=|" .env
    warn "  CLAUDE_API_KEY оставлен пустым (рекомендации Claude отключены — это ок)"
  fi
}

# ── 2. SSL self-signed (один раз) ────────────────────────────────
ensure_ssl() {
  if [ ! -f docker/nginx/ssl/server.crt ]; then
    info "Генерирую self-signed SSL сертификат..."
    mkdir -p docker/nginx/ssl
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
      -keyout docker/nginx/ssl/server.key \
      -out    docker/nginx/ssl/server.crt \
      -subj "/C=KG/ST=Bishkek/O=School/CN=engagement-monitor" \
      -addext "subjectAltName=IP:127.0.0.1,DNS:localhost" \
      >/dev/null 2>&1
    ok "  SSL сертификат создан"
  fi
}

# ── 3. Helper: миграции выполнялись хоть раз? ────────────────────
already_installed() {
  # Если volume postgres_data уже существует — считаем что install уже был
  docker volume inspect engagement-system_postgres_data >/dev/null 2>&1
}

# ── 3b. Сборка фронтенда (vue-builder) ───────────────────────────
# vue-builder лежит в profile=build, поэтому сам не поднимается с
# `docker compose up`. Запускаем вручную, чтобы любые правки в
# frontend/src/ попадали в раздаваемый bundle (иначе nginx отдаёт
# старый кэш и в браузере висят прежние ошибки).
build_frontend() {
  info "Собираю фронтенд (vue-builder)..."
  mkdir -p docker/nginx/html
  docker compose --profile build run --rm vue-builder npm run build
  ok "  фронт собран в docker/nginx/html"
}

# ── 4. Команды ───────────────────────────────────────────────────
do_install() {
  info "Первый запуск — устанавливаю систему..."
  ensure_env
  ensure_ssl

  info "Сборка Docker образов (5-15 минут на первый раз)..."
  docker compose build --parallel

  info "Поднимаю Postgres + Redis..."
  docker compose up -d postgres redis
  sleep 6

  info "Накатываю миграции и сидер..."
  docker compose run --rm laravel php artisan migrate --force --seed
  docker compose run --rm laravel php artisan storage:link

  build_frontend

  info "Запускаю все 14 сервисов (laravel, ml-service, celery-worker, celery-beat, flower, soketi, ...)..."
  docker compose up -d

  ok "Установка завершена"
  show_status
}

do_start() {
  if already_installed; then
    info "Запуск..."
    ensure_env
    ensure_ssl
    build_frontend
    docker compose up -d
    ok "Все сервисы запущены"
    show_status
  else
    do_install
  fi
}

do_rebuild() {
  ensure_env
  build_frontend
  info "Перезапускаю nginx, чтобы подхватил свежий bundle..."
  docker compose restart nginx
  ok "Готово. Освободи кэш браузера (Ctrl-Shift-R) и обнови страницу."
}

do_stop()    { info "Останавливаю все сервисы..."; docker compose stop; ok "Остановлено"; }
do_restart() { do_stop; do_start; }
do_logs()    { docker compose logs -f --tail=100 "${@:-}"; }

show_status() {
  echo
  docker compose ps
  echo
  echo "Открой в браузере:"
  echo "  Дашборд:   https://localhost   (логин: admin@school.local / admin)"
  echo "  Flower:    http://localhost:5555  (Celery — мониторинг очередей)"
  echo "  ML docs:   http://localhost:8001/docs"
  echo "  Grafana:   https://localhost/grafana"
  echo
  echo "Учитель: открыть https://localhost → Войти → нажать «Начать урок»."
  echo "Камера откроется автоматически, аналитика появится во вкладках «Аналитика» / «История»."
}

# ── Диспетчер ────────────────────────────────────────────────────
case "${1:-start}" in
  start|"")  do_start    ;;
  install)   do_install  ;;
  stop)      do_stop     ;;
  restart)   do_restart  ;;
  rebuild)   do_rebuild  ;;
  status)    show_status ;;
  logs)      shift; do_logs "$@" ;;
  *)         echo "Использование: $0 [start|stop|restart|rebuild|status|logs|install]"; exit 1 ;;
esac
