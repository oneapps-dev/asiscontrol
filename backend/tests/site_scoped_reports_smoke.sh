#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
site1=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT MIN(id) FROM sites WHERE tenant_id=1")
site2=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM sites WHERE tenant_id=1 AND name='QA Sede Restringida' LIMIT 1")
test -n "$site1" && test -n "$site2"

mysql -h 127.0.0.1 -u root vzwquesv_app <<SQL
INSERT INTO people(tenant_id,person_type,document_type,document_number,full_name,privacy_consent_at)
SELECT 1,'empleado','CC','QA-ATT-SECURED','QA Empleado Auditoría',NOW()
WHERE NOT EXISTS(SELECT 1 FROM people WHERE tenant_id=1 AND document_type='CC' AND document_number='QA-ATT-SECURED');
INSERT IGNORE INTO employee_profiles(tenant_id,person_id,status)
SELECT 1,id,'active' FROM people WHERE tenant_id=1 AND document_type='CC' AND document_number='QA-ATT-SECURED';
INSERT INTO users(tenant_id,site_id,name,email,password_hash,role,is_active)
SELECT 1,$site1,'QA Supervisor Reportes','qa-site-reports-supervisor@asiscontrol.local',
 password_hash,'supervisor',1 FROM users WHERE email='admin@asiscontrol.local'
AND NOT EXISTS(SELECT 1 FROM users WHERE email='qa-site-reports-supervisor@asiscontrol.local');
INSERT IGNORE INTO user_scopes(tenant_id,user_id,scope_type,scope_id)
SELECT 1,id,'site',$site1 FROM users WHERE email='qa-site-reports-supervisor@asiscontrol.local';
INSERT INTO users(tenant_id,site_id,name,email,password_hash,role,is_active)
SELECT 1,$site1,'QA Supervisor General','qa-tenant-reports-supervisor@asiscontrol.local',
 password_hash,'supervisor',1 FROM users WHERE email='admin@asiscontrol.local'
AND NOT EXISTS(SELECT 1 FROM users WHERE email='qa-tenant-reports-supervisor@asiscontrol.local');
INSERT IGNORE INTO user_scopes(tenant_id,user_id,scope_type,scope_id)
SELECT 1,id,'tenant',1 FROM users WHERE email='qa-tenant-reports-supervisor@asiscontrol.local';
INSERT INTO attendance_records(tenant_id,employee_profile_id,person_id,site_id,event_type,occurred_at,status,notes)
SELECT 1,ep.id,p.id,$site1,'entrada',NOW(),'puntual','QA_ATTENDANCE_ALLOWED_SITE'
FROM employee_profiles ep JOIN people p ON p.id=ep.person_id
WHERE ep.tenant_id=1 AND p.document_number='QA-ATT-SECURED';
INSERT INTO attendance_records(tenant_id,employee_profile_id,person_id,site_id,event_type,occurred_at,status,notes)
SELECT 1,ep.id,p.id,$site2,'entrada',NOW(),'tarde','QA_ATTENDANCE_DENIED_SITE'
FROM employee_profiles ep JOIN people p ON p.id=ep.person_id
WHERE ep.tenant_id=1 AND p.document_number='QA-ATT-SECURED';
SQL

echo 'QA REPORT REQUEST 1'
curl -fsS -c /tmp/scoped-reports.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-site-reports-supervisor@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/scoped-reports-login.json

echo 'QA REPORT REQUEST 2'
curl -fsS -c /tmp/scoped-attendance.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-site-fileadmin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/scoped-attendance-login.json

echo 'QA REPORT REQUEST 3'
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/visits.csv" >/tmp/scoped-visit.csv
grep -q VISITA_EVIDENCIA_PERMITIDA_QA /tmp/scoped-visit.csv
! grep -q VISITA_NO_AUTORIZADA_QA /tmp/scoped-visit.csv
echo 'QA REPORT REQUEST 4'
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/visits.html" >/tmp/scoped-visit.html
grep -q 'Sede permitida QA' /tmp/scoped-visit.html
! grep -q 'Sede restringida' /tmp/scoped-visit.html
echo 'QA REPORT REQUEST 5'
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/visits.xlsx" >/tmp/scoped-visit.xlsx
python3 - <<'PY'
from zipfile import ZipFile
with ZipFile('/tmp/scoped-visit.xlsx') as z:
    content=' '.join(z.read(name).decode('utf-8','ignore')
        for name in z.namelist() if name.endswith('.xml'))
