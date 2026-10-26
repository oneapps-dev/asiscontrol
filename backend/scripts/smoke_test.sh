#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000/api}"
EMAIL="${ASISCONTROL_EMAIL:-guardia@asiscontrol.local}"
PASSWORD="${ASISCONTROL_PASSWORD:-demo1234}"
COOKIE_JAR="$(mktemp)"
LOGIN_JSON="$(mktemp)"

cleanup() {
  rm -f "$COOKIE_JAR" "$LOGIN_JSON"
}
trap cleanup EXIT

curl -fsS "$BASE_URL/health" >/dev/null

curl -fsS \
  -c "$COOKIE_JAR" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" \
  "$BASE_URL/auth/login" > "$LOGIN_JSON"

CSRF_TOKEN="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["csrf_token"] ?? "";' "$LOGIN_JSON")"

if [ -z "$CSRF_TOKEN" ]; then
  echo "Missing CSRF token after login" >&2
  exit 1
fi

curl -fsS -b "$COOKIE_JAR" "$BASE_URL/bootstrap" >/dev/null
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/reports/summary" >/dev/null
curl -fsS -b "$COOKIE_JAR" -H "X-CSRF-Token: $CSRF_TOKEN" -X POST "$BASE_URL/auth/logout" >/dev/null

echo "Asiscontrol API smoke test passed."
