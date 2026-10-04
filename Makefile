# =============================================================================
#  Import/Export products — shortcuts для разработки
#  Список целей: make help
# =============================================================================

COMPOSE ?= docker compose
APP      := $(COMPOSE) run --rm app

TESTS_IMAGE ?= sherpa-test-tests:local
TESTS_NET   ?= sherpa-test_app_net

# Тесты гоняются в PHP-контейнере с dev-зависимостями (прод-образ собран без них).
# Исходники монтируются из репозитория, сеть общая с docker-compose, поэтому
# тесты видят те же БД и RabbitMQ, что и приложение.
TESTS_RUN := docker run --rm \
	--network $(TESTS_NET) \
	-v $(PWD)/backend:/app \
	-v $(PWD)/docs:/docs:ro \
	-w /app \
	-e APP_ENV=test \
	-e APP_DEBUG=0 \
	-e DATABASE_URL="$${DATABASE_URL:-pgsql://app:app_secret@db:5432/app}" \
	-e IMPORT_STORAGE_PATH=/app/var/imports \
	-e MEDIA_PATH=/app/var/media \
	-e JWT_SECRET="$${JWT_SECRET:-ci-secret-key-at-least-32-characters-long}" \
	$(TESTS_IMAGE)

.DEFAULT_GOAL := help
.PHONY: help install up down restart build logs ps shell worker worker-logs \
        migrate migrate-status seed fixtures login import-sample \
        messenger-setup messenger-stats \
        dev-up dev-down test tests-build test-unit test-integration lint cs-fix stan e2e clean

## ------------------------------------------------------------------ общее --

help: ## Показать список целей
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

install: ## Создать .env из .env.example (если ещё нет)
	@test -f .env || (cp .env.example .env && echo "создан .env")

up: install ## Поднять весь стек в фоне
	$(COMPOSE) up -d --build
	@$(MAKE) --no-print-directory ps

down: ## Остановить стек (данные и тома сохраняются)
	$(COMPOSE) down

restart: ## Перезапустить стек
	$(COMPOSE) restart

build: ## Пересобрать образы
	$(COMPOSE) build

logs: ## Логи всех сервисов
	$(COMPOSE) logs -f --tail=100

ps: ## Статусы контейнеров и healthcheck
	$(COMPOSE) ps

shell: ## Shell внутри контейнера app
	$(APP) sh

## --------------------------------------------------------------- backend ---

worker: ## Запустить воркер Messenger на переднем плане
	$(APP) php bin/console messenger:consume async --memory-limit=256M \
		--time-limit=3600 --no-interaction

worker-logs: ## Логи воркера
	$(COMPOSE) logs -f --tail=100 worker

migrate: ## Применить миграции
	$(APP) php bin/console migrations:migrate --no-interaction

migrate-status: ## Статус миграций
	$(APP) php bin/console migrations:status

seed: ## Наполнить БД сидерными данными
	$(APP) php bin/console app:seed --no-interaction

messenger-setup: ## Создать exchange/очереди Messenger в RabbitMQ
	$(APP) php bin/console messenger:setup-transports async
	$(APP) php bin/console messenger:setup-transports failed

messenger-stats: ## Показать длины очередей Messenger
	$(APP) php bin/console messenger:stats --

fixtures: ## Скопировать пример .xlsx внутрь контейнера app
	$(COMPOSE) cp fixtures/import-example.xlsx app:/tmp/import-example.xlsx

APP_PORT     ?= $(shell grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')
APP_PORT     ?= 8080
API_PORT     ?= $(APP_PORT)

login: ## Получить JWT администратора из .env (печатает токен)
	@email="$$(grep -E '^SEED_ADMIN_EMAIL=' .env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')"; \
	password="$$(grep -E '^SEED_ADMIN_PASSWORD=' .env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')"; \
	response="$$(curl -fsS -X POST "http://localhost:$(APP_PORT)/api/auth/login" \
		-H 'Content-Type: application/json' \
		-d "{\"email\":\"$${email:-admin@example.com}\",\"password\":\"$${password:-admin_secret}\"}")"; \
	echo "$$response" >&2; \
	printf '%s' "$$response" | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p'

import-sample: ## Загрузить fixtures/import-example.xlsx в API (токен берётся из make login)
	@TOKEN="$$($(MAKE) --no-print-directory login 2>/dev/null | tail -1)"; \
	test -n "$$TOKEN" || (echo "не удалось получить токен, проверьте make seed" >&2 && exit 1); \
	echo "используем токен $${TOKEN:0:16}..." >&2; \
	curl -sS -X POST "http://localhost:$(APP_PORT)/api/imports" \
		-H "Authorization: Bearer $$TOKEN" \
		-F "file=@fixtures/import-example.xlsx" >&2

## --------------------------------------------------------------- frontend --

dev-up: ## Поднять стек с bind-mount кода (изменения без пересборки)
	$(COMPOSE) -f docker-compose.yml -f docker-compose.dev.yml up -d
	@$(MAKE) --no-print-directory ps

dev-down: ## Остановить dev-стек
	$(COMPOSE) -f docker-compose.yml -f docker-compose.dev.yml down

## ----------------------------------------------------------------- тесты ---

tests-build: ## Собрать образ для тестов (PHP + dev-зависимости)
	docker build -f docker/php/Dockerfile --target test -t $(TESTS_IMAGE) .

test: ## Все backend-тесты (unit + integration)
	@$(MAKE) --no-print-directory tests-build
	$(TESTS_RUN) php vendor/bin/phpunit --testdox

test-unit: ## Только unit-тесты (без БД)
	@$(MAKE) --no-print-directory tests-build
	$(TESTS_RUN) php vendor/bin/phpunit --testsuite unit --testdox

test-integration: ## Интеграционные тесты: CRUD репозитория и пайплайн импорта
	@$(MAKE) --no-print-directory tests-build
	$(TESTS_RUN) php vendor/bin/phpunit --testsuite integration --testdox

lint: ## Проверка стиля (php-cs-fixer --dry-run) + PHPStan
	@$(MAKE) --no-print-directory tests-build
	$(TESTS_RUN) php vendor/bin/php-cs-fixer check --diff --using-cache=no
	$(TESTS_RUN) php vendor/bin/phpstan analyse --no-progress

cs-fix: ## Автоформатирование кода
	@$(MAKE) --no-print-directory tests-build
	$(TESTS_RUN) php vendor/bin/php-cs-fixer fix --using-cache=no

stan: ## Только PHPStan
	@$(MAKE) --no-print-directory tests-build
	$(TESTS_RUN) php vendor/bin/phpstan analyse --no-progress

e2e: ## E2E smoke по поднятому стеку: health, login, товары, импорт XLSX
	sh scripts/smoke.sh

## ----------------------------------------------------------------- уборка --

clean: ## Остановить стек и удалить тома (БД и медиа пропадут!)
	$(COMPOSE) down -v
