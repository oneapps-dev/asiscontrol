#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
curl -fsS -c /tmp/quota-owner.cookies -H 'Content-Type: application/json' \
  -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
  "$base/auth/login" > /tmp/quota-owner-login.json
csrf=$(python3 -c 'import json;print(json.load(open("/tmp/quota-owner-login.json"))["csrf_token"])')
mysql -h 127.0.0.1 -u root vzwquesv_app -e \
  "INSERT INTO plan_limits(plan_id,resource_code,capacity)
   SELECT id,'users_operational',1 FROM subscription_plans WHERE code='starter' AND billing_period='monthly'
   ON DUPLICATE KEY UPDATE capacity=1"

payload='{"name":"QA Cupo Excedido","email":"qa-quota-one@asiscontrol.local","password":"qa-password-strong","role":"guard","site_id":1,"is_active":true}'
status=$(curl -sS -o /tmp/quota-first-denied.json -w '%{http_code}' -b /tmp/quota-owner.cookies \
 -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d "$payload" "$base/users")
test "$status" = "409" || { echo "Operational quota not enforced: $status"; cat /tmp/quota-first-denied.json; exit 1; }
grep -q PLAN_LIMIT_REACHED /tmp/quota-first-denied.json

mysql -h 127.0.0.1 -u root vzwquesv_app -e \
  "UPDATE plan_limits SET capacity=2 WHERE plan_id=(SELECT id FROM subscription_plans WHERE code='starter' AND billing_period='monthly' LIMIT 1) AND resource_code='users_operational'"
status=$(curl -sS -o /tmp/quota-second-created.json -w '%{http_code}' -b /tmp/quota-owner.cookies \
 -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d "$payload" "$base/users")
test "$status" = "201" || { echo "Quota increase did not allow user: $status"; cat /tmp/quota-second-created.json; exit 1; }

payload='{"name":"QA Usuario Inactivo","email":"qa-quota-inactive@asiscontrol.local","password":"qa-password-strong","role":"guard","site_id":1,"is_active":false}'
status=$(curl -sS -o /tmp/quota-inactive-created.json -w '%{http_code}' -b /tmp/quota-owner.cookies \
 -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d "$payload" "$base/users")
test "$status" = "201" || { echo "Creating inactive user failed: $status"; exit 1; }
inactive_id=$(python3 -c 'import json;print(json.load(open("/tmp/quota-inactive-created.json"))["id"])')
status=$(curl -sS -o /tmp/quota-reactivate-denied.json -w '%{http_code}' -b /tmp/quota-owner.cookies \
 -X PATCH -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d '{"role":"guard","is_active":true}' "$base/users/$inactive_id")
test "$status" = "409" || { echo "Reactivation bypassed quota: $status"; exit 1; }
mysql -h 127.0.0.1 -u root vzwquesv_app -N -e \
  "SELECT is_active FROM users WHERE id=$inactive_id" | grep -q '^0$'

echo 'User quotas: category capacity, successful allocation and reactivation denial PASS'
