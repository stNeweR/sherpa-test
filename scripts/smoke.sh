#!/usr/bin/env sh
# E2E-проверка поднятого стека: health → login → товары (пагинация, поиск, цена)
# → импорт XLSX → отчёт → повторный импорт без дублей → картинка → лимит запросов.
set -eu

APP_PORT="${APP_PORT:-$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')}"
APP_PORT="${APP_PORT:-8080}"
BASE_URL="http://localhost:${APP_PORT}"

EMAIL="${SEED_ADMIN_EMAIL:-$(grep -E '^SEED_ADMIN_EMAIL=' .env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')}"
EMAIL="${EMAIL:-admin@example.com}"
PASSWORD="${SEED_ADMIN_PASSWORD:-$(grep -E '^SEED_ADMIN_PASSWORD=' .env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')}"
PASSWORD="${PASSWORD:-admin_secret}"
FIXTURE="${1:-fixtures/import-example.xlsx}"
RATE_LIMIT="${IMPORT_RATE_LIMIT:-$(grep -E '^IMPORT_RATE_LIMIT=' .env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')}"
RATE_LIMIT="${RATE_LIMIT:-5}"

fail() {
	echo "FAIL: $1" >&2
	exit 1
}

info() {
	echo "  -> $1"
}

command -v jq > /dev/null 2>&1 || fail "нужен jq для проверки JSON-ответов"

api() {
	curl -fsS -H "Authorization: Bearer ${TOKEN}" "$@"
}

info "health $BASE_URL/api/health"
HEALTH="$(curl -fsS "${BASE_URL}/api/health")" || fail "health endpoint недоступен"
[ "$(printf '%s' "${HEALTH}" | jq -r '.database.status')" = "ok" ] \
	|| fail "БД недоступна по /api/health: ${HEALTH}"
[ "$(printf '%s' "${HEALTH}" | jq -r '.broker.status')" = "ok" ] \
	|| fail "RabbitMQ недоступен по /api/health: ${HEALTH}"

info "login ${EMAIL}"
TOKEN="$(curl -fsS -X POST "${BASE_URL}/api/auth/login" \
	-H 'Content-Type: application/json' \
	-d "{\"email\":\"${EMAIL}\",\"password\":\"${PASSWORD}\"}" \
	| jq -r '.access_token // empty')"

[ -n "${TOKEN}" ] || fail "не удалось получить access_token (запустите make seed)"

info "GET /api/products: пагинация"
PAGE="$(api "${BASE_URL}/api/products?page=1&limit=5")"
ITEMS="$(printf '%s' "${PAGE}" | jq -r '.items | length')"
[ "${ITEMS}" -le 5 ] || fail "ожидалось не больше 5 товаров, получено ${ITEMS}"
TOTAL_BEFORE="$(printf '%s' "${PAGE}" | jq -r '.pagination.total')"
[ "$(printf '%s' "${PAGE}" | jq -r '.pagination.page')" = "1" ] || fail "пагинация вернула неверный page"

info "GET /api/products: поиск по name"
TERM="$(printf '%s' "${PAGE}" | jq -r '.items[0].name // empty | .[0:12]')"
if [ -n "${TERM}" ]; then
	FOUND="$(api --get --data-urlencode "name=${TERM}" "${BASE_URL}/api/products?limit=100" | jq -r '.items | length')"
	[ "${FOUND}" -ge 1 ] || fail "поиск по «${TERM}» не нашёл товар, который есть в выдаче"
	MISSED="$(api --get --data-urlencode "name=${TERM}" "${BASE_URL}/api/products?limit=100" \
		| jq -r --arg term "${TERM}" '[.items[] | select(.name | ascii_downcase | contains($term | ascii_downcase))] | length')"
	[ "${MISSED}" = "${FOUND}" ] || fail "поиск вернул товары без «${TERM}» в названии"
fi

info "GET /api/products: диапазон price"
PRICE="$(printf '%s' "${PAGE}" | jq -r '.items[0].price // empty')"
if [ -n "${PRICE}" ]; then
	BELOW="$(api "${BASE_URL}/api/products?price_from=${PRICE}&limit=100" \
		| jq -r --arg price "${PRICE}" '[.items[] | select((.price | tonumber) < ($price | tonumber))] | length')"
	[ "${BELOW}" = "0" ] || fail "price_from=${PRICE} вернул ${BELOW} товаров дешевле"
fi

info "import без токена должен вернуть 401"
STATUS="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/api/imports" \
	-F "file=@${FIXTURE}")"
[ "${STATUS}" = "401" ] || fail "ожидался 401 без токена, получен ${STATUS}"

info "импорт ${FIXTURE}"
JOB_ID="$(curl -fsS -X POST "${BASE_URL}/api/imports" \
	-H "Authorization: Bearer ${TOKEN}" \
	-F "file=@${FIXTURE}" \
	| jq -r '.job_id // empty')"

[ -n "${JOB_ID}" ] || fail "импорт не вернул id задачи"

