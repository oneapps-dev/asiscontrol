#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
mysql_cmd="mysql -h 127.0.0.1 -u root vzwquesv_app -N -e"
industrial_id=$($mysql_cmd "SELECT id FROM tenants WHERE name='QA Industria Central'")
residential_id=$($mysql_cmd "SELECT id FROM tenants WHERE name='QA Residencial Parque'")

curl -fsS -c /tmp/dashboard-security.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-seguridad@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/dashboard-security-login.json
curl -fsS -b /tmp/dashboard-security.cookies "$base/dashboard/contextual" \
 >/tmp/dashboard-security.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/dashboard-security.json'))['data']
assert d['client_type']=='security_company' and d['role']=='tech_operator'
assert d['metrics']['managed_clients']==2, 'Managed-by relationships omitted from security dashboard'
assert 'active_access_points' in d['metrics']
assert 'residents_active' not in d['metrics']
PY

curl -fsS -c /tmp/dashboard-industry.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-industria@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/dashboard-industry-login.json
curl -fsS -b /tmp/dashboard-industry.cookies "$base/dashboard/contextual" >/tmp/dashboard-industry.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/dashboard-industry.json'))['data']
assert d['client_type']=='industrial' and d['role']=='admin'
for key in ('employee_present','contractors_inside','attendance_entries_today','attendance_late_today'):
    assert isinstance(d['metrics'][key],int) and d['metrics'][key]>=0
assert 'residents_active' not in d['metrics']
PY
actual=$($mysql_cmd "SELECT COUNT(*) FROM attendance_records WHERE tenant_id=$industrial_id AND event_type='entrada' AND occurred_at>=CURRENT_DATE AND occurred_at<DATE_ADD(CURRENT_DATE,INTERVAL 1 DAY)")
ACTUAL="$actual" python3 - <<'PY'
import os,json
count=json.load(open('/tmp/dashboard-industry.json'))['data']['metrics']['attendance_entries_today']
assert count==int(os.environ['ACTUAL']), (count,os.environ['ACTUAL'])
PY

curl -fsS -c /tmp/dashboard-industry-guard.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-guard-industria@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/dashboard-industry-guard-login.json
curl -fsS -b /tmp/dashboard-industry-guard.cookies "$base/dashboard/contextual" >/tmp/dashboard-industry-guard.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/dashboard-industry-guard.json'))['data']
assert d['role']=='guard'
assert 'employee_present' in d['metrics'] and 'contractors_inside' in d['metrics']
assert 'attendance_entries_today' not in d['metrics']
assert 'attendance_late_today' not in d['metrics']
PY

curl -fsS -c /tmp/dashboard-residential.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-residencial@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/dashboard-residential-login.json
curl -fsS -b /tmp/dashboard-residential.cookies "$base/dashboard/contextual" >/tmp/dashboard-residential.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/dashboard-residential.json'))['data']
assert d['client_type']=='residential'
assert 'residents_active' in d['metrics'] and 'bookings_pending' in d['metrics']
assert 'attendance_entries_today' not in d['metrics']
PY

curl -fsS -c /tmp/dashboard-resident-guard.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-guard-residencial@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/dashboard-resident-guard-login.json
curl -fsS -b /tmp/dashboard-resident-guard.cookies "$base/dashboard/contextual" >/tmp/dashboard-resident-guard.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/dashboard-resident-guard.json'))['data']
assert d['role']=='guard'
assert 'residents_active' not in d['metrics'] and 'bookings_pending' not in d['metrics']
PY
echo 'Sector and role dashboards: SQL counts and least-privilege KPIs PASS'
