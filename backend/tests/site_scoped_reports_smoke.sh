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
INSERT INTO attendance_records(tenant_id,employee_profile_id,person_id,site_id,event_type,occurred_at,status,notes)
SELECT 1,ep.id,p.id,$site1,'entrada',NOW(),'puntual','QA_ATTENDANCE_ALLOWED_SITE'
FROM employee_profiles ep JOIN people p ON p.id=ep.person_id
WHERE ep.tenant_id=1 AND p.document_number='QA-ATT-SECURED';
INSERT INTO attendance_records(tenant_id,employee_profile_id,person_id,site_id,event_type,occurred_at,status,notes)
SELECT 1,ep.id,p.id,$site2,'entrada',NOW(),'tarde','QA_ATTENDANCE_DENIED_SITE'
FROM employee_profiles ep JOIN people p ON p.id=ep.person_id
WHERE ep.tenant_id=1 AND p.document_number='QA-ATT-SECURED';
SQL

curl -fsS -c /tmp/scoped-reports.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-site-fileadmin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/scoped-reports-login.json

curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/visits.csv" >/tmp/scoped-visit.csv
grep -q VISITA_EVIDENCIA_PERMITIDA_QA /tmp/scoped-visit.csv
! grep -q VISITA_NO_AUTORIZADA_QA /tmp/scoped-visit.csv
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/visits.html" >/tmp/scoped-visit.html
grep -q 'Sede permitida QA' /tmp/scoped-visit.html
! grep -q 'Sede restringida' /tmp/scoped-visit.html
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/visits.xlsx" >/tmp/scoped-visit.xlsx
python3 - <<'PY'
from zipfile import ZipFile
with ZipFile('/tmp/scoped-visit.xlsx') as z:
    content=' '.join(z.read(name).decode('utf-8','ignore')
        for name in z.namelist() if name.endswith('.xml'))
assert 'VISITA_EVIDENCIA_PERMITIDA_QA' in content
assert 'VISITA_NO_AUTORIZADA_QA' not in content
PY

curl -fsS -b /tmp/scoped-reports.cookies "$base/attendance" >/tmp/scoped-attendance.json
python3 - <<'PY'
import json
rows=json.load(open('/tmp/scoped-attendance.json'))['data']
notes={str(x.get('notes','')) for x in rows}
assert 'QA_ATTENDANCE_ALLOWED_SITE' in notes
assert 'QA_ATTENDANCE_DENIED_SITE' not in notes
PY
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/attendance.csv" >/tmp/scoped-attendance.csv
grep -q QA_ATTENDANCE_ALLOWED_SITE /tmp/scoped-attendance.csv
! grep -q QA_ATTENDANCE_DENIED_SITE /tmp/scoped-attendance.csv
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/attendance.html" >/tmp/scoped-attendance.html
grep -q QA_ATTENDANCE_ALLOWED_SITE /tmp/scoped-attendance.html
! grep -q QA_ATTENDANCE_DENIED_SITE /tmp/scoped-attendance.html
curl -fsS -b /tmp/scoped-reports.cookies "$base/reports/attendance.xlsx" >/tmp/scoped-attendance.xlsx
python3 - <<'PY'
from zipfile import ZipFile
with ZipFile('/tmp/scoped-attendance.xlsx') as z:
    content=' '.join(z.read(name).decode('utf-8','ignore')
        for name in z.namelist() if name.endswith('.xml'))
assert 'QA_ATTENDANCE_ALLOWED_SITE' in content
assert 'QA_ATTENDANCE_DENIED_SITE' not in content
PY
# All previously allowed tenant-wide views must still include the other site.
curl -fsS -c /tmp/reports-tenant-admin.cookies -H 'Content-Type: application/json' \
 -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/reports-tenant-admin.json
curl -fsS -b /tmp/reports-tenant-admin.cookies "$base/reports/visits.csv" >/tmp/reports-full-visits.csv
curl -fsS -b /tmp/reports-tenant-admin.cookies "$base/reports/attendance.csv" >/tmp/reports-full-attendance.csv
grep -q VISITA_NO_AUTORIZADA_QA /tmp/reports-full-visits.csv
grep -q QA_ATTENDANCE_DENIED_SITE /tmp/reports-full-attendance.csv
echo 'Reports: site-limited CSV, XLSX, HTML and attendance API contain only allowed records PASS'
