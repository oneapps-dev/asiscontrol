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
# An authorized administrator with selected site must retain the force-close action.
mysql -h 127.0.0.1 -u root vzwquesv_app -e "INSERT INTO guard_shifts (tenant_id,site_id,user_id,checkpoint,started_at) SELECT 1,$site_a,id,'QA_ALLOWED_FORCE_CLOSE',NOW() FROM users WHERE email='guardia@asiscontrol.local'"
force_shift_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT MAX(id) FROM guard_shifts WHERE checkpoint='QA_ALLOWED_FORCE_CLOSE'")
# The seeded supervisor lacks shifts.monitor. Use the seeded admin, which explicitly has it.
curl -fsS -c /tmp/shift-admin.cookies -H 'Content-Type: application/json' -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' "$base/auth/login" >/tmp/shift-admin-login.json
admin_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/shift-admin-login.json"))["csrf_token"])')
selected_status=$(curl -sS -o /tmp/shift-admin-selected.json -w '%{http_code}' -c /tmp/shift-admin.cookies -b /tmp/shift-admin.cookies -H "X-CSRF-Token: $admin_csrf" -H 'Content-Type: application/json' -d "{\"site_id\":$site_a}" "$base/me/context/select")
test "$selected_status" = "200" || { echo "Admin site selection returned $selected_status"; exit 1; }
# A user WITH shifts.monitor is still denied when the persisted shift is in another site.
scoped_denied=$(curl -sS -o /tmp/shift-admin-cross-site.json -w '%{http_code}' -b /tmp/shift-admin.cookies -H "X-CSRF-Token: $admin_csrf" -H 'Content-Type: application/json' -d '{"reason":"QA forbidden other site"}' "$base/shifts/$shift_id/force-close")
test "$scoped_denied" = "403" || { echo "Privileged cross-site forced close returned $scoped_denied"; cat /tmp/shift-admin-cross-site.json; exit 1; }
grep -Eq 'SCOPE_DENIED|SITE_CONTEXT_DENIED' /tmp/shift-admin-cross-site.json
denied_still_open=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT COUNT(*) FROM guard_shifts WHERE id=$shift_id AND ended_at IS NULL")
test "$denied_still_open" = "1"
force_status=$(curl -sS -o /tmp/shift-force-allowed.json -w '%{http_code}' -b /tmp/shift-admin.cookies -H "X-CSRF-Token: $admin_csrf" -H 'Content-Type: application/json' -d '{"reason":"QA authorized supervisory closure"}' "$base/shifts/$force_shift_id/force-close")
test "$force_status" = "200" || { echo "Allowed forced close returned $force_status"; cat /tmp/shift-force-allowed.json; exit 1; }
force_closed=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT COUNT(*) FROM guard_shifts WHERE id=$force_shift_id AND ended_at IS NOT NULL")
test "$force_closed" = "1"

# A guard cannot close their own shift outside the selected site either.
mysql -h 127.0.0.1 -u root vzwquesv_app -e "INSERT INTO guard_shifts (tenant_id,site_id,user_id,checkpoint,started_at) SELECT 1,$site_b,id,'QA_CROSS_SITE_SELF_CLOSE',NOW() FROM users WHERE email='guardia@asiscontrol.local'"
self_shift_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT MAX(id) FROM guard_shifts WHERE checkpoint='QA_CROSS_SITE_SELF_CLOSE'")
curl -fsS -c /tmp/shift-guard.cookies -H 'Content-Type: application/json' -d '{"email":"guardia@asiscontrol.local","password":"demo1234"}' "$base/auth/login" >/tmp/shift-guard-login.json
guard_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/shift-guard-login.json"))["csrf_token"])')
guard_status=$(curl -sS -o /tmp/shift-guard-denied.json -w '%{http_code}' -b /tmp/shift-guard.cookies -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' -d '{}' "$base/shifts/$self_shift_id/close")
test "$guard_status" = "403" || { echo "Cross-site self-close returned $guard_status"; exit 1; }
still_open=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT COUNT(*) FROM guard_shifts WHERE id=$self_shift_id AND ended_at IS NULL")
test "$still_open" = "1"
# Preserve the original allowed workflow: guard can close their own shift at an authorized site.
mysql -h 127.0.0.1 -u root vzwquesv_app -e "INSERT INTO guard_shifts (tenant_id,site_id,user_id,checkpoint,started_at) SELECT 1,$site_a,id,'QA_ALLOWED_SELF_CLOSE',NOW() FROM users WHERE email='guardia@asiscontrol.local'"
own_shift_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT MAX(id) FROM guard_shifts WHERE checkpoint='QA_ALLOWED_SELF_CLOSE'")
allowed_status=$(curl -sS -o /tmp/shift-guard-allowed.json -w '%{http_code}' -b /tmp/shift-guard.cookies -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' -d '{}' "$base/shifts/$own_shift_id/close")
test "$allowed_status" = "200" || { echo "Allowed self-close returned $allowed_status"; cat /tmp/shift-guard-allowed.json; exit 1; }
is_closed=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT COUNT(*) FROM guard_shifts WHERE id=$own_shift_id AND ended_at IS NOT NULL")
test "$is_closed" = "1"
echo "Shift closes: unauthorized denied and authorized self-close accepted PASS"
