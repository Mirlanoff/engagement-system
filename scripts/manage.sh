#!/usr/bin/env bash
# ================================================================
#  ENGAGEMENT SYSTEM — Скрипт управления
#  Использование: ./scripts/manage.sh [команда]
# ================================================================

set -euo pipefail

COMPOSE="docker compose"
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC}   $1"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error()   { echo -e "${RED}[ERR]${NC}  $1"; exit 1; }

# ── Проверка .env ────────────────────────────────────────────────
check_env() {
    if [ ! -f ".env" ]; then
        log_warn ".env не найден — копирую из .env.example"
        cp .env.example .env
        log_error "Заполни .env перед запуском!"
    fi

    REQUIRED_VARS=(APP_KEY DB_PASSWORD REDIS_PASSWORD PUSHER_APP_SECRET ML_SERVICE_SECRET)
    for var in "${REQUIRED_VARS[@]}"; do
        val=$(grep "^${var}=" .env | cut -d'=' -f2-)
        if [ -z "$val" ] || [[ "$val" == *"REPLACE"* ]]; then
            log_error "Переменная ${var} не заполнена в .env"
        fi
    done
    log_success "Переменные окружения — OK"
}

# ── Генерация SSL сертификата (self-signed) ──────────────────────
gen_ssl() {
    mkdir -p docker/nginx/ssl
    if [ ! -f "docker/nginx/ssl/server.crt" ]; then
        log_info "Генерирую self-signed SSL сертификат..."
        openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
            -keyout docker/nginx/ssl/server.key \
            -out docker/nginx/ssl/server.crt \
            -subj "/C=KG/ST=Bishkek/O=School/CN=engagement-monitor" \
            -addext "subjectAltName=IP:127.0.0.1,DNS:localhost"
        log_success "SSL сертификат создан (10 лет)"
    else
        log_info "SSL сертификат уже существует — пропускаю"
    fi
}

# ── Первоначальная установка ─────────────────────────────────────
install() {
    log_info "=== Первоначальная установка ==="
    check_env
    gen_ssl

    log_info "Сборка Docker образов..."
    $COMPOSE --profile build build --parallel

    log_info "Сборка фронтенда Vue.js..."
    mkdir -p docker/nginx/html
    $COMPOSE --profile build run --rm vue-builder

    log_info "Запуск БД и Redis..."
    $COMPOSE up -d postgres redis
    sleep 5

    log_info "Запуск Laravel миграций..."
    $COMPOSE run --rm laravel php artisan migrate --force --seed

    log_info "Создание storage symlink..."
    $COMPOSE run --rm laravel php artisan storage:link

    log_info "Запуск всех сервисов..."
    $COMPOSE up -d

    log_success "=== Система установлена и запущена ==="
    status
}

# ── Запуск ──────────────────────────────────────────────────────
start() {
    log_info "Запуск системы..."
    $COMPOSE up -d
    log_success "Система запущена"
    status
}

# ── Остановка ───────────────────────────────────────────────────
stop() {
    log_info "Остановка системы..."
    $COMPOSE stop
    log_success "Система остановлена"
}

# ── Перезапуск ──────────────────────────────────────────────────
restart() {
    stop
    start
}

# ── Статус сервисов ─────────────────────────────────────────────
status() {
    echo ""
    $COMPOSE ps
    echo ""
    log_info "Адреса:"
    echo "  Дашборд:   https://localhost"
    echo "  Grafana:   https://localhost/grafana"
    echo "  Flower:    http://localhost:5555"
    echo "  Prometheus: http://localhost:9090"
}

# ── Деплой (pull + rebuild + migrate) ───────────────────────────
deploy() {
    log_info "=== Деплой новой версии ==="
    check_env

    log_info "Получение изменений..."
    git pull origin main

    log_info "Пересборка образов..."
    $COMPOSE --profile build build --parallel laravel ml-service vue-builder

    log_info "Пересборка фронтенда Vue.js..."
    mkdir -p docker/nginx/html
    $COMPOSE --profile build run --rm vue-builder

    log_info "Применение миграций..."
    $COMPOSE run --rm laravel php artisan migrate --force

    log_info "Очистка кэша Laravel..."
    $COMPOSE run --rm laravel php artisan config:cache
    $COMPOSE run --rm laravel php artisan route:cache
    $COMPOSE run --rm laravel php artisan view:cache

    log_info "Перезапуск сервисов..."
    $COMPOSE up -d --force-recreate laravel horizon scheduler celery-worker nginx

    log_success "=== Деплой завершён ==="
    status
}

# ── Логи ────────────────────────────────────────────────────────
logs() {
    SERVICE=${2:-""}
    $COMPOSE logs -f --tail=100 $SERVICE
}

# ── Бэкап БД ────────────────────────────────────────────────────
backup() {
    BACKUP_DIR="./backups"
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    FILENAME="${BACKUP_DIR}/engagement_db_${TIMESTAMP}.sql.gz"
    mkdir -p "$BACKUP_DIR"

    log_info "Создаю бэкап БД → ${FILENAME}"
    $COMPOSE exec -T postgres pg_dump \
        -U "$DB_USERNAME" \
        -d "$DB_DATABASE" \
        | gzip > "$FILENAME"

    # Удаляем бэкапы старше 30 дней
    find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete

    log_success "Бэкап создан: ${FILENAME}"
}

# ── Восстановление БД ───────────────────────────────────────────
restore() {
    FILE=${2:-""}
    [ -z "$FILE" ] && log_error "Укажи файл: ./manage.sh restore backups/file.sql.gz"
    log_warn "ВНИМАНИЕ: текущая БД будет ПЕРЕЗАПИСАНА!"
    read -p "Продолжить? (yes/no): " confirm
    [ "$confirm" != "yes" ] && exit 0

    log_info "Восстанавливаю из ${FILE}..."
    gunzip -c "$FILE" | $COMPOSE exec -T postgres psql \
        -U "$DB_USERNAME" \
        -d "$DB_DATABASE"
    log_success "Восстановление завершено"
}

# ── Artisan команда ─────────────────────────────────────────────
artisan() {
    $COMPOSE run --rm laravel php artisan "${@:2}"
}

# ── Shell в контейнер ───────────────────────────────────────────
shell() {
    SERVICE=${2:-laravel}
    $COMPOSE exec "$SERVICE" sh
}

# ── Тесты ───────────────────────────────────────────────────────
test() {
    log_info "Запуск тестов Laravel..."
    $COMPOSE run --rm laravel php artisan test --parallel

    log_info "Запуск тестов ML сервиса..."
    $COMPOSE run --rm ml-service python -m pytest tests/ -v
}

# ── Помощь ──────────────────────────────────────────────────────
help() {
    echo ""
    echo "Использование: ./scripts/manage.sh [команда]"
    echo ""
    echo "Команды:"
    echo "  install   — первоначальная установка (первый запуск)"
    echo "  start     — запуск всех сервисов"
    echo "  stop      — остановка всех сервисов"
    echo "  restart   — перезапуск"
    echo "  deploy    — деплой новой версии из git"
    echo "  status    — статус сервисов"
    echo "  logs      — логи (./manage.sh logs [сервис])"
    echo "  backup    — бэкап базы данных"
    echo "  restore   — восстановление БД из файла"
    echo "  artisan   — Laravel artisan (./manage.sh artisan migrate)"
    echo "  shell     — shell в контейнер (./manage.sh shell laravel)"
    echo "  test      — запуск тестов"
    echo ""
}

# ── Диспетчер ───────────────────────────────────────────────────
CMD=${1:-help}
case "$CMD" in
    install)  install  ;;
    start)    start    ;;
    stop)     stop     ;;
    restart)  restart  ;;
    deploy)   deploy   ;;
    status)   status   ;;
    logs)     logs "$@"    ;;
    backup)   backup   ;;
    restore)  restore "$@" ;;
    artisan)  artisan "$@" ;;
    shell)    shell "$@"   ;;
    test)     test     ;;
    *)        help     ;;
esac