assert 'VISITA_EVIDENCIA_PERMITIDA_QA' in content
assert 'VISITA_NO_AUTORIZADA_QA' not in content
PY

echo 'QA REPORT REQUEST 6'
echo 'QA SQL scoped summary'
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/summary" >/tmp/scoped-summary.json
expected_site_visits=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT COUNT(*) FROM visits v LEFT JOIN guard_shifts gs ON gs.id=v.shift_id AND gs.tenant_id=v.tenant_id WHERE v.tenant_id=1 AND COALESCE(v.site_id,gs.site_id)=$site1")
EXPECTED_SITE="$expected_site_visits" python3 - <<'PY'
import os,json
metrics=json.load(open('/tmp/scoped-summary.json'))['data']
assert metrics['visits_total']==int(os.environ['EXPECTED_SITE']), (metrics,os.environ['EXPECTED_SITE'])
assert metrics['visits_inside']+metrics['visits_exited']==metrics['visits_total']
PY

curl -fsS -b /tmp/scoped-attendance.cookies "$base/attendance" >/tmp/scoped-attendance.json
python3 - <<'PY'
import json
rows=json.load(open('/tmp/scoped-attendance.json'))['data']
notes={str(x.get('notes','')) for x in rows}
assert 'QA_ATTENDANCE_ALLOWED_SITE' in notes
assert 'QA_ATTENDANCE_DENIED_SITE' not in notes
PY
echo 'QA REPORT REQUEST 7'
curl -fsS -b /tmp/scoped-attendance.cookies "$base/reports/attendance.csv" >/tmp/scoped-attendance.csv
grep -q QA_ATTENDANCE_ALLOWED_SITE /tmp/scoped-attendance.csv
! grep -q QA_ATTENDANCE_DENIED_SITE /tmp/scoped-attendance.csv
echo 'QA REPORT REQUEST 8'
curl -fsS -b /tmp/scoped-attendance.cookies "$base/reports/attendance.html" >/tmp/scoped-attendance.html
grep -q QA_ATTENDANCE_ALLOWED_SITE /tmp/scoped-attendance.html
! grep -q QA_ATTENDANCE_DENIED_SITE /tmp/scoped-attendance.html
echo 'QA REPORT REQUEST 9'
curl -fsS -b /tmp/scoped-attendance.cookies "$base/reports/attendance.xlsx" >/tmp/scoped-attendance.xlsx
python3 - <<'PY'
from zipfile import ZipFile
with ZipFile('/tmp/scoped-attendance.xlsx') as z:
    content=' '.join(z.read(name).decode('utf-8','ignore')
        for name in z.namelist() if name.endswith('.xml'))
assert 'QA_ATTENDANCE_ALLOWED_SITE' in content
assert 'QA_ATTENDANCE_DENIED_SITE' not in content
PY
# All previously allowed tenant-wide views must still include the other site.
echo 'QA REPORT REQUEST 10'
curl -fsS -c /tmp/reports-tenant-admin.cookies -H 'Content-Type: application/json' \
 -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/reports-tenant-admin.json
echo 'QA REPORT REQUEST 11'
curl -fsS -c /tmp/reports-tenant-supervisor.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-tenant-reports-supervisor@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/reports-tenant-supervisor.json
echo 'QA REPORT REQUEST 12'
curl -fsS -b /tmp/reports-tenant-supervisor.cookies "$base/reports/visits.csv" >/tmp/reports-full-visits.csv
curl -fsS -b /tmp/reports-tenant-supervisor.cookies "$base/reports/summary" >/tmp/reports-full-summary.json
EXPECTED_SITE="$expected_site_visits" python3 - <<'PY'
import json,os
d=json.load(open('/tmp/reports-full-summary.json'))['data']
assert d['visits_total']>int(os.environ['EXPECTED_SITE']), 'Tenant-wide report unexpectedly truncated'
PY
echo 'QA REPORT REQUEST 13'
curl -fsS -b /tmp/reports-tenant-admin.cookies "$base/reports/attendance.csv" >/tmp/reports-full-attendance.csv
grep -q VISITA_NO_AUTORIZADA_QA /tmp/reports-full-visits.csv
grep -q QA_ATTENDANCE_DENIED_SITE /tmp/reports-full-attendance.csv
echo 'Reports: site-limited CSV, XLSX, HTML and attendance API contain only allowed records PASS'
