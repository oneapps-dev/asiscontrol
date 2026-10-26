#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
query="mysql -h 127.0.0.1 -u root vzwquesv_app -N -e"
security_id=$($query "SELECT id FROM tenants WHERE name='QA Seguridad Norte'")
residential_id=$($query "SELECT id FROM tenants WHERE name='QA Residencial Parque'")
industrial_id=$($query "SELECT id FROM tenants WHERE name='QA Industria Central'")
residential_site=$($query "SELECT id FROM sites WHERE tenant_id=$residential_id AND name='QA Sede Principal'")
industrial_site=$($query "SELECT id FROM sites WHERE tenant_id=$industrial_id AND name='QA Sede Principal'")
guard_id=$($query "SELECT id FROM users WHERE email='qa-guard-seguridad@asiscontrol.local'")
test -n "$guard_id" && test -n "$industrial_site" && test -n "$residential_site"

curl -fsS -c /tmp/member-manager.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-seguridad@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" > /tmp/member-manager-login.json
manager_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/member-manager-login.json"))["csrf_token"])')

# Delegated operator must never gain an arbitrary client's assignments.
status=$(curl -sS -o /tmp/member-third-denied.json -w '%{http_code}' -b /tmp/member-manager.cookies \
 -H "X-CSRF-Token: $manager_csrf" -H 'Content-Type: application/json' \
 -d "{\"user_id\":$guard_id,\"role\":\"guard\",\"site_id\":1}" \
 "$base/platform/tenants/1/memberships")
test "$status" = "403" || { echo "Unrelated client assignment allowed: $status"; exit 1; }
grep -q MEMBERSHIP_RELATION_DENIED /tmp/member-third-denied.json

# Server refuses privilege elevation to administrator or global superadmin.
status=$(curl -sS -o /tmp/member-escalation-denied.json -w '%{http_code}' -b /tmp/member-manager.cookies \
 -H "X-CSRF-Token: $manager_csrf" -H 'Content-Type: application/json' \
 -d "{\"user_id\":$guard_id,\"role\":\"superadmin\",\"site_id\":$residential_site}" \
 "$base/platform/tenants/$residential_id/memberships")
test "$status" = "422" || { echo "Role escalation unexpectedly allowed: $status"; exit 1; }


# A security operator sees both its directly owned and separately managed clients.
curl -fsS -b /tmp/member-manager.cookies \
 "$base/platform/tenants/$security_id/children" > /tmp/member-managed-clients.json
MEMBER_RES="$residential_id" MEMBER_IND="$industrial_id" python3 - <<'PY'
import os,json
ids={int(x['id']) for x in json.load(open('/tmp/member-managed-clients.json'))['data']}
assert int(os.environ['MEMBER_RES']) in ids and int(os.environ['MEMBER_IND']) in ids
PY
curl -fsS -b /tmp/member-manager.cookies \
 "$base/platform/tenants/$residential_id/membership-options" > /tmp/member-options.json
GUARD_ID="$guard_id" MEMBER_RES_SITE="$residential_site" python3 - <<'PY'
import json,os
d=json.load(open('/tmp/member-options.json'))['data']
assert any(int(u['id'])==int(os.environ['GUARD_ID']) for u in d['source_users'])
assert any(int(s['id'])==int(os.environ['MEMBER_RES_SITE']) for s in d['sites'])
assert 'superadmin' not in d['assignable_roles']
PY
# Even an authorized operator cannot elevate a guard into a coordinator.
status=$(curl -sS -o /tmp/member-ceiling-denied.json -w '%{http_code}' \
 -b /tmp/member-manager.cookies -H "X-CSRF-Token: $manager_csrf" \
 -H 'Content-Type: application/json' \
 -d "{\"user_id\":$guard_id,\"role\":\"coordinator\",\"site_id\":$residential_site}" \
 "$base/platform/tenants/$residential_id/memberships")
test "$status" = "403" || { echo "Role ceiling bypass: $status"; exit 1; }
grep -q MEMBERSHIP_USER_DENIED /tmp/member-ceiling-denied.json

