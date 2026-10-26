#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
curl -fsS -c /tmp/offline-isolation.cookies -H 'Content-Type: application/json' \
  -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
  "$base/auth/login" >/tmp/offline-isolation-login.json
actor_id=$(python3 -c 'import json;print(json.load(open("/tmp/offline-isolation-login.json"))["user"]["id"])')
tenant_id=$(python3 -c 'import json;print(json.load(open("/tmp/offline-isolation-login.json"))["user"]["tenant_id"])')
csrf=$(python3 -c 'import json;print(json.load(open("/tmp/offline-isolation-login.json"))["csrf_token"])')
wrong_tenant=$((tenant_id+987654))
wrong_actor=$((actor_id+987654))

status=$(curl -sS -o /tmp/offline-tenant-denied.json -w '%{http_code}' \
 -b /tmp/offline-isolation.cookies -H "X-CSRF-Token: $csrf" \
 -H 'Content-Type: application/json' \
 -d "{\"tenant_id\":$wrong_tenant,\"actor_user_id\":$actor_id,\"event_uuid\":\"8e132b89-5c1a-4c1f-a30d-a11aba01c001\",\"idempotency_key\":\"QA_OFFLINE_CONTEXT_WRONG_TENANT\",\"action\":\"qa_probe\"}" \
 "$base/offline/events")
test "$status" = "403" || { echo "Offline cross-tenant origin accepted: $status"; cat /tmp/offline-tenant-denied.json; exit 1; }
grep -q OFFLINE_CONTEXT_MISMATCH /tmp/offline-tenant-denied.json

status=$(curl -sS -o /tmp/offline-actor-denied.json -w '%{http_code}' \
 -b /tmp/offline-isolation.cookies -H "X-CSRF-Token: $csrf" \
 -H 'Content-Type: application/json' \
 -d "{\"tenant_id\":$tenant_id,\"actor_user_id\":$wrong_actor,\"event_uuid\":\"8e132b89-5c1a-4c1f-a30d-a11aba01c002\",\"idempotency_key\":\"QA_OFFLINE_CONTEXT_WRONG_ACTOR\",\"action\":\"qa_probe\"}" \
 "$base/offline/events")
test "$status" = "403" || { echo "Offline cross-actor origin accepted: $status"; cat /tmp/offline-actor-denied.json; exit 1; }
grep -q OFFLINE_CONTEXT_MISMATCH /tmp/offline-actor-denied.json

mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT COUNT(*) FROM offline_queue WHERE idempotency_key IN ('QA_OFFLINE_CONTEXT_WRONG_ACTOR','QA_OFFLINE_CONTEXT_WRONG_TENANT')" | grep -q '^0$'

status=$(curl -sS -o /tmp/offline-same-context.json -w '%{http_code}' \
 -b /tmp/offline-isolation.cookies -H "X-CSRF-Token: $csrf" \
 -H 'Content-Type: application/json' \
 -d "{\"tenant_id\":$tenant_id,\"actor_user_id\":$actor_id,\"event_uuid\":\"8e132b89-5c1a-4c1f-a30d-a11aba01c003\",\"idempotency_key\":\"QA_OFFLINE_CONTEXT_VALID\",\"action\":\"qa_probe\"}" \
 "$base/offline/events")
test "$status" = "202" || { echo "Same-context offline envelope rejected: $status"; cat /tmp/offline-same-context.json; exit 1; }

mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT COUNT(*) FROM offline_queue WHERE tenant_id=$tenant_id AND idempotency_key='QA_OFFLINE_CONTEXT_VALID'" | grep -q '^1$'

status=$(curl -sS -o /tmp/offline-same-context-repeat.json -w '%{http_code}' \
 -b /tmp/offline-isolation.cookies -H "X-CSRF-Token: $csrf" \
 -H 'Content-Type: application/json' \
 -d "{\"tenant_id\":$tenant_id,\"actor_user_id\":$actor_id,\"event_uuid\":\"8e132b89-5c1a-4c1f-a30d-a11aba01c003\",\"idempotency_key\":\"QA_OFFLINE_CONTEXT_VALID\",\"action\":\"qa_probe\"}" \
 "$base/offline/events")
test "$status" = "200" || { echo "Idempotent retry changed response: $status"; exit 1; }
grep -q '"duplicate":true' /tmp/offline-same-context-repeat.json
echo 'Offline context: cross-tenant and cross-actor denial, same-context idempotency PASS'
