# Student Engagement Monitoring System

Система мониторинга вовлечённости студентов в реальном времени.  
On-premise деплой на школьном сервере.

## Стек

| Слой | Технологии |
|---|---|
| ML анализ | Python 3.11, FastAPI, OpenCV, MediaPipe, DeepFace, Celery |
| Бэкенд | Laravel 11, PostgreSQL 16, Redis 7, Soketi |
| Фронтенд | Vue 3, Pinia, Chart.js, Tailwind CSS |
| Инфраструктура | Docker Compose, Nginx, Prometheus, Grafana |

## Быстрый старт — одна команда

```bash
git clone <repo-url> engagement-system && cd engagement-system
./start.sh
```

Всё. Скрипт сам:

1. создаст `.env` из `.env.example`,
2. сгенерирует все пароли и `APP_KEY`,
3. сгенерирует self-signed SSL сертификат,
4. соберёт Docker образы,
5. накатит миграции и сидеры в Postgres,
6. поднимет все 14 сервисов (Laravel + Vue + ML + **Celery worker/beat/flower** + Redis + Soketi + Postgres + Grafana + Prometheus + Nginx + Horizon + Scheduler).

Дальше учитель просто открывает **https://localhost** в браузере, логинится и нажимает «Начать урок» — камера откроется автоматически, аналитика появится во вкладках «Аналитика» и «История».

### Управление

```bash
./start.sh           # запуск (либо первичная установка)
./start.sh stop      # остановить
./start.sh restart   # перезапустить
./start.sh status    # статус всех сервисов
./start.sh logs      # все логи
./start.sh logs celery-worker   # только Celery
```

Или через `make`:

```bash
make up        # запустить
make down      # остановить
make logs      # логи
make status    # статус
```

### Требования к серверу

- Ubuntu 22.04+ / Debian 12+
- Docker Engine 25+ и Docker Compose v2 (`curl -fsSL https://get.docker.com | sh`)
- CPU: 8+ ядер · RAM: 16 GB+ · SSD: 100 GB+

### Дополнительно — `scripts/manage.sh`

Расширенные операции (бэкап БД, восстановление, тесты, артизан, shell в контейнер):

```bash
./scripts/manage.sh backup        # бэкап Postgres → ./backups/
./scripts/manage.sh restore <f>   # восстановление из бэкапа
./scripts/manage.sh artisan migrate
./scripts/manage.sh shell laravel
./scripts/manage.sh test
```

## Адреса после запуска

| Сервис | Адрес |
|---|---|
| Дашборд | https://localhost |
| Grafana | https://localhost/grafana |
| Flower (Celery) | http://localhost:5555 |
| Prometheus | http://localhost:9090 |
| FastAPI docs (dev) | http://localhost:8001/docs |

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
