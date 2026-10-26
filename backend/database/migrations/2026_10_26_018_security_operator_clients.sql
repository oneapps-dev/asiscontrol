-- Migration 018: a dedicated non-global permission for security operators.
INSERT INTO permissions (code,name,description)
VALUES ('clients.manage','Administrar clientes secundarios','Gestionar solo clientes subordinados y autorizados')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description);

INSERT IGNORE INTO master_role_permissions (role_code,permission_code)
VALUES ('tech_operator','clients.manage'),('superadmin','clients.manage');

INSERT INTO role_permissions (tenant_id,role,permission_code,granted)
SELECT t.id,'tech_operator','clients.manage',1 FROM tenants t
ON DUPLICATE KEY UPDATE granted=VALUES(granted);
