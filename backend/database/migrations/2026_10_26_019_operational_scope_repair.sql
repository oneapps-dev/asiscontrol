-- Migration 019: repair inherited auto-scopes without touching explicit grants.
-- Operators require a valid scoped location; no implicit tenant-wide fallback.
INSERT IGNORE INTO user_scopes(tenant_id,user_id,scope_type,scope_id,granted_by)
SELECT u.tenant_id,u.id,'site',u.site_id,NULL
FROM users u INNER JOIN sites s ON s.id=u.site_id AND s.tenant_id=u.tenant_id
WHERE u.role IN ('guard','supervisor','coordinator','tech_operator');

-- Only migrate automatic grants (granted_by NULL); preserve explicit grants.
DELETE us FROM user_scopes us
INNER JOIN users u ON u.id=us.user_id AND u.tenant_id=us.tenant_id
WHERE u.role IN ('guard','supervisor','coordinator','tech_operator')
  AND us.scope_type='tenant' AND us.scope_id=u.tenant_id
  AND us.granted_by IS NULL;