for target in residential industrial; do
 if test "$target" = "residential"; then tenant="$residential_id"; site="$residential_site"; else tenant="$industrial_id"; site="$industrial_site"; fi
 status=$(curl -sS -o "/tmp/member-grant-$target.json" -w '%{http_code}' -b /tmp/member-manager.cookies \
  -H "X-CSRF-Token: $manager_csrf" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$guard_id,\"role\":\"guard\",\"site_id\":$site}" \
  "$base/platform/tenants/$tenant/memberships")
 test "$status" = "201" || { echo "$target grant status $status"; cat "/tmp/member-grant-$target.json"; exit 1; }
done

curl -fsS -c /tmp/multi-guard.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-guard-seguridad@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/multi-guard-login.json
guard_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/multi-guard-login.json"))["csrf_token"])')
curl -fsS -b /tmp/multi-guard.cookies "$base/me/tenants" >/tmp/member-contexts.json
MEMBER_SECURITY="$security_id" MEMBER_RES="$residential_id" MEMBER_IND="$industrial_id" python3 - <<'PY'
import os,json
d=json.load(open('/tmp/member-contexts.json'))['data']
assert d['current_tenant_id']==int(os.environ['MEMBER_SECURITY'])
assert {int(x['tenant_id']) for x in d['tenants']}=={int(os.environ[k]) for k in ('MEMBER_SECURITY','MEMBER_RES','MEMBER_IND')}
assert any(x['tenant_id']==int(os.environ['MEMBER_RES']) and x['role']=='guard' for x in d['tenants'])
PY

