CREATE TABLE permissions (
  code VARCHAR(80) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  tenant_id BIGINT UNSIGNED NOT NULL,
  role ENUM('superadmin', 'admin', 'supervisor', 'guard', 'auditor') NOT NULL,
  permission_code VARCHAR(80) NOT NULL,
  granted TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, role, permission_code),
  CONSTRAINT fk_role_permissions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_code) REFERENCES permissions(code)
) ENGINE=InnoDB;

INSERT INTO permissions (code, name, description)
VALUES
  ('visits.manage', 'Gestionar visitas', 'Crear ingresos, salidas y redimir invitaciones'),
  ('vehicles.manage', 'Gestionar vehiculos', 'Registrar ingresos y salidas de vehiculos'),
  ('packages.manage', 'Gestionar paquetes', 'Registrar, entregar y firmar paquetes'),
  ('incidents.manage', 'Gestionar incidentes', 'Crear y cerrar incidentes operativos'),
  ('approvals.manage', 'Gestionar aprobaciones', 'Crear y decidir solicitudes de aprobacion'),
  ('reports.view', 'Ver reportes', 'Consultar y exportar reportes operativos'),
  ('audit.view', 'Ver auditoria', 'Consultar y exportar auditoria'),
  ('settings.manage', 'Administrar configuracion', 'Gestionar sedes, usuarios, unidades, residentes y restricciones'),
  ('imports.manage', 'Gestionar importaciones', 'Cargar CSV administrativos'),
  ('files.manage', 'Gestionar adjuntos', 'Subir, listar y descargar evidencias')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT id, 'superadmin', code, 1 FROM tenants CROSS JOIN permissions
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT id, 'admin', code, 1 FROM tenants CROSS JOIN permissions
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT tenants.id, default_permissions.role, default_permissions.permission_code, 1
FROM tenants
CROSS JOIN (
  SELECT 'supervisor' AS role, 'visits.manage' AS permission_code UNION ALL
  SELECT 'supervisor', 'vehicles.manage' UNION ALL
  SELECT 'supervisor', 'packages.manage' UNION ALL
  SELECT 'supervisor', 'incidents.manage' UNION ALL
  SELECT 'supervisor', 'approvals.manage' UNION ALL
  SELECT 'supervisor', 'reports.view' UNION ALL
  SELECT 'supervisor', 'audit.view' UNION ALL
  SELECT 'guard', 'visits.manage' UNION ALL
  SELECT 'guard', 'vehicles.manage' UNION ALL
  SELECT 'guard', 'packages.manage' UNION ALL
  SELECT 'guard', 'incidents.manage' UNION ALL
  SELECT 'guard', 'files.manage' UNION ALL
  SELECT 'auditor', 'reports.view' UNION ALL
  SELECT 'auditor', 'audit.view'
) AS default_permissions
ON DUPLICATE KEY UPDATE granted = VALUES(granted);
