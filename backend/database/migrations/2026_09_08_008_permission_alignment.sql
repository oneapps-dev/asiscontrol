-- Align role grants with the canonical Asiscontrol permission catalog.
DELETE FROM role_permissions
WHERE role = 'admin' AND permission_code IN ('reports.view', 'audit.view', 'devices.manage');

DELETE FROM role_permissions
WHERE role = 'supervisor' AND permission_code = 'approvals.manage';

DELETE FROM role_permissions
WHERE role = 'guard' AND permission_code = 'bookings.manage';

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, 'admin', p.code, 1
FROM tenants t
JOIN permissions p ON p.code IN ('bookings.view', 'devices.view', 'devices.revoke')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, 'supervisor', p.code, 1
FROM tenants t
JOIN permissions p ON p.code IN ('bookings.view', 'offline.resolve', 'devices.manage', 'backups.manage', 'access_events.register')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);
