-- Migration 016: master role ceilings, downward-only restrictions and scope baseline.
-- The existing user_scopes table is reused; all existing user IDs remain unchanged.
CREATE TABLE IF NOT EXISTS master_roles (
 code VARCHAR(32) PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 is_operational TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO master_roles (code,name,is_operational) VALUES
 ('superadmin','CREAMOX global',0),('admin','Administrador',0),
 ('supervisor','Supervisor',1),('coordinator','Coordinador',1),
 ('guard','Vigilante',1),('auditor','Auditor',0),
 ('resident','Residente',0),('tech_operator','Operador de Medios Tecnológicos',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),is_operational=VALUES(is_operational);

CREATE TABLE IF NOT EXISTS master_role_permissions (
 role_code VARCHAR(32) NOT NULL,
 permission_code VARCHAR(80) NOT NULL,
 PRIMARY KEY (role_code,permission_code),
 CONSTRAINT fk_master_permissions_role FOREIGN KEY(role_code) REFERENCES master_roles(code),
 CONSTRAINT fk_master_permissions_code FOREIGN KEY(permission_code) REFERENCES permissions(code)
) ENGINE=InnoDB;

-- Initialize master ceilings from approved tenant baseline. Changes to tenant
-- grants can subsequently REMOVE but cannot ADD permissions beyond this ceiling.
INSERT IGNORE INTO master_role_permissions (role_code, permission_code)
SELECT rp.role,rp.permission_code FROM role_permissions rp
WHERE rp.tenant_id=1 AND rp.granted=1 AND rp.role<>'superadmin';
INSERT IGNORE INTO master_role_permissions (role_code, permission_code)
SELECT 'superadmin',p.code FROM permissions p;

CREATE TABLE IF NOT EXISTS tenant_role_restrictions (
 tenant_id BIGINT UNSIGNED NOT NULL,
 role_code VARCHAR(32) NOT NULL,
 permission_code VARCHAR(80) NOT NULL,
 restricted_by BIGINT UNSIGNED NULL,
 changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_id,role_code,permission_code),
 CONSTRAINT fk_tenant_restrictions_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id),
 CONSTRAINT fk_tenant_restrictions_role FOREIGN KEY(role_code) REFERENCES master_roles(code),
 CONSTRAINT fk_tenant_restrictions_permission FOREIGN KEY(permission_code) REFERENCES permissions(code),
 CONSTRAINT fk_tenant_restrictions_actor FOREIGN KEY(restricted_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE user_scopes MODIFY scope_type ENUM('security_company','tenant','site','access_point','unit') NOT NULL;
INSERT IGNORE INTO user_scopes (tenant_id,user_id,scope_type,scope_id)
SELECT u.tenant_id,u.id,
 CASE WHEN u.role IN ('guard','coordinator','supervisor','tech_operator') AND u.site_id IS NOT NULL THEN 'site' ELSE 'tenant' END,
 CASE WHEN u.role IN ('guard','coordinator','supervisor','tech_operator') AND u.site_id IS NOT NULL THEN u.site_id ELSE u.tenant_id END
FROM users u;