info "ожидание завершения задачи ${JOB_ID}"
ATTEMPT=0
while [ "${ATTEMPT}" -lt 90 ]; do
	STATUS_JSON="$(api "${BASE_URL}/api/imports/${JOB_ID}")"
	STATE="$(printf '%s' "${STATUS_JSON}" | jq -r '.state')"

	case "${STATE}" in
	completed)
		printf '%s' "${STATUS_JSON}" | jq -c '{job_id, state, processed, imported, updated, failed, report: {total: .report.total, errors: (.report.errors | length)}}'
		break
		;;
	failed)
		printf '%s\n' "${STATUS_JSON}"
		fail "задача импорта завершилась с ошибкой"
		;;
	esac

	ATTEMPT=$((ATTEMPT + 1))
	sleep 2
done

[ "${STATE}" = "completed" ] || fail "задача ${JOB_ID} не завершилась за отведённое время (state: ${STATE:-unknown})"

info "проверка отчёта импорта"
[ "$(printf '%s' "${STATUS_JSON}" | jq -r '.processed')" -gt 0 ] || fail "processed = 0, товары не импортированы"
[ "$(printf '%s' "${STATUS_JSON}" | jq -r '.report.total')" -gt 0 ] || fail "report.total = 0"
ERRORS="$(printf '%s' "${STATUS_JSON}" | jq -r '.report.errors | length')"
[ "$(printf '%s' "${STATUS_JSON}" | jq -r '.failed')" = "${ERRORS}" ] \
	|| fail "failed не совпадает с числом ошибок в отчёте"
if [ "${ERRORS}" -gt 0 ]; then
	printf '%s' "${STATUS_JSON}" | jq -r '.report.errors[].code' | sort | uniq -c | sed 's/^/     /'
fi

info "товары из импорта доступны в списке и в карточке"
CODE="$(printf '%s' "${STATUS_JSON}" | jq -r '.report.errors[0].context.external_code // empty')"
CARD="$(api "${BASE_URL}/api/products?limit=1")"
FIRST_CODE="$(printf '%s' "${CARD}" | jq -r '.items[0].external_code // empty')"
[ -n "${FIRST_CODE}" ] || fail "после импорта список товаров пуст"
DETAIL="$(api "${BASE_URL}/api/products/${FIRST_CODE}")"
[ "$(printf '%s' "${DETAIL}" | jq -r '.external_code')" = "${FIRST_CODE}" ] \
	|| fail "карточка товара вернула другой external_code"

info "изображение отдаётся nginx"
MEDIA_PATH="$(printf '%s' "${DETAIL}" | jq -r '[.images[]?.path] | map(select(. != null)) | .[0] // empty')"
if [ -n "${MEDIA_PATH}" ]; then
	STATUS="$(curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}${MEDIA_PATH}")"
	[ "${STATUS}" = "200" ] || fail "изображение ${MEDIA_PATH} вернуло ${STATUS}"
else
	echo "     у товара нет скачанных картинок — проверка /media пропущена"
fi

info "повторный импорт не создаёт дублей"
SECOND_ID="$(curl -fsS -X POST "${BASE_URL}/api/imports" \
	-H "Authorization: Bearer ${TOKEN}" \
	-F "file=@${FIXTURE}" \
	| jq -r '.job_id // empty')"
[ -n "${SECOND_ID}" ] || fail "повторный импорт не вернул id задачи"

ATTEMPT=0
while [ "${ATTEMPT}" -lt 90 ]; do
	SECOND_JSON="$(api "${BASE_URL}/api/imports/${SECOND_ID}")"
	SECOND_STATE="$(printf '%s' "${SECOND_JSON}" | jq -r '.state')"

	case "${SECOND_STATE}" in
	completed)
		break
		;;
	failed)
		fail "повторный импорт завершился с ошибкой"
		;;
	esac

	ATTEMPT=$((ATTEMPT + 1))
	sleep 2
done

[ "${SECOND_STATE}" = "completed" ] || fail "повторный импорт не завершился (state: ${SECOND_STATE:-unknown})"
[ "$(printf '%s' "${SECOND_JSON}" | jq -r '.imported')" = "0" ] \
	|| fail "повторный импорт создал новые товары вместо обновления"
[ "$(printf '%s' "${SECOND_JSON}" | jq -r '.updated')" -gt 0 ] \
	|| fail "повторный импорт ничего не обновил"

TOTAL_AFTER="$(api "${BASE_URL}/api/products?page=1&limit=1" | jq -r '.pagination.total')"
[ "${TOTAL_AFTER}" = "${TOTAL_BEFORE}" ] \
	|| fail "после повторного импорта товаров ${TOTAL_BEFORE}, стало ${TOTAL_AFTER} — upsiet не сработал"

info "лимит запросов импорта (${RATE_LIMIT} в минуту на IP)"
SEEN_429=0
ATTEMPT=0
while [ "${ATTEMPT}" -lt $((RATE_LIMIT + 3)) ]; do
	STATUS="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/api/imports" \
		-H "Authorization: Bearer ${TOKEN}" \
		-F "file=@${FIXTURE}")"
	if [ "${STATUS}" = "429" ]; then
		SEEN_429=1
		break
	fi
	ATTEMPT=$((ATTEMPT + 1))
done
[ "${SEEN_429}" = "1" ] || fail "лимит импорта не сработал за $((RATE_LIMIT + 3)) запросов"

echo "OK: стенд проходит e2e-проверку"