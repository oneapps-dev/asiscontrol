#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
curl -fsS -c /tmp/owner.cookies -H 'Content-Type: application/json' \
  -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
  "$base/auth/login" >/tmp/owner-login.json
owner_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/owner-login.json"))["csrf_token"])')
curl -fsS -b /tmp/owner.cookies "$base/platform/impersonation/context" > /tmp/preview-initial.json
python3 - <<'PY'
import json
assert json.load(open('/tmp/preview-initial.json'))['active'] is False
PY

curl -fsS -c /tmp/preview-guard.cookies -H 'Content-Type: application/json' \
  -d '{"email":"guardia@asiscontrol.local","password":"demo1234"}' \
  "$base/auth/login" > /tmp/preview-guard-login.json
guard_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/preview-guard-login.json"))["csrf_token"])')
status=$(curl -sS -o /tmp/guard-start-denied.json -w '%{http_code}' \
  -b /tmp/preview-guard.cookies -H "X-CSRF-Token: $guard_csrf" \
  -H 'Content-Type: application/json' -d '{"tenant_id":1,"user_id":2,"reason":"Intento no autorizado"}' \
  "$base/platform/impersonation/start")
test "$status" = "403" || { echo "Guard attempted preview: $status"; exit 1; }

status=$(curl -sS -o /tmp/preview-start.json -w '%{http_code}' \
  -b /tmp/owner.cookies -H "X-CSRF-Token: $owner_csrf" \
  -H 'Content-Type: application/json' -d '{"tenant_id":1,"user_id":2,"reason":"Verificar permisos de vigilante en QA"}' \
  "$base/platform/impersonation/start")
test "$status" = "201" || { cat /tmp/preview-start.json; echo "Preview start status $status"; exit 1; }
curl -fsS -b /tmp/owner.cookies "$base/platform/impersonation/context" > /tmp/preview-context.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/preview-context.json'))
assert d['active'] is True
x=d['data']
assert x['actor_user_id']==1 and x['tenant_id']==1 and x['target_user_id']==2
assert x['role']=='guard' and x['read_only'] is True
assert isinstance(x['metrics']['people_inside'],int)
assert x['scope']=='sites'
PY
# PHP session identity is never swapped to the target.
curl -fsS -b /tmp/owner.cookies "$base/platform/me" > /tmp/owner-while-preview.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/owner-while-preview.json'))['data']
assert d['user']['id']==1 and d['platform_admin'] is True
PY
# A state-changing API request is denied even with a valid CSRF token.
status=$(curl -sS -o /tmp/preview-write-denied.json -w '%{http_code}' \
  -b /tmp/owner.cookies -H "X-CSRF-Token: $owner_csrf" \
  -H 'Content-Type: application/json' -d '{"status":"inactive"}' \
  "$base/platform/tenants/1/status")
test "$status" = "403" || { echo "Preview mutation status $status"; exit 1; }
grep -q PREVIEW_READ_ONLY /tmp/preview-write-denied.json
mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT status FROM tenants WHERE id=1" | grep -q '^active$'

status=$(curl -sS -o /tmp/preview-stop.json -w '%{http_code}' \
  -b /tmp/owner.cookies -H "X-CSRF-Token: $owner_csrf" \
  -H 'Content-Type: application/json' -d '{}' \
  "$base/platform/impersonation/stop")
test "$status" = "200" || { echo "Stop preview $status"; exit 1; }
curl -fsS -b /tmp/owner.cookies "$base/platform/impersonation/context" > /tmp/preview-stopped.json
python3 - <<'PY'
import json
assert json.load(open('/tmp/preview-stopped.json'))['active'] is False
PY
curl -fsS -b /tmp/owner.cookies "$base/platform/impersonation/audit" > /tmp/preview-audit.json
python3 - <<'PY'
import json
rows=json.load(open('/tmp/preview-audit.json'))['data']
assert any(row['actor_user_id']==1 and row['target_user_id']==2 and row['status']=='stopped' for row in rows)
PY
# A target from a different tenant must never be accepted.
status=$(curl -sS -o /tmp/preview-mismatch-denied.json -w '%{http_code}' \
  -b /tmp/owner.cookies -H "X-CSRF-Token: $owner_csrf" \
  -H 'Content-Type: application/json' -d '{"tenant_id":3,"user_id":2,"reason":"Prueba de aislamiento entre clientes"}' \
  "$base/platform/impersonation/start")
test "$status" = "403" || { echo "Preview cross-tenant status $status"; exit 1; }
echo 'CREAMOX preview: authorization, read-only, audit, identity isolation PASS'
