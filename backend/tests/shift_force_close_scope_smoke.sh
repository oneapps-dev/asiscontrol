#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
site_a=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT MIN(id) FROM sites WHERE tenant_id=1")
site_b=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM sites WHERE tenant_id=1 AND name='QA Sede Restringida' LIMIT 1")
mysql -h 127.0.0.1 -u root vzwquesv_app -e "INSERT INTO guard_shifts (tenant_id,site_id,user_id,checkpoint,started_at) SELECT 1,$site_b,id,'QA_CROSS_SITE_CLOSE',NOW() FROM users WHERE email='guardia@asiscontrol.local'"
shift_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT MAX(id) FROM guard_shifts WHERE checkpoint='QA_CROSS_SITE_CLOSE'")
curl -fsS -c /tmp/shift-scope.cookies -H 'Content-Type: application/json' -d '{"email":"qa-site-reports-supervisor@asiscontrol.local","password":"demo1234"}' "$base/auth/login" >/tmp/shift-scope-login.json
csrf=$(python3 -c 'import json;print(json.load(open("/tmp/shift-scope-login.json"))["csrf_token"])')
status=$(curl -sS -o /tmp/shift-scope-denied.json -w '%{http_code}' -b /tmp/shift-scope.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d '{"reason":"QA scope validation"}' "$base/shifts/$shift_id/force-close")
test "$status" = "403" || { echo "Cross-site close returned $status"; exit 1; }
still_open=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT COUNT(*) FROM guard_shifts WHERE id=$shift_id AND ended_at IS NULL")
test "$still_open" = "1"
# A guard cannot close their own shift outside the selected site either.
mysql -h 127.0.0.1 -u root vzwquesv_app -e "INSERT INTO guard_shifts (tenant_id,site_id,user_id,checkpoint,started_at) SELECT 1,$site_b,id,'QA_CROSS_SITE_SELF_CLOSE',NOW() FROM users WHERE email='guardia@asiscontrol.local'"
self_shift_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT MAX(id) FROM guard_shifts WHERE checkpoint='QA_CROSS_SITE_SELF_CLOSE'")
curl -fsS -c /tmp/shift-guard.cookies -H 'Content-Type: application/json' -d '{"email":"guardia@asiscontrol.local","password":"demo1234"}' "$base/auth/login" >/tmp/shift-guard-login.json
guard_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/shift-guard-login.json"))["csrf_token"])')
guard_status=$(curl -sS -o /tmp/shift-guard-denied.json -w '%{http_code}' -b /tmp/shift-guard.cookies -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' -d '{}' "$base/shifts/$self_shift_id/close")
test "$guard_status" = "403" || { echo "Cross-site self-close returned $guard_status"; exit 1; }
still_open=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT COUNT(*) FROM guard_shifts WHERE id=$self_shift_id AND ended_at IS NULL")
test "$still_open" = "1"
echo "Cross-site forced and personal shift closures rejected PASS"
