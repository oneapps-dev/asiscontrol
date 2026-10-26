#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
tenant_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM tenants WHERE name='QA Industria Central'")
site_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM sites WHERE tenant_id=$tenant_id AND name='QA Sede Principal'")
# A legacy residential record imported into an industrial tenant must be hidden by default.
mysql -h 127.0.0.1 -u root vzwquesv_app -e \
  "INSERT INTO units(tenant_id,site_id,tower,unit_code,status) VALUES($tenant_id,$site_id,'Legacy QA','LEGACY-QA','active')"

curl -fsS -c /tmp/type-industry.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-industria@asiscontrol.local","password":"demo1234"}' "$base/auth/login" >/tmp/type-industry-login.json
status=$(curl -sS -o /tmp/disabled-units.json -w '%{http_code}' -b /tmp/type-industry.cookies "$base/units")
test "$status" = "403" || { echo "Industrial tenant read residential units: $status"; exit 1; }
grep -q CLIENT_MODULE_DISABLED /tmp/disabled-units.json
curl -fsS -b /tmp/type-industry.cookies "$base/bootstrap" >/tmp/type-industry-bootstrap.json
python3 - <<'PY'
import json
data=json.load(open('/tmp/type-industry-bootstrap.json'))['data']
assert data['units']==[] and data['residents']==[], 'Industrial client leaked residential data'
PY
curl -fsS -c /tmp/type-residential.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-residencial@asiscontrol.local","password":"demo1234"}' "$base/auth/login" >/tmp/type-residential-login.json
status=$(curl -sS -o /tmp/type-residential-units.json -w '%{http_code}' -b /tmp/type-residential.cookies "$base/units")
test "$status" = "200" || { echo "Residential tenant blocked: $status"; exit 1; }

curl -fsS -c /tmp/type-owner.cookies -H 'Content-Type: application/json' \
 -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' "$base/auth/login" >/tmp/type-owner-login.json
csrf=$(python3 -c 'import json;print(json.load(open("/tmp/type-owner-login.json"))["csrf_token"])')
status=$(curl -sS -o /tmp/type-enable.json -w '%{http_code}' \
 -b /tmp/type-owner.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -X PUT -d '{"residential_modules":true}' "$base/platform/tenants/$tenant_id/features")
test "$status" = "200" || { echo "CREAMOX tenant feature enable: $status"; exit 1; }
curl -fsS -b /tmp/type-industry.cookies "$base/units" >/tmp/type-enabled-units.json
python3 - <<'PY'
import json
assert any(x.get('unit_code')=='LEGACY-QA' for x in json.load(open('/tmp/type-enabled-units.json'))['data'])
PY
status=$(curl -sS -o /tmp/type-disable.json -w '%{http_code}' \
 -b /tmp/type-owner.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -X PUT -d '{"residential_modules":false}' "$base/platform/tenants/$tenant_id/features")
test "$status" = "200" || { echo "CREAMOX tenant feature disable: $status"; exit 1; }
status=$(curl -sS -o /tmp/type-disabled-again.json -w '%{http_code}' -b /tmp/type-industry.cookies "$base/units")
test "$status" = "403" || { echo "Override revocation did not block access: $status"; exit 1; }
echo 'Client-type module isolation and CREAMOX override/revocation PASS'
