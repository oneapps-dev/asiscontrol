#!/usr/bin/env bash
set -euo pipefail

# Second site in the SAME tenant catches accidental tenant-only scoping.
mysql -h 127.0.0.1 -u root vzwquesv_app <<'SQL'
INSERT INTO sites(tenant_id,name,address,timezone)
VALUES (1,'QA Sede Restringida','Lugar ficticio','America/Bogota');
SET @other_site=LAST_INSERT_ID();
INSERT INTO guard_shifts(tenant_id,site_id,user_id,checkpoint,started_at)
VALUES (1,@other_site,1,'Segundo puesto QA',NOW());
SET @other_shift=LAST_INSERT_ID();
INSERT INTO visits(tenant_id,person_id,shift_id,destination,host,reason,entered_at,status)
VALUES (1,1,@other_shift,'Sede restringida','Equipo QA','VISITA_NO_AUTORIZADA_QA',NOW(),'inside');
SQL

base=http://127.0.0.1:8765/api
curl -fsS -c /tmp/siteguard.cookies -H 'Content-Type: application/json' \
  -d '{"email":"guardia@asiscontrol.local","password":"demo1234"}' \
  "$base/auth/login" >/tmp/siteguard-login.json
curl -fsS -b /tmp/siteguard.cookies "$base/bootstrap" >/tmp/siteguard-bootstrap.json
curl -fsS -b /tmp/siteguard.cookies "$base/visits" >/tmp/siteguard-visits.json

python3 - <<'PY'
import json
boot=json.load(open('/tmp/siteguard-bootstrap.json'))['data']
visits=json.load(open('/tmp/siteguard-visits.json'))['data']
sites=boot['sites']
assert sites, 'Expected at least one authorized site'
assert all(s['name']!='QA Sede Restringida' for s in sites), 'Site scope leak'
assert all(v.get('reason')!='VISITA_NO_AUTORIZADA_QA' for v in boot['visits']), 'Bootstrap visit leak'
assert all(v.get('reason')!='VISITA_NO_AUTORIZADA_QA' for v in visits), 'Direct visit route leak'
PY

csrf=$(python3 -c 'import json;print(json.load(open("/tmp/siteguard-login.json"))["csrf_token"])')
site_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM sites WHERE name='QA Sede Restringida' AND tenant_id=1 LIMIT 1")
status=$(curl -sS -o /tmp/out-of-scope.json -w '%{http_code}' \
  -b /tmp/siteguard.cookies -H "X-CSRF-Token: $csrf" \
  -H 'Content-Type: application/json' \
  -d "{\"site_id\":$site_id,\"checkpoint\":\"No autorizado\"}" "$base/shifts")
test "$status" = "403" || { echo "Cross-site shift creation returned $status"; exit 1; }
status=$(curl -sS -o /tmp/audit-denied.json -w '%{http_code}' -b /tmp/siteguard.cookies "$base/audit")
test "$status" = "403" || { echo "Guard audit read returned $status"; exit 1; }

# Resident portal sessions must be revoked immediately after deactivation.
hash=$(php -r 'echo password_hash("qa-portal-pass", PASSWORD_DEFAULT);')
mysql -h 127.0.0.1 -u root vzwquesv_app -e "UPDATE residents SET password_hash='$hash' WHERE id=1 AND tenant_id=1"
curl -fsS -c /tmp/portal-qa.cookies -H 'Content-Type: application/json' \
  -d '{"email":"laura.gomez@asiscontrol.local","password":"qa-portal-pass"}' \
  "$base/portal/auth/login" >/tmp/portal-qa-login.json
curl -fsS -b /tmp/portal-qa.cookies "$base/portal/me" >/tmp/portal-qa-before.json
mysql -h 127.0.0.1 -u root vzwquesv_app -e "UPDATE residents SET status='inactive' WHERE id=1 AND tenant_id=1"
status=$(curl -sS -o /tmp/portal-revoked.json -w '%{http_code}' \
  -b /tmp/portal-qa.cookies "$base/portal/me")
test "$status" = "403" || { echo "Revoked portal session returned $status"; exit 1; }
grep -q 'RESIDENT_ACCESS_REVOKED' /tmp/portal-revoked.json

echo 'Scope isolation, cross-site denial, audit denial, portal revocation: PASSED'
