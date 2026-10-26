#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
query='mysql -h 127.0.0.1 -u root vzwquesv_app -N -e'

# A newly-created record must return its own primary key, never the audit ID.
curl -fsS -c /tmp/created-ids-admin.cookies -H 'Content-Type: application/json' \
 -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/created-ids-admin-login.json
csrf=$(python3 -c 'import json;print(json.load(open("/tmp/created-ids-admin-login.json"))["csrf_token"])')
site=$($query "SELECT MIN(id) FROM sites WHERE tenant_id=1")
test -n "$site"

assert_id() {
  local body="$1" table="$2" column="$3" expected="$4"
  local id
  id=$(python3 -c 'import json,sys;v=json.load(open(sys.argv[1]));print(v["id"])' "$body")
  test "$id" -gt 0
  local actual
  actual=$($query "SELECT $column FROM $table WHERE id=$id AND tenant_id=1 LIMIT 1")
  test "$actual" = "$expected" || {
    echo "Returned ID $id in $table does not identify created value: $actual"
    exit 1
  }
}

status=$(curl -sS -o /tmp/id-restriction.json -w '%{http_code}' \
 -b /tmp/created-ids-admin.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d '{"document_type":"CC","document_number":"QA-ID-CHECK-RESTRICTION","reason":"Regresión de IDs","level":"warning"}' \
 "$base/restrictions")
test "$status" = "201" || { echo "Restriction create $status"; cat /tmp/id-restriction.json; exit 1; }
assert_id /tmp/id-restriction.json restrictions document_number QA-ID-CHECK-RESTRICTION

status=$(curl -sS -o /tmp/id-parking.json -w '%{http_code}' \
 -b /tmp/created-ids-admin.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "{\"site_id\":$site,\"space_code\":\"QA-ID-CHECK-PARKING\",\"assigned_to\":\"QA titular\"}" \
 "$base/parking")
test "$status" = "201" || { echo "Parking create $status"; cat /tmp/id-parking.json; exit 1; }
assert_id /tmp/id-parking.json parking_assignments space_code QA-ID-CHECK-PARKING

status=$(curl -sS -o /tmp/id-incident.json -w '%{http_code}' \
 -b /tmp/created-ids-admin.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d '{"title":"QA-ID-CHECK-INCIDENT","severity":"media","area":"QA","description":"Verificar identidad real"}' \
 "$base/incidents")
test "$status" = "201" || { echo "Incident create $status"; cat /tmp/id-incident.json; exit 1; }
assert_id /tmp/id-incident.json incidents title QA-ID-CHECK-INCIDENT

curl -fsS -c /tmp/created-ids-guard.cookies -H 'Content-Type: application/json' \
 -d '{"email":"guardia@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/created-ids-guard-login.json
guard_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/created-ids-guard-login.json"))["csrf_token"])')
guard_site=$($query "SELECT site_id FROM users WHERE email='guardia@asiscontrol.local'")
status=$(curl -sS -o /tmp/id-shift.json -w '%{http_code}' \
 -b /tmp/created-ids-guard.cookies -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' \
 -d "{\"site_id\":$guard_site,\"checkpoint\":\"QA-ID-CHECK-SHIFT\"}" "$base/shifts")
test "$status" = "201" || { echo "Shift create $status"; cat /tmp/id-shift.json; exit 1; }
assert_id /tmp/id-shift.json guard_shifts checkpoint QA-ID-CHECK-SHIFT

# Verify that the audit record exists independently of the returned IDs.
for action in restriccion.creada parqueadero.creado incidente.creado turno.inicio; do
  found=$($query "SELECT COUNT(*) FROM audit_logs WHERE tenant_id=1 AND action='$action'")
  test "$found" -ge 1 || { echo "Missing audit for $action"; exit 1; }
done

echo 'Creation IDs: restrictions, parking, incidents and shifts map to persisted records; audit preserved PASS'
