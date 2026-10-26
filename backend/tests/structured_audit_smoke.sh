#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api

# The new ledger supplements, never replaces, the existing audit tables.
mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT COUNT(*) FROM audit_logs WHERE tenant_id=1" | awk '{if($1<1)exit 1}'
mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT COUNT(*) FROM structured_audit_events WHERE tenant_id=1 AND origin='tenant'" | awk '{if($1<1)exit 1}'
mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT COUNT(*) FROM structured_audit_events WHERE tenant_id=1 AND action='contexto.sede_cambiada'" | awk '{if($1<1)exit 1}'

php -r '
require "backend/api/audit_structured.php";
$a=structured_audit_redact(["password"=>"sensitive123","role"=>"guard","nested"=>["token"=>"secret","site_id"=>2]]);
if($a["password"]!=="[REDACTED]" || $a["nested"]["token"]!=="[REDACTED]" || $a["nested"]["site_id"]!==2)exit(1);
'

curl -fsS -c /tmp/audit-owner.cookies -H 'Content-Type: application/json' \
 -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/audit-owner-login.json
curl -fsS -b /tmp/audit-owner.cookies "$base/platform/audit/structured" >/tmp/structured-global.json

python3 - <<'PY'
import json
d=json.load(open('/tmp/structured-global.json'))['data']
assert d and len(d)<=200
assert any(r['action']=='contexto.sede_cambiada' for r in d)
assert all('password_hash' not in json.dumps(row).lower() for row in d)
PY

curl -fsS -b /tmp/audit-owner.cookies "$base/platform/audit/structured?tenant_id=1" >/tmp/structured-global-tenant1.json
python3 - <<'PY'
import json
rows=json.load(open('/tmp/structured-global-tenant1.json'))['data']
assert rows and all(r['tenant_id']==1 for r in rows)
PY

mysql -h 127.0.0.1 -u root vzwquesv_app <<'SQL'
INSERT INTO users(tenant_id,site_id,name,email,password_hash,role,is_active)
SELECT 1,1,'QA Auditor','qa-auditor@asiscontrol.local',password_hash,'auditor',1
FROM users WHERE id=1
AND NOT EXISTS(SELECT 1 FROM users WHERE email='qa-auditor@asiscontrol.local');
INSERT IGNORE INTO user_scopes(tenant_id,user_id,scope_type,scope_id)
SELECT tenant_id,id,'tenant',tenant_id FROM users WHERE email='qa-auditor@asiscontrol.local';
SQL

curl -fsS -c /tmp/structured-auditor.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-auditor@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/structured-auditor-login.json
curl -fsS -b /tmp/structured-auditor.cookies "$base/audit/structured" >/tmp/structured-own-tenant.json
python3 - <<'PY'
import json
rows=json.load(open('/tmp/structured-own-tenant.json'))['data']
assert rows and all(row['tenant_id']==1 for row in rows)
PY

status=$(curl -sS -o /tmp/structured-auditor-global-denied.json -w '%{http_code}' \
 -b /tmp/structured-auditor.cookies "$base/platform/audit/structured")
test "$status" = "403" || { echo "Tenant auditor accessed provider logs: $status"; exit 1; }

status=$(curl -sS -o /tmp/structured-bad-date.json -w '%{http_code}' \
 -b /tmp/audit-owner.cookies "$base/platform/audit/structured?from=2026-02-31")
test "$status" = "422" || { echo "Invalid date not rejected: $status"; exit 1; }
echo 'Structured audit: tenant and provider boundaries, redaction and filter validation PASS'
