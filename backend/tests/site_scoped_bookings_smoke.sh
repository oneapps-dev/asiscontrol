#!/usr/bin/env bash
set -euo pipefail
base=http://127.0.0.1:8765/api
sql='mysql -h 127.0.0.1 -u root vzwquesv_app -N -e'
site1=$($sql "SELECT MIN(id) FROM sites WHERE tenant_id=1")
site2=$($sql "SELECT id FROM sites WHERE tenant_id=1 AND name='QA Sede Restringida' LIMIT 1")
test -n "$site1" && test -n "$site2"

mysql -h 127.0.0.1 -u root vzwquesv_app <<SQL
INSERT INTO booking_resources(tenant_id,site_id,name,resource_type,pricing_mode,is_active)
VALUES(1,$site2,'QA Reserva Recurso Otra Sede','sala','hora',1);
SQL
other_resource=$($sql "SELECT id FROM booking_resources WHERE tenant_id=1 AND name='QA Reserva Recurso Otra Sede' ORDER BY id DESC LIMIT 1")
test -n "$other_resource"

curl -fsS -c /tmp/book-site.cookies -H 'Content-Type: application/json' \
 -d '{"email":"qa-site-fileadmin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/book-site-login.json
csrf=$(python3 -c 'import json;print(json.load(open("/tmp/book-site-login.json"))["csrf_token"])')
curl -fsS -c /tmp/book-wide.cookies -H 'Content-Type: application/json' \
 -d '{"email":"admin@asiscontrol.local","password":"demo1234"}' \
 "$base/auth/login" >/tmp/book-wide-login.json
wide_csrf=$(python3 -c 'import json;print(json.load(open("/tmp/book-wide-login.json"))["csrf_token"])')

status=$(curl -sS -o /tmp/book-resource-valid.json -w '%{http_code}' \
 -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "{\"name\":\"QA Reserva Permitida\",\"resource_type\":\"sala\",\"site_id\":$site1}" \
 "$base/booking-resources")
test "$status" = "201" || { echo "Authorized resource create failed: $status"; cat /tmp/book-resource-valid.json; exit 1; }
resource=$(python3 -c 'import json;print(json.load(open("/tmp/book-resource-valid.json"))["id"])')

for payload in "{\"name\":\"QA Reserva Denegada\",\"resource_type\":\"sala\",\"site_id\":$site2}" \
 '{"name":"QA Recurso Sin Sede","resource_type":"sala"}'; do
 status=$(curl -sS -o /tmp/book-resource-denied.json -w '%{http_code}' \
  -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d "$payload" "$base/booking-resources")
 test "$status" = "403" || { echo "Cross-site or unscoped resource created: $status"; exit 1; }
done

curl -fsS -b /tmp/book-site.cookies "$base/booking-resources" >/tmp/book-resources-listed.json
RES="$resource" OTHER="$other_resource" python3 - <<'PY'
import json,os
ids={str(row['id']) for row in json.load(open('/tmp/book-resources-listed.json'))['data']}
assert os.environ['RES'] in ids and os.environ['OTHER'] not in ids, {'allowed':os.environ['RES'],'other':os.environ['OTHER'],'visible_ids':sorted(ids)}
PY

day=$(date -u -d '+150 days' '+%Y-%m-%d')
start="$day 02:20:00"
end="$day 03:20:00"
authorized_payload=$(printf '{"resource_id":%s,"requester_name":"QA Reserva Autorizada","purpose":"QA sitio permitido","starts_at":"%s","ends_at":"%s"}' "$resource" "$start" "$end")
status=$(curl -sS -o /tmp/book-valid-create.json -w '%{http_code}' \
 -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "$authorized_payload" "$base/bookings")
test "$status" = "201" || { echo "Authorized booking create failed: $status"; cat /tmp/book-valid-create.json; exit 1; }
booking=$(python3 -c 'import json;print(json.load(open("/tmp/book-valid-create.json"))["id"])')

denied_payload=$(printf '{"resource_id":%s,"requester_name":"QA Reserva Ajena","purpose":"QA no permitido","starts_at":"%s","ends_at":"%s"}' "$other_resource" "$start" "$end")
status=$(curl -sS -o /tmp/book-cross-create.json -w '%{http_code}' \
 -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "$denied_payload" "$base/bookings")
test "$status" = "403" || { echo "Cross-site booking creation accepted: $status"; cat /tmp/book-cross-create.json; exit 1; }

