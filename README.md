# Импорт/экспорт товаров — тестовое задание

Асинхронный импорт товаров из `.xlsx` с фоновой обработкой через RabbitMQ,
поиском и фильтрацией по API, расчётом скидки и веб-интерфейсом на Angular.

## Стек

| Слой | Технологии |
|---|---|
| Backend | PHP 8.4, Slim 4, Doctrine ORM 3 + PostgreSQL 17, Symfony Messenger (RabbitMQ), OpenSpout, Guzzle |
| Инфраструктура | Docker Compose, nginx + PHP-FPM, RabbitMQ 4 |
| Frontend | Angular 21, NgRx Store + Effects, Angular Material, Signals |
| Качество | PHPUnit 13, PHPStan (level max), PHP CS Fixer, GitHub Actions |

## Быстрый старт

```bash
cp .env.example .env       # при необходимости поправьте порты и пароли
make up                    # сборка и запуск: app, db, rabbitmq, worker, frontend, docs
make migrate               # применить миграции
make messenger-setup       # создать exchange и очереди в RabbitMQ
make seed                  # демонстрационные товары, атрибуты, изображения и администратор
```

| Адрес | Назначение |
|---|---|
| `http://localhost:8080` | API и статика фронтенда (nginx) |
| `http://localhost:8080/api/health` | Health check БД и брокера |
| `http://localhost:8081` | Swagger UI |
| `http://localhost:15672` | RabbitMQ UI (`app` / `app_secret`) |
| `http://localhost:5432` | PostgreSQL |

Вход и импорт примера:

```bash
make login                                    # JWT администратора из .env
make import-sample                            # загрузить fixtures/import-example.xlsx
curl -sS http://localhost:8080/api/imports/<job_id>   # прогресс и отчёт
```

Ручной вариант:

```bash
TOKEN=$(curl -sS -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"admin_secret"}' | jq -r .access_token)

curl -sS -X POST http://localhost:8080/api/imports \
  -H "Authorization: Bearer $TOKEN" -F "file=@fixtures/import-example.xlsx"
```

Полезные команды: `make help`, `make logs`, `make worker-logs`,
`make e2e`, `make down`.

## API

Все ответы — JSON в `snake_case`. Списки отдаются в виде
`{ "items": [...], "pagination": { "page", "limit", "total", "pages" } }`.

| Метод | Путь | Описание |
|---|---|---|
| `GET` | `/api/health` | Состояние БД и RabbitMQ |
| `POST` | `/api/auth/login` | Вход: `email` + `password` → JWT (`access_token`) |
| `GET` | `/api/products` | Список товаров: `page`, `limit`, `name`, `price_from`, `price_to` |
| `GET` | `/api/products/{external_code}` | Карточка товара с атрибутами и изображениями |
| `POST` | `/api/imports` | Загрузка `.xlsx` (multipart, поле `file`) → `202` и тело задачи |
| `GET` | `/api/imports/{job_id}` | Состояние и отчёт импорта |

`/api/products*` и `/api/imports*` требуют `Authorization: Bearer <JWT>`,
остальные маршруты публичны. Токен выдаёт `POST /api/auth/login` на время
`JWT_TTL` секунд и хранится во `users` вместе с паролем (bcrypt).

## Аутентификация

Бэкенд: `firebase/php-jwt`, `JWT_SECRET`/`JWT_ALGORITHM`/`JWT_TTL` в `.env`,
`AuthMiddleware` проверяет подпись и срок действия на маршрутах импорта,
`AuthController` отдаёт `access_token` и `expires_in`. Администратор из
`SEED_ADMIN_EMAIL`/`SEED_ADMIN_PASSWORD` создаётся командой `make seed`.

Фронтенд: `AuthService` хранит токен и срок в `localStorage`,
`apiInterceptor` добавляет заголовок `Authorization` и при `401` разлогинивает
с редиректом на `/login`, `authGuard` (`CanActivate`) закрывает `/products*`
и `/import`, `/login` — отдельная lazy-страница Material.

`POST /api/imports` отвечает сразу: файл сохраняется в общий том, задача уходит
в очередь `imports`, дальше состояние опрашивается по `job_id`.
Файл проверяется до постановки в очередь: расширение (`.xlsx`, `.xlsm`), размер
(`IMPORT_MAX_FILE_SIZE`), MIME (`IMPORT_ALLOWED_MIME`) и сигнатура zip — XLSX это
zip-архив, поэтому переименованный `.csv` отклоняется. Частота запросов импорта
ограничена `IMPORT_RATE_LIMIT` запросами в минуту **на IP** (ключ лимита строится
из `REMOTE_ADDR`, который nginx прокидывает в `fastcgi_params`).
Состояния: `pending` → `processing` → `completed` / `failed`.
Отчёт содержит `total`, `imported`, `updated`, `failed`, `duration_seconds`
и список ошибок с номером строки, кодом и сообщением.

Спецификация API пишется руками, контроллеры не содержат аннотаций OpenAPI.
Корневой файл — `docs/openapi.yaml`, остальное разложено по доменам:
`docs/openapi/paths/*.yaml` (по файлу на эндпоинт),
`docs/openapi/components/security-schemes.yaml`, `responses.yaml` и
`schemas/*.yaml` (`auth`, `product`, `import`, `health`, `errors`).
Просмотр — Swagger UI на `http://localhost:8081`.
Актуальность проверяет `OpenApiSpecTest`: собирает спецификацию, разрешает
внешние `$ref` и сверяет её с маршрутами из `src/Core/Bootstrap/Routing.php` —
лишний или забытый путь валит тест.

## Формат импорта

