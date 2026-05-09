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
| Flower (Celery) | http://localhost:5555 |
| Prometheus | http://localhost:9090 |
| FastAPI docs (dev) | http://localhost:8001/docs |
| Ollama (LLM) | http://localhost:11434 (внутри сети, не пробрасывается наружу) |

## Локальный AI (Ollama)

AI-рекомендации (еженедельные отчёты по классам, после-урочные сводки)
работают полностью **on-premise** через локальный сервис `ollama`,
который поднимается из `docker-compose.yml`. **Никакие данные не
покидают школьный сервер.**

### Первичная установка модели

После первого `docker compose up -d`:

```bash
# Скачиваем рекомендованную модель (~5 GB, занимает несколько минут)
docker compose exec ollama ollama pull qwen2.5:7b-instruct

# Проверяем, что модель установлена
docker compose exec ollama ollama list
```

### Альтернативные модели

| Модель | Размер | Подходит для |
|---|---|---|
| `qwen2.5:7b-instruct` (по умолчанию) | ~4.7 GB | Лучшее качество JSON, русский язык |
| `llama3.1:8b-instruct` | ~4.7 GB | Хорошая альтернатива, английский лучше русского |
| `qwen2.5:14b-instruct` | ~8.7 GB | Если есть 24+ GB RAM — заметно лучше формулировки |
| `phi3:mini` | ~2.2 GB | Слабый сервер; качество ниже, но запускается на 8 GB RAM |

Чтобы переключиться на другую модель:

```bash
# 1. Скачиваем
docker compose exec ollama ollama pull llama3.1:8b-instruct

# 2. В .env устанавливаем
OLLAMA_MODEL=llama3.1:8b-instruct

# 3. Перезапускаем backend
docker compose restart laravel queue scheduler
```

### Переменные окружения

```dotenv
# URL внутри docker-сети — менять обычно не нужно
OLLAMA_URL=http://ollama:11434

# Активная модель — должна быть pull-нута заранее
OLLAMA_MODEL=qwen2.5:7b-instruct
```

### Расписание еженедельных отчётов

Команда `php artisan recommendations:weekly` запускается планировщиком
каждый понедельник в 08:00 (после-школьный, off-peak слот для тяжёлых
LLM-запросов). Можно запустить вручную:

```bash
docker compose exec laravel php artisan recommendations:weekly
# или для конкретного класса
docker compose exec laravel php artisan recommendations:weekly --classroom=<uuid>
```

Полученный отчёт будет виден в дашборде на вкладке
**Аналитика → AI-инсайты недели** (только для ролей `supervisor`
и `admin`).

## Роли

| Роль | Доступ |
|---|---|
| `teacher` | Активные уроки, веб-камера для своего класса, алерты, история. **Без аналитики и AI-отчётов.** |
| `supervisor` | + полная аналитика (heatmap, сравнение классов, тренды), AI-рекомендации, расшифровка скоринга по студентам. |
| `admin` | Всё, что у supervisor, + админ-панель и сброс дашборда. |

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
