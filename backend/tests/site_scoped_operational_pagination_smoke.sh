#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
sql='mysql -h 127.0.0.1 -u root vzwquesv_app -N -e'
site1=$($sql "SELECT MIN(id) FROM sites WHERE tenant_id=1")
site2=$($sql "SELECT id FROM sites WHERE tenant_id=1 AND name='QA Sede Restringida' LIMIT 1")
shift1=$($sql "SELECT id FROM guard_shifts WHERE tenant_id=1 AND site_id=$site1 LIMIT 1")
shift2=$($sql "SELECT id FROM guard_shifts WHERE tenant_id=1 AND site_id=$site2 LIMIT 1")
person=$($sql "SELECT id FROM people WHERE tenant_id=1 ORDER BY id LIMIT 1")
actor=$($sql "SELECT id FROM users WHERE email='admin@asiscontrol.local'")
for item in "$site1" "$site2" "$shift1" "$shift2" "$person" "$actor"; do
  test -n "$item" && test "$item" -gt 0
done
# Inserts in bulk for each resource type, forcing >200 newer off-site events
# ahead of an older valid record. The endpoint must filter in SQL BEFORE LIMIT.
python3 - "$shift1" "$shift2" "$site1" "$site2" "$person" "$actor" > /tmp/scoped-volume.sql <<'PY'
import sys
shift1,shift2,site1,site2,person,actor=map(int,sys.argv[1:])
def insert(table,columns,rows):
    print(f"INSERT INTO {table} ({columns}) VALUES\n  "+",\n  ".join(rows)+";")
seq=range(1,221)
insert("visits","tenant_id,person_id,shift_id,site_id,destination,host,reason,entered_at,status",
    [f"(1,{person},{shift2},{site2},'QA externo','QA','QA_LIMIT_OFFSITE_VISIT_{n}',DATE_ADD(NOW(),INTERVAL {n} SECOND),'inside')" for n in seq]
    +[f"(1,{person},{shift1},{site1},'QA local','QA','QA_LIMIT_ALLOWED_VISIT',DATE_SUB(NOW(),INTERVAL 1 DAY),'inside')"])
insert("vehicles","tenant_id,shift_id,site_id,plate,driver_name,vehicle_type,destination,entered_at,status",
    [f"(1,{shift2},{site2},'QA_LIMIT_OFFSITE_VEHICLE_{n}','QA conductor','Automovil','QA externo',DATE_ADD(NOW(),INTERVAL {n} SECOND),'inside')" for n in seq]
    +[f"(1,{shift1},{site1},'QA_LIMIT_ALLOWED_VEHICLE','QA conductor','Automovil','QA local',DATE_SUB(NOW(),INTERVAL 1 DAY),'inside')"])
insert("packages","tenant_id,shift_id,tracking,courier,recipient,destination,received_at,status",
    [f"(1,{shift2},'QA_LIMIT_OFFSITE_PACKAGE_{n}','QA Courier','QA externo','QA externo',DATE_ADD(NOW(),INTERVAL {n} SECOND),'pending')" for n in seq]
    +[f"(1,{shift1},'QA_LIMIT_ALLOWED_PACKAGE','QA Courier','QA local','QA local',DATE_SUB(NOW(),INTERVAL 1 DAY),'pending')"])
insert("incidents","tenant_id,shift_id,user_id,title,severity,area,description,status,created_at",
    [f"(1,{shift2},{actor},'QA_LIMIT_OFFSITE_INCIDENT_{n}','media','QA externo','QA externo','open',DATE_ADD(NOW(),INTERVAL {n} SECOND))" for n in seq]
    +[f"(1,{shift1},{actor},'QA_LIMIT_ALLOWED_INCIDENT','media','QA local','QA local','open',DATE_SUB(NOW(),INTERVAL 1 DAY))"])
PY
mysql -h 127.0.0.1 -u root vzwquesv_app < /tmp/scoped-volume.sql
curl -fsS -c /tmp/scoped-volume.cookies -H 'Content-Type: application/json' \
 -d '{"email":"guardia@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/scoped-volume-login.json
for resource in visits vehicles packages incidents; do
  curl -fsS -b /tmp/scoped-volume.cookies "$base/$resource" >"/tmp/scoped-volume-$resource.json"
done
curl -fsS -b /tmp/scoped-volume.cookies "$base/bootstrap" > /tmp/scoped-volume-bootstrap.json

python3 - <<'PY'
import json
cases={
 'visits':('reason','QA_LIMIT_ALLOWED_VISIT'),
 'vehicles':('plate','QA_LIMIT_ALLOWED_VEHICLE'),
 'packages':('tracking','QA_LIMIT_ALLOWED_PACKAGE'),
 'incidents':('title','QA_LIMIT_ALLOWED_INCIDENT'),
}
bootstrap=json.load(open('/tmp/scoped-volume-bootstrap.json'))['data']
for resource,(field,allowed) in cases.items():
    direct=json.load(open(f'/tmp/scoped-volume-{resource}.json'))['data']
    for label,rows in [('direct',direct),('bootstrap',bootstrap[resource])]:
        values={str(x.get(field,'')) for x in rows}
        assert allowed in values, f'{resource} {label} lost valid site record behind unrelated LIMIT'
        assert not any(v.startswith('QA_LIMIT_OFFSITE_') for v in values), f'{resource} {label} leaked other site'
print('Operational lists: >200 unrelated off-site rows cannot mask valid site records PASS')
PY