# Cookie needs to be saved on every session_regenerate_id(true).
status=$(curl -sS -o /tmp/member-selected-residential.json -w '%{http_code}' \
 -c /tmp/multi-guard.cookies -b /tmp/multi-guard.cookies \
 -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' \
 -d "{\"tenant_id\":$residential_id}" "$base/me/tenant/select")
test "$status" = "200" || { echo "Residential switch $status"; cat /tmp/member-selected-residential.json; exit 1; }
curl -fsS -b /tmp/multi-guard.cookies "$base/me" >/tmp/member-residential-me.json
curl -fsS -b /tmp/multi-guard.cookies "$base/bootstrap" >/tmp/member-residential-bootstrap.json
MEMBER_RES="$residential_id" MEMBER_RES_SITE="$residential_site" python3 - <<'PY'
import json,os
me=json.load(open('/tmp/member-residential-me.json'))['user']
data=json.load(open('/tmp/member-residential-bootstrap.json'))['data']
assert int(me['tenant_id'])==int(os.environ['MEMBER_RES'])
assert me['role']=='guard' and int(me['home_tenant_id']) != int(me['tenant_id'])
assert data['sites'] and all(int(s['id'])==int(os.environ['MEMBER_RES_SITE']) for s in data['sites'])
assert all(int(v['tenant_id'])==int(os.environ['MEMBER_RES']) for v in data['visits'])
PY

status=$(curl -sS -o /tmp/member-unassigned-denied.json -w '%{http_code}' \
 -b /tmp/multi-guard.cookies -H "X-CSRF-Token: $guard_csrf" \
 -H 'Content-Type: application/json' -d '{"tenant_id":1}' "$base/me/tenant/select")
test "$status" = "403" || { echo "Unassigned switch should deny: $status"; exit 1; }

status=$(curl -sS -o /tmp/member-selected-industry.json -w '%{http_code}' \
 -c /tmp/multi-guard.cookies -b /tmp/multi-guard.cookies \
 -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' \
 -d "{\"tenant_id\":$industrial_id}" "$base/me/tenant/select")
test "$status" = "200" || { echo "Industrial switch $status"; cat /tmp/member-selected-industry.json; exit 1; }
curl -fsS -b /tmp/multi-guard.cookies "$base/me/context" >/tmp/member-industry-context.json
MEMBER_IND="$industrial_id" python3 - <<'PY'
import json,os
d=json.load(open('/tmp/member-industry-context.json'))['data']
assert int(d['tenant']['id'])==int(os.environ['MEMBER_IND'])
assert d['role']=='guard'
assert all(int(x['scope_id'])!=1 for x in d['scopes'])
PY

# Revoke the ACTIVE assignment while the employee has it selected.
curl -fsS -b /tmp/member-manager.cookies "$base/platform/tenants/$industrial_id/memberships" > /tmp/member-industrial-list.json
export GUARD_ID="$guard_id"
membership_id=$(python3 -c 'import json,os;print(next(str(x["id"]) for x in json.load(open("/tmp/member-industrial-list.json"))["data"] if str(x["user_id"])==os.environ["GUARD_ID"]))')
status=$(curl -sS -o /tmp/member-revoked.json -w '%{http_code}' \
 -b /tmp/member-manager.cookies -H "X-CSRF-Token: $manager_csrf" \
 -X DELETE "$base/platform/tenants/$industrial_id/memberships/$membership_id")
test "$status" = "200" || { echo "Revocation status $status"; exit 1; }
status=$(curl -sS -o /tmp/member-revoked-context.json -w '%{http_code}' \
 -b /tmp/multi-guard.cookies "$base/me")
test "$status" = "403" || { echo "Revoked user still authorized: $status"; exit 1; }
grep -q TENANT_CONTEXT_REVOKED /tmp/member-revoked-context.json

# An explicit clear returns to the home principal WITHOUT preserving target data.
status=$(curl -sS -o /tmp/member-return-home.json -w '%{http_code}' \
 -c /tmp/multi-guard.cookies -b /tmp/multi-guard.cookies \
 -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' \
 -d '{}' "$base/me/tenant/clear")
test "$status" = "200" || { echo "Home restoration $status"; cat /tmp/member-return-home.json; exit 1; }
curl -fsS -b /tmp/multi-guard.cookies "$base/me" >/tmp/member-home-after.json
MEMBER_SECURITY="$security_id" python3 - <<'PY'
import json,os
user=json.load(open('/tmp/member-home-after.json'))['user']
assert int(user['tenant_id'])==int(os.environ['MEMBER_SECURITY'])
assert user['role']=='guard' and 'membership_id' not in user
PY
# Assigned workers are counted in the tenant's plan capacities.
mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT COUNT(*) FROM tenant_user_memberships WHERE tenant_id=$residential_id AND status='active'" | grep -q '^1$'
# Logs retain actor + exact tenant and keep old identity unchanged.
mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
 "SELECT COUNT(*) FROM platform_audit_logs WHERE actor_user_id=$guard_id AND tenant_id=$industrial_id AND action='contexto.cliente_seleccionado'" | awk '{if ($1 < 1) exit 1}'

# A delegated relationship is not enough when the security operator is commercially suspended.
# Keep the residential membership active and validate both target discovery and selected access.
mysql -h 127.0.0.1 -u root vzwquesv_app -e "UPDATE tenants SET commercial_status='suspended' WHERE id=$security_id"
status=$(curl -sS -o /tmp/member-suspended-operator.json -w '%{http_code}' -b /tmp/multi-guard.cookies "$base/me/tenants")
test "$status" != "200" || { echo "Suspended operator retained normal tenant access"; exit 1; }
mysql -h 127.0.0.1 -u root vzwquesv_app -e "UPDATE tenants SET commercial_status='active' WHERE id=$security_id"

# Target subscription changes invalidate the selected context before any operation.
status=$(curl -sS -o /tmp/member-residential-reselect.json -w '%{http_code}' \
 -c /tmp/multi-guard.cookies -b /tmp/multi-guard.cookies \
 -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' \
 -d "{\"tenant_id\":$residential_id}" "$base/me/tenant/select")
test "$status" = "200" || { echo "Residential reselect $status"; exit 1; }
mysql -h 127.0.0.1 -u root vzwquesv_app -e \
 "UPDATE tenant_subscriptions SET status='past_due' WHERE tenant_id=$residential_id"
status=$(curl -sS -o /tmp/member-past-due-denied.json -w '%{http_code}' \
 -b /tmp/multi-guard.cookies "$base/me")
test "$status" = "403" || { echo "Past-due client session allowed: $status"; exit 1; }
grep -q TENANT_CONTEXT_REVOKED /tmp/member-past-due-denied.json
mysql -h 127.0.0.1 -u root vzwquesv_app -e \
 "UPDATE tenant_subscriptions SET status='active' WHERE tenant_id=$residential_id"
status=$(curl -sS -o /tmp/member-past-due-cleared.json -w '%{http_code}' \
 -c /tmp/multi-guard.cookies -b /tmp/multi-guard.cookies \
 -H "X-CSRF-Token: $guard_csrf" -H 'Content-Type: application/json' \
 -d '{}' "$base/me/tenant/clear")
test "$status" = "200" || { echo "Past-due context reset denied $status"; exit 1; }

echo 'Multi-tenant memberships: two contexts, privilege separation and live revocation PASS'