status=$(curl -sS -o /tmp/book-other-create.json -w '%{http_code}' \
 -b /tmp/book-wide.cookies -H "X-CSRF-Token: $wide_csrf" -H 'Content-Type: application/json' \
 -d "$denied_payload" "$base/bookings")
test "$status" = "201" || { echo "Wide admin booking failed: $status"; cat /tmp/book-other-create.json; exit 1; }
other_booking=$(python3 -c 'import json;print(json.load(open("/tmp/book-other-create.json"))["id"])')

curl -fsS -b /tmp/book-site.cookies "$base/bookings" >/tmp/book-list.json
ALLOWED="$booking" DENIED="$other_booking" python3 - <<'PY'
import json,os
rows=json.load(open('/tmp/book-list.json'))['data']
ids={str(x['id']) for x in rows}
assert os.environ['ALLOWED'] in ids and os.environ['DENIED'] not in ids
PY
for operation in status guests; do
 if test "$operation" = "status"; then method=POST; body='{"status":"confirmed"}'; else method=GET; body=''; fi
 if test "$method" = POST; then
   status=$(curl -sS -o /tmp/book-cross-action.json -w '%{http_code}' \
     -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
     -d "$body" "$base/bookings/$other_booking/$operation")
 else
   status=$(curl -sS -o /tmp/book-cross-action.json -w '%{http_code}' \
     -b /tmp/book-site.cookies "$base/bookings/$other_booking/$operation")
 fi
 test "$status" = "403" || { echo "Cross-site booking $operation succeeded: $status"; exit 1; }
done

status=$(curl -sS -o /tmp/book-cross-delete.json -w '%{http_code}' \
 -X DELETE -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" \
 "$base/bookings/$other_booking")
test "$status" = "403" || { echo "Cross-site booking delete succeeded: $status"; exit 1; }

status=$(curl -sS -o /tmp/book-valid-status.json -w '%{http_code}' \
 -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d '{"status":"confirmed"}' "$base/bookings/$booking/status")
test "$status" = "200" || { echo "Authorized booking status change failed: $status"; cat /tmp/book-valid-status.json; exit 1; }
current=$($sql "SELECT status FROM bookings WHERE tenant_id=1 AND id=$booking")
test "$current" = "confirmed"

# Booking guests inherit the booking's site: neither listing nor mutating a
# guest may bypass the booking authorization.
status=$(curl -sS -o /tmp/book-valid-guest.json -w '%{http_code}' \
 -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d '{"guest_name":"Invitado QA Permitido"}' "$base/bookings/$booking/guests")
test "$status" = "201" || { echo "Allowed guest assignment failed: $status"; cat /tmp/book-valid-guest.json; exit 1; }
guest=$(python3 -c 'import json;print(json.load(open("/tmp/book-valid-guest.json"))["id"])')
curl -fsS -b /tmp/book-site.cookies "$base/bookings/$booking/guests" >/tmp/book-valid-guests.json
GUEST="$guest" python3 - <<'PY'
import json,os
data=json.load(open('/tmp/book-valid-guests.json'))['data']
assert any(str(x['id'])==os.environ['GUEST'] for x in data)
PY
status=$(curl -sS -o /tmp/book-guest-checkin.json -w '%{http_code}' \
 -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d '{}' "$base/reservation-guests/$guest/check-in")
test "$status" = "200" || { echo "Allowed guest check-in failed: $status"; cat /tmp/book-guest-checkin.json; exit 1; }

status=$(curl -sS -o /tmp/book-other-guest.json -w '%{http_code}' \
 -b /tmp/book-wide.cookies -H "X-CSRF-Token: $wide_csrf" -H 'Content-Type: application/json' \
 -d '{"guest_name":"Invitado QA Otra Sede"}' "$base/bookings/$other_booking/guests")
test "$status" = "201" || { echo "Tenant admin guest creation failed: $status"; cat /tmp/book-other-guest.json; exit 1; }
other_guest=$(python3 -c 'import json;print(json.load(open("/tmp/book-other-guest.json"))["id"])')
status=$(curl -sS -o /tmp/book-cross-guest-checkin.json -w '%{http_code}' \
 -b /tmp/book-site.cookies -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d '{}' "$base/reservation-guests/$other_guest/check-in")
test "$status" = "403" || { echo "Cross-site guest check-in succeeded: $status"; exit 1; }
echo 'Bookings: scoped resources, booking lifecycle, guest ownership, and cross-site denial PASS'
