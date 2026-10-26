#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
mysql -h 127.0.0.1 -u root vzwquesv_app <<'SQL'
SET @site=(SELECT MIN(id) FROM sites WHERE tenant_id=1);
INSERT INTO users (tenant_id,site_id,name,email,password_hash,role,is_active)
SELECT 1,@site,'QA Administradora Limitada','qa-site-fileadmin@asiscontrol.local',
       password_hash,'admin',1
FROM users WHERE email='admin@asiscontrol.local'
AND NOT EXISTS(SELECT 1 FROM users WHERE email='qa-site-fileadmin@asiscontrol.local');
INSERT IGNORE INTO user_scopes(tenant_id,user_id,scope_type,scope_id)
SELECT 1,id,'site',@site FROM users WHERE email='qa-site-fileadmin@asiscontrol.local';
INSERT INTO visits(tenant_id,person_id,shift_id,destination,host,reason,entered_at,status)
SELECT 1,p.id,gs.id,'Sede permitida QA','Control QA','VISITA_EVIDENCIA_PERMITIDA_QA',NOW(),'inside'
FROM people p JOIN guard_shifts gs ON gs.tenant_id=1 AND gs.site_id=@site
WHERE p.tenant_id=1 ORDER BY gs.id LIMIT 1;
SQL
allowed=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM visits WHERE tenant_id=1 AND reason='VISITA_EVIDENCIA_PERMITIDA_QA' ORDER BY id DESC LIMIT 1")
denied=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM visits WHERE tenant_id=1 AND reason='VISITA_NO_AUTORIZADA_QA' LIMIT 1")
test -n "$allowed" && test -n "$denied"

curl -fsS -c /tmp/evidence-limited.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-site-fileadmin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/evidence-limited-login.json
csrf=$(python3 -c 'import json;print(json.load(open("/tmp/evidence-limited-login.json"))["csrf_token"])')
printf '%%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%%%EOF\n' >/tmp/evidence-small.pdf

status=$(curl -sS -o /tmp/evidence-allowed.json -w '%{http_code}' \
  -b /tmp/evidence-limited.cookies -H "X-CSRF-Token: $csrf" \
  -F 'owner_type=visit' -F "owner_id=$allowed" \
  -F 'file=@/tmp/evidence-small.pdf;type=application/pdf' "$base/files")
test "$status" = "201" || { echo "Allowed attachment upload failed ($status)"; cat /tmp/evidence-allowed.json; exit 1; }
file_id=$(python3 -c 'import json;print(json.load(open("/tmp/evidence-allowed.json"))["id"])')

curl -fsS -b /tmp/evidence-limited.cookies \
 "$base/files?owner_type=visit&owner_id=$allowed" >/tmp/evidence-list.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/evidence-list.json'))['data']
assert len(d)==1 and 'stored_path' not in d[0]
PY
status=$(curl -sS -o /tmp/evidence-download.pdf -w '%{http_code}' \
 -b /tmp/evidence-limited.cookies "$base/files/$file_id/download")
test "$status" = "200" || { echo "Authorized attachment download failed: $status"; exit 1; }
grep -aq '%PDF' /tmp/evidence-download.pdf

status=$(curl -sS -o /tmp/evidence-cross-site-list.json -w '%{http_code}' \
 -b /tmp/evidence-limited.cookies "$base/files?owner_type=visit&owner_id=$denied")
test "$status" = "403" || { echo "Cross-site evidence list was exposed: $status"; exit 1; }

status=$(curl -sS -o /tmp/evidence-cross-site-upload.json -w '%{http_code}' \
 -b /tmp/evidence-limited.cookies -H "X-CSRF-Token: $csrf" \
 -F 'owner_type=visit' -F "owner_id=$denied" \
 -F 'file=@/tmp/evidence-small.pdf;type=application/pdf' "$base/files")
test "$status" = "403" || { echo "Cross-site evidence upload accepted: $status"; exit 1; }

