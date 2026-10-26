-- Cierre de hallazgos QA 08-09-2026.
-- Ejecutar después de 2026_09_08_013_operational_completion.sql.
-- La migración es segura para instalaciones existentes: amplía enums y
-- conserva todos los registros históricos.

ALTER TABLE users
  MODIFY role ENUM('superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'auditor', 'resident', 'tech_operator') NOT NULL DEFAULT 'guard';

ALTER TABLE role_permissions
  MODIFY role ENUM('superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'auditor', 'resident', 'tech_operator') NOT NULL;

ALTER TABLE login_attempts
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER email,
  ADD COLUMN user_agent VARCHAR(4000) NULL AFTER ip_address,
  ADD COLUMN location VARCHAR(180) NULL AFTER user_agent,
  ADD INDEX idx_login_attempts_user (user_id, attempted_at);

ALTER TABLE vehicles
  ADD COLUMN parking_space VARCHAR(60) NULL AFTER destination,
  ADD INDEX idx_vehicles_parking (tenant_id, parking_space, status);

ALTER TABLE packages
  MODIFY package_type ENUM('documento', 'paquete', 'mercancia', 'alimento', 'servicios', 'otro') NOT NULL DEFAULT 'paquete',
  ADD COLUMN service_type VARCHAR(120) NULL AFTER package_type,
  ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER destination,
  ADD COLUMN recipient_resident_id BIGINT UNSIGNED NULL AFTER unit_id,
  ADD INDEX idx_packages_unit (tenant_id, unit_id, status);

INSERT INTO permissions (code, name, description) VALUES
  ('bookings.view', 'Consultar reservas', 'Ver disponibilidad y reservas propias o asignadas'),
  ('access_events.register', 'Registrar accesos', 'Registrar entradas y salidas con trazabilidad'),
  ('employees.manage', 'Gestionar empleados', 'Crear fichas, horarios y asistencia'),
  ('shifts.monitor', 'Supervisar turnos', 'Consultar puestos activos y cerrar turnos con motivo')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, 'admin', 'shifts.monitor', 1 FROM tenants t
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, 'resident', p.code, 1
FROM tenants t INNER JOIN permissions p ON p.code IN ('bookings.view')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, 'tech_operator', p.code, 1
FROM tenants t INNER JOIN permissions p ON p.code IN ('bookings.view', 'devices.view', 'access_events.register')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

-- El operador técnico puede consultar el catálogo de permisos, pero no recibe
-- permisos operativos implícitos adicionales. Los demás roles conservan las
-- asignaciones creadas por las migraciones anteriores.

INSERT INTO schema_migrations (migration)
VALUES ('2026_09_12_014_qa_completion.sql')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