Колонки читаются по индексам (с нуля) в первой строке листа:

| Индекс | Значение |
|---|---|
| 4 | Наименование |
| 5 | Внешний код (артикул) — ключ upsert |
| 8 | Цена продажи |
| 10 | Описание |
| 11 | Закупочная цена |
| 37 | Доп. поле: «Ссылки на фото» |

Колонки с префиксом `Доп. поле: ` сохраняются как атрибуты товара, кроме
«Ссылки на фото» — из них берутся URL изображений. Скидка считается по формуле
`(цена продажи − закупочная цена) / цена продажи × 100` и не уходит в минус.
Строки без кода, без наименования или с нечисловыми суммами попадают в отчёт,
а не прерывают импорт: остальные товары сохраняются.
Повторный импорт того же файла обновляет товары по внешнему коду, не создавая дублей.

## Архитектура

```
backend/src
├── Core/
│   ├── Bootstrap/      AppFactory, Routing (таблица маршрутов), env, контейнер
│   ├── Container/      типизированный доступ к сервисам (ServiceFetcher)
│   ├── Doctrine/       EntityManagerFactory
│   ├── Http/           RequestPayload, JsonResponder, DomainErrorHandler
│   ├── Messenger/      шина, транспорты, ретраи
│   └── RabbitMq/       проверка брокера для health
└── App/
    ├── Console/Command/  app:health, app:seed
    ├── Controller/       HTTP-слой (Slim): только адаптация PSR-7 и вызов use case
    ├── Dto/Api|Auth|Import
    ├── Entity/           Product, ProductAttribute, ProductImage, ImportJob, User
    ├── Exception/        доменные ошибки: 400, 401, 404, 429, 500
    ├── Migrations/       миграции Doctrine
    ├── Repository/       запросы и upsert
    ├── Service/          JwtService и пайплайн импорта (фоновая обработка)
    │   └── Import/       XlsxRowReader, RowNormalizer, ImageDownloader,
    │                    ProductImporter, ImportJobHandler
    └── UseCase/          вся бизнес-логика, по одному handle() на операцию
        ├── Auth/         LoginUseCase, AuthenticateUseCase
        ├── Health/       CheckHealthUseCase
        ├── Import/       CreateImportJobUseCase, GetImportJobUseCase
        └── Product/      ListProductsUseCase, GetProductCardUseCase
```

Правило слоёв: контроллер не содержит логики и кодов статуса — он читает
запрос и отдаёт результат use case. Use case бросает доменное исключение
(`App\Exception`), а `DomainErrorHandler` превращает его в ответ API:
`400/401/404/429/500` с телом `{"error": "..."}`. Ошибки инфраструктуры
(роутинг, middleware) остаются на стандартном обработчике Slim с телом
`{"message": "..."}`.

Пайплайн импорта: `ImportController` → `CreateImportJobUseCase` (лимит запросов,
валидация файла, запись задачи, отправка `ImportJobMessage`) → RabbitMQ →
`ImportJobHandler` воркера → `XlsxRowReader` → `RowNormalizer` →
`ImageDownloader` → `ProductImporter` (транзакция на товар) → отчёт
в `import_jobs`.

Сервисы `app` и `worker` работают из одного образа, загруженные файлы лежат
в общем томе `imports`, изображения — в томе `media`, который отдаётся nginx
по `/media/`.

## Тесты и статический анализ

```bash
make test-unit          # 100 unit-тестов, база не нужна
make test-integration   # CRUD репозитория и пайплайн импорта, нужна БД
make test               # оба набора
make lint               # PHP CS Fixer --dry-run + PHPStan level max
make stan
make cs-fix             # применить форматирование
```

Unit-тесты покрывают HTTP-слой авторизации (парсинг JSON, `401` без токена,
`401` на подделанном токене, выдача JWT), JWT-сервис, разбор XLSX и сопоставление колонок на реальном файле,
валидацию строк, формулу скидки, загрузку изображений (с мок-HTTP-клиентом),
а также приём файла импорта: расширение, размер, MIME, сигнатура zip, коды
ошибок загрузки и лимит запросов (в том числе что лимит считается на IP, а не
на весь сервис).
Интеграционные тесты проверяют CRUD, фильтры, пагинацию, отсутствие N+1,
а также полный импорт: пропуск битых строк, отчёт об ошибках, идемпотентность
повторного импорта и удаление файла после обработки. Недоступная база — это
ошибка окружения: интеграционные тесты падают с подсказкой, а не помечаются
пропущенными, иначе прогон выглядел бы зелёным при нуле проверок.

`make e2e` прогоняет `scripts/smoke.sh` по поднятому стеку (нужен `jq`):
health с проверкой БД и брокера → login → пагинация, поиск по имени и фильтр по
цене → `401` без токена → загрузка XLSX → опрос задачи до `completed` →
согласованность отчёта (`failed` = числу ошибок) → карточка товара и отдача
картинки через nginx → повторный импорт без дублей → `429` при превышении
лимита. В CI этот же скрипт запускается на поднятом `docker compose`
(джоб `e2e`), поэтому поломки в volumes или в очереди ловятся до сдачи.

## Конфигурация

Все переменные описаны в `.env.example`: подключения к БД и RabbitMQ, порты,
лимиты загрузки (`IMPORT_MAX_FILE_SIZE`), допустимые MIME и расширения,
таймаут и число попыток скачивания изображений, лимит запросов импорта
(`IMPORT_RATE_LIMIT`) и путь хранения загрузок.

## Что ещё предстоит

CORS-политика (фронт и API сейчас раздаются с одного домена), экспорт товаров
в `.xlsx`/`.csv` и Playwright e2e для UI.