# Student Engagement Monitoring System

Система мониторинга вовлечённости студентов в реальном времени.  
On-premise деплой на школьном сервере.

## Production readiness

Текущий статус: **MVP / pre-production**. Стек поднимается через Docker Compose и имеет базовую изоляцию сервисов, очереди, WebSocket, ML-service и мониторинг, но перед реальной школой нужно закрыть P0/P1 checklist ниже.

## Стек

| Слой | Технологии |
|---|---|
| ML анализ | Python 3.11, FastAPI, OpenCV, MediaPipe, DeepFace, Celery |
| Бэкенд | Laravel 11, PostgreSQL 16, Redis 7, Soketi |
| Фронтенд | Vue 3, Pinia, Chart.js, Tailwind CSS |
| Инфраструктура | Docker Compose, Nginx, Prometheus, Grafana |

## Быстрый старт

### 1. Требования к серверу

- Ubuntu 22.04+ или Debian 12+
- Docker Engine 25+ и Docker Compose v2
- CPU: 8+ ядер (ML обработка без GPU)
- RAM: 16 GB минимум
- SSD: 100 GB+

### 2. Установка Docker

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
newgrp docker
```

### 3. Клонирование и настройка

```bash
git clone <repo-url> engagement-system
cd engagement-system

# Настройка окружения
cp .env.example .env
nano .env  # заполни все REPLACE_WITH_... значения

# Генерация APP_KEY для Laravel
docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32));"
```

Перед production запуском:
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<домен или IP сервера>`
- сгенерируй уникальные `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `PUSHER_APP_SECRET`, `ML_SERVICE_SECRET`, `GRAFANA_PASSWORD`, `FLOWER_PASSWORD`
- `SEED_DEMO_DATA=false` для реальной школы; `true` только для демо/стейджинга
- `ML_EXPOSE_DOCS=false` в production
- используй реальный TLS сертификат вместо self-signed из `scripts/manage.sh`, если сервер доступен по домену

### 4. Первый запуск

```bash
chmod +x scripts/manage.sh
./scripts/manage.sh install
```

Это автоматически:
- Сгенерирует SSL сертификат
- Соберёт все Docker образы
- Запустит БД и применит миграции
- Поднимет все сервисы

Для production запуска без dev override используй:

```bash
docker compose -f docker-compose.yml up -d
```

### 5. Управление системой

```bash
./scripts/manage.sh start    # запуск
./scripts/manage.sh stop     # остановка
./scripts/manage.sh status   # статус сервисов
./scripts/manage.sh logs     # все логи
./scripts/manage.sh logs ml-service   # логи ML сервиса
./scripts/manage.sh backup   # бэкап БД
./scripts/manage.sh deploy   # деплой новой версии
```

## Адреса после запуска

| Сервис | Адрес |
|---|---|
| Дашборд | https://localhost |
| Grafana | https://localhost/grafana |
| Flower (Celery, local only) | http://127.0.0.1:5556 |
| Prometheus (local only) | http://127.0.0.1:9090 |
| FastAPI docs (dev only) | http://localhost:8001/docs |

## Production checklist

### P0 — блокирует production

- Заполнить `.env` реальными секретами и не коммитить его в git.
- Убрать тестовые аккаунты/демо-данные: `SEED_DEMO_DATA=false`; создать реальных пользователей отдельной admin-командой или через защищённый seed.
- Поставить реальный TLS сертификат и домен/IP в `APP_URL`.
- Проверить физический доступ к RTSP камерам, согласия на обработку видео и сетевую сегментацию камер.
- Подключить `CLAUDE_API_KEY`, если AI-рекомендации нужны в production; иначе отключить генерацию рекомендаций на уровне бизнес-процесса.
- Настроить backup retention и внешнее копирование `./backups/` за пределы сервера.

### P1 — нужно перед пилотом

- Настроить Grafana dashboards/provisioning под реальные метрики.
- Добавить CI для Laravel tests/Pint, Vue build и Docker Compose config validation.
- Добавить smoke-тесты API: login, classrooms, session lifecycle, internal ML snapshots.
- Настроить алерты Prometheus/Grafana по CPU/RAM/disk, Redis, PostgreSQL, ML latency и ошибкам очередей.
- Проверить WebSocket broadcast на реальном домене через `/app`.

### P2 — после пилота

- Ротация и архивирование логов.
- Регламент обновлений моделей ML и rollback.
- Документация для школьного администратора: пользователи, камеры, бэкапы, восстановление.

## Структура проекта

```
engagement-system/
├── docker-compose.yml          # Основной стек
├── docker-compose.override.yml # Настройки для разработки
├── .env.example                # Шаблон переменных
├── scripts/
│   └── manage.sh               # Скрипт управления
├── docker/
│   ├── nginx/conf.d/           # Nginx конфиг
│   ├── postgres/               # PostgreSQL конфиг + init.sql
│   ├── prometheus/             # Prometheus конфиг
│   └── grafana/                # Grafana дашборды
├── backend/                    # Laravel приложение
├── frontend/                   # Vue.js приложение
└── ml-service/                 # Python ML сервис
```

## Мониторинг

Grafana дашборды включают:
- Engagement score по классам в реальном времени
- Нагрузка на ML сервис (FPS, latency)
- PostgreSQL метрики (connections, query time)
- Redis метрики (memory, hit rate)
- Системные метрики (CPU, RAM, disk)

## Бэкапы

Автоматические бэкапы через Laravel Scheduler:
- Ежедневно в 02:00 — бэкап PostgreSQL
- Хранение: 30 дней
- Папка: `./backups/`

Ручной бэкап: `./scripts/manage.sh backup`