# A forged attachment in the same tenant must still check its parent record on download.
mysql -h 127.0.0.1 -u root vzwquesv_app -e "
INSERT INTO file_attachments (tenant_id,owner_type,owner_id,original_name,stored_path,mime_type,byte_size,sha256,uploaded_by,captured_at,immutable_at)
SELECT tenant_id,'visit',$denied,original_name,stored_path,mime_type,byte_size,sha256,uploaded_by,NOW(),NOW()
FROM file_attachments WHERE id=$file_id;"
forged=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT MAX(id) FROM file_attachments WHERE tenant_id=1 AND owner_type='visit' AND owner_id=$denied")
status=$(curl -sS -o /tmp/evidence-cross-site-download.json -w '%{http_code}' \
 -b /tmp/evidence-limited.cookies "$base/files/$forged/download")
test "$status" = "403" || { echo "Cross-site evidence download exposed: $status"; exit 1; }

status=$(curl -sS -o /tmp/evidence-invalid-type.json -w '%{http_code}' \
 -b /tmp/evidence-limited.cookies "$base/files?owner_type=platform_admins&owner_id=1")
test "$status" = "422" || { echo "Untrusted evidence owner type accepted: $status"; exit 1; }

# A tenant-wide administrator retains legitimate access to both parents.
curl -fsS -c /tmp/evidence-global.cookies -H 'Content-Type: application/json' \
 -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/evidence-global-login.json
curl -fsS -b /tmp/evidence-global.cookies \
 "$base/files?owner_type=visit&owner_id=$denied" >/tmp/evidence-tenantwide.json
python3 - <<'PY'
import json
rows=json.load(open('/tmp/evidence-tenantwide.json'))['data']
assert len(rows)==1 and rows[0]['owner_type']=='visit'
PY

# A real site-scoped guard must retain evidence access granted by files.manage.
curl -fsS -c /tmp/evidence-guard.cookies -H 'Content-Type: application/json' \
 -d '{"email":"guardia@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/evidence-guard-login.json
curl -fsS -b /tmp/evidence-guard.cookies \
 "$base/files?owner_type=visit&owner_id=$allowed" >/tmp/evidence-guard-allowed.json
python3 - <<'PY'
import json
rows=json.load(open('/tmp/evidence-guard-allowed.json'))['data']
assert len(rows)==1 and 'stored_path' not in rows[0]
PY
status=$(curl -sS -o /tmp/evidence-guard-denied.json -w '%{http_code}' \
 -b /tmp/evidence-guard.cookies "$base/files?owner_type=visit&owner_id=$denied")
test "$status" = "403" || { echo "Guard accessed other-site evidence: $status"; exit 1; }

# A tenant-wide administrator may select a site; the selection reduces access.
owner_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/evidence-global-login.json"))["csrf_token"])')
site2=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT id FROM sites WHERE tenant_id=1 AND name='QA Sede Restringida' LIMIT 1")
status=$(curl -sS -o /tmp/evidence-context-selected.json -w '%{http_code}' \
 -b /tmp/evidence-global.cookies -H "X-CSRF-Token: $owner_csrf" \
 -H 'Content-Type: application/json' \
 -d "{\"site_id\":$site2}" "$base/me/context/select")
test "$status" = "200" || { echo "Admin site selection denied $status"; exit 1; }
status=$(curl -sS -o /tmp/evidence-context-old-site-denied.json -w '%{http_code}' \
 -b /tmp/evidence-global.cookies "$base/files?owner_type=visit&owner_id=$allowed")
test "$status" = "403" || { echo "Site selection failed to constrain evidence access: $status"; exit 1; }
curl -fsS -b /tmp/evidence-global.cookies \
 "$base/files?owner_type=visit&owner_id=$denied" >/tmp/evidence-context-selected-files.json
status=$(curl -sS -o /tmp/evidence-context-cleared.json -w '%{http_code}' \
 -b /tmp/evidence-global.cookies -H "X-CSRF-Token: $owner_csrf" \
 -H 'Content-Type: application/json' -d '{}' "$base/me/context/clear")
test "$status" = "200" || { echo "Site selection clear failed: $status"; exit 1; }
curl -fsS -b /tmp/evidence-global.cookies \
 "$base/files?owner_type=visit&owner_id=$allowed" >/tmp/evidence-cleared-files.json
echo 'Evidence: authorized upload/list/download, restricted guard, and temporary site-context isolation PASS'

