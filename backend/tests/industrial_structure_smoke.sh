#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api

curl -fsS -c /tmp/industry.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-industria@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/industry-login.json
csrf=$(python3 -c 'import json;print(json.load(open("/tmp/industry-login.json"))["csrf_token"])')
tenant_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM tenants WHERE name='QA Industria Central'")
site_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM sites WHERE tenant_id=$tenant_id AND name='QA Sede Principal'")
residential_site=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT s.id FROM sites s JOIN tenants t ON t.id=s.tenant_id WHERE t.name='QA Residencial Parque' LIMIT 1")
curl -fsS -b /tmp/industry.cookies "$base/organization/nodes" > /tmp/organization-initial.json
python3 - <<'PY'
import json
nodes=json.load(open('/tmp/organization-initial.json'))['data']
codes={x['code'] for x in nodes}
assert {'EDIF-QA','PISO-QA-1','AREA-QA'}.issubset(codes)
assert len({x['tenant_id'] for x in nodes})==1
PY

status=$(curl -sS -o /tmp/org-cross-site.json -w '%{http_code}' -b /tmp/industry.cookies \
 -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "{\"site_id\":$residential_site,\"node_type\":\"office\",\"name\":\"Sede ajena\",\"code\":\"SITE-LEAK\"}" \
 "$base/organization/nodes")
test "$status" = "403" || { echo "Organization cross-tenant create: $status"; exit 1; }

status=$(curl -sS -o /tmp/org-created-parent.json -w '%{http_code}' -b /tmp/industry.cookies \
 -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "{\"site_id\":$site_id,\"node_type\":\"building\",\"name\":\"Bloque de pruebas\",\"code\":\"BLOQUE-QA\"}" \
 "$base/organization/nodes")
test "$status" = "201" || { echo "Create parent: $status"; cat /tmp/org-created-parent.json; exit 1; }
parent_id=$(python3 -c 'import json;print(json.load(open("/tmp/org-created-parent.json"))["id"])')
status=$(curl -sS -o /tmp/org-created-child.json -w '%{http_code}' -b /tmp/industry.cookies \
 -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "{\"site_id\":$site_id,\"parent_id\":$parent_id,\"node_type\":\"office\",\"name\":\"Oficina de pruebas\",\"code\":\"OFICINA-QA\"}" \
 "$base/organization/nodes")
test "$status" = "201" || { echo "Create child: $status"; exit 1; }
child_id=$(python3 -c 'import json;print(json.load(open("/tmp/org-created-child.json"))["id"])')
status=$(curl -sS -o /tmp/org-cycle.json -w '%{http_code}' -b /tmp/industry.cookies \
 -X PATCH -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "{\"parent_id\":$child_id}" "$base/organization/nodes/$parent_id")
test "$status" = "422" || { echo "Hierarchy cycle accepted: $status"; exit 1; }
grep -q ORG_STRUCTURE_CYCLE /tmp/org-cycle.json

status=$(curl -sS -o /tmp/org-not-empty.json -w '%{http_code}' -b /tmp/industry.cookies \
 -X DELETE -H "X-CSRF-Token: $csrf" "$base/organization/nodes/$parent_id")
test "$status" = "409" || { echo "Parent with children deactivated: $status"; exit 1; }

employee_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM people WHERE tenant_id=$tenant_id AND document_number=CONCAT('QAEMP',$tenant_id)")
area_id=$(mysql -h 127.0.0.1 -u root vzwquesv_app -N -e "SELECT id FROM organization_nodes WHERE tenant_id=$tenant_id AND code='AREA-QA'")
status=$(curl -sS -o /tmp/org-assigned.json -w '%{http_code}' -b /tmp/industry.cookies \
 -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "{\"node_id\":$area_id,\"person_id\":$employee_id,\"relationship\":\"employee\"}" \
 "$base/organization/people")
test "$status" = "201" || { echo "Valid employee assignment: $status"; exit 1; }
curl -fsS -b /tmp/industry.cookies "$base/organization/people?node_id=$area_id" > /tmp/organization-people.json
python3 - <<'PY'
import json
assert any(r['full_name']=='Empleado industrial ficticio QA' and r['status']=='active' for r in json.load(open('/tmp/organization-people.json'))['data'])
PY

status=$(curl -sS -o /tmp/org-residential-read.json -w '%{http_code}' \
 -c /tmp/residential-org.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-residencial@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login")
test "$status" = "200" || { echo "Residential login: $status"; exit 1; }
status=$(curl -sS -o /tmp/org-residential-denied.json -w '%{http_code}' \
 -b /tmp/residential-org.cookies "$base/organization/nodes")
test "$status" = "403" || { echo "Residential tenant could read corporate nodes: $status"; exit 1; }
echo 'Business hierarchy: tenant isolation, hierarchy cycles and employee assignment PASS'
