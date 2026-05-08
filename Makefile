# ================================================================
#  ENGAGEMENT SYSTEM — короткие команды
#  Использование: make up | make down | make logs
# ================================================================

.PHONY: up start stop down restart status logs ps install help

# По умолчанию — запуск
up start: ## Запустить всю систему (при первом запуске — установка)
	./start.sh start

stop down: ## Остановить все сервисы
	./start.sh stop

restart: ## Перезапустить
	./start.sh restart

status ps: ## Показать статус сервисов
	./start.sh status

logs: ## Логи всех сервисов (Ctrl-C для выхода)
	./start.sh logs

install: ## Полная переустановка с миграциями
	./start.sh install

help: ## Показать эту справку
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'
