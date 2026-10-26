-- Supervisors manage devices and therefore need read access to their inventory.
INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, 'supervisor', 'devices.view', 1
FROM tenants t
JOIN permissions p ON p.code = 'devices.view'
ON DUPLICATE KEY UPDATE granted = VALUES(granted);
