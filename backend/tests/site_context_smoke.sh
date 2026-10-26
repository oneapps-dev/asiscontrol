#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
site2=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM sites WHERE tenant_id=1 AND name='QA Sede Restringida' LIMIT 1")
test -n "$site2"

curl -fsS -c /tmp/context-guard.cookies -H 'Content-Type: application/json' \
  -d '{"email":"guardia@asiscontrol.local","password":"demo1234"}' \
  "$base/auth/login" > /tmp/context-guard-login.json
guard_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/context-guard-login.json"))["csrf_token"])')
status=$(curl -sS -o /tmp/context-guard-denied.json -w '%{http_code}' \
  -b /tmp/context-guard.cookies -H "X-CSRF-Token: $guard_csrf" \
  -H 'Content-Type: application/json' -d "{\"site_id\":$site2}" \
  "$base/me/context/select")
test "$status" = "403" || { echo "Guard selected an unauthorized site: $status"; exit 1; }
grep -q SCOPE_DENIED /tmp/context-guard-denied.json

curl -fsS -c /tmp/context-admin.cookies -H 'Content-Type: application/json' \
  -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
  "$base/auth/login" > /tmp/context-admin-login.json
admin_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/context-admin-login.json"))["csrf_token"])')
curl -fsS -b /tmp/context-admin.cookies "$base/me/contexts" > /tmp/available-sites.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/available-sites.json'))['data']
assert d['scope']=='tenant' and d['selected_site_id'] is None
assert any(x['name']=='QA Sede Restringida' for x in d['sites'])
PY

status=$(curl -sS -o /tmp/context-selected.json -w '%{http_code}' \
  -b /tmp/context-admin.cookies -H "X-CSRF-Token: $admin_csrf" \
  -H 'Content-Type: application/json' -d "{\"site_id\":$site2}" \
  "$base/me/context/select")
test "$status" = "200" || { echo "Allowed site selection failed: $status"; cat /tmp/context-selected.json; exit 1; }

# A selected site must not permit tenant-wide user enumeration or identity changes.
user_status=$(curl -sS -o /tmp/site-users-blocked.json -w '%{http_code}' -b /tmp/context-admin.cookies "$base/users")
test "$user_status" = "403" || { echo "Selected-site user listing returned $user_status"; exit 1; }
patch_status=$(curl -sS -o /tmp/site-user-edit-blocked.json -w '%{http_code}' -b /tmp/context-admin.cookies -H "X-CSRF-Token: $admin_csrf" -H 'Content-Type: application/json' -X PATCH -d '{"name":"QA unauthorized edit"}' "$base/users/1")
test "$patch_status" = "403" || { echo "Selected-site user update returned $patch_status"; exit 1; }
create_status=$(curl -sS -o /tmp/site-user-create-blocked.json -w '%{http_code}' -b /tmp/context-admin.cookies -H "X-CSRF-Token: $admin_csrf" -H 'Content-Type: application/json' -d '{}' "$base/users")
test "$create_status" = "403" || { echo "Selected-site user create returned $create_status"; exit 1; }
# Tenant-wide audit exports and queued job metadata must not leak through a site selection.
for endpoint in audit.csv audit.html jobs/imports jobs/exports; do
  code=$(curl -sS -o /tmp/site-wide-denied.json -w '%{http_code}' -b /tmp/context-admin.cookies "$base/$endpoint")
  test "$code" = "403" || { echo "Site context leaked $endpoint: $code"; exit 1; }
done
curl -fsS -b /tmp/context-admin.cookies "$base/visits" > /tmp/site-context-visits.json
curl -fsS -b /tmp/context-admin.cookies "$base/bootstrap" > /tmp/site-context-bootstrap.json
curl -fsS -b /tmp/context-admin.cookies "$base/dashboard/contextual" > /tmp/site-context-metrics.json
python3 - <<'PY'
import json
visits=json.load(open('/tmp/site-context-visits.json'))['data']
bootstrap=json.load(open('/tmp/site-context-bootstrap.json'))['data']
metrics=json.load(open('/tmp/site-context-metrics.json'))['data']
selected=json.load(open('/tmp/context-selected.json'))['data']
assert selected['selected_site_id'] is not None and selected['expires_at'] is not None
assert visits and all(v['reason']=='VISITA_NO_AUTORIZADA_QA' for v in visits), 'Wrong-site visit leaked'
assert all(s['name']=='QA Sede Restringida' for s in bootstrap['sites'])
assert metrics['scope']=='sites' and len(metrics['authorized_sites'])==1
assert metrics['metrics']['people_inside']==1, 'Site-selected dashboard leaked other-site visitors'
PY

status=$(curl -sS -o /tmp/context-cross-site-write.json -w '%{http_code}' \
  -b /tmp/context-admin.cookies -H "X-CSRF-Token: $admin_csrf" \
  -H 'Content-Type: application/json' -d '{}' "$base/visits/1/exit")
test "$status" = "403" || { echo "Selected site allowed cross-site modification: $status"; exit 1; }

status=$(curl -sS -o /tmp/context-cleared.json -w '%{http_code}' \
  -b /tmp/context-admin.cookies -H "X-CSRF-Token: $admin_csrf" \
  -H 'Content-Type: application/json' -d '{}' "$base/me/context/clear")
test "$status" = "200" || { echo "Clearing site selection failed: $status"; exit 1; }
curl -fsS -b /tmp/context-admin.cookies "$base/visits" > /tmp/context-uncropped-visits.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/context-cleared.json'))['data']
v=json.load(open('/tmp/context-uncropped-visits.json'))['data']
assert d['selected_site_id'] is None
reasons={x['reason'] for x in v}
assert 'VISITA_NO_AUTORIZADA_QA' in reasons and 'Visita familiar' in reasons
PY
echo 'Site context: authorization, filtering, mutation guard and clear PASS'
