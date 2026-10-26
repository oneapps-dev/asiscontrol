-- Completa los flujos operativos definidos en la documentación de AsisControl.
-- La migración es idempotente: instalaciones existentes pueden ejecutarla sin
-- perder registros ni evidencias originales.

ALTER TABLE users
  MODIFY role ENUM('superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'auditor') NOT NULL DEFAULT 'guard';

ALTER TABLE role_permissions
  MODIFY role ENUM('superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'auditor') NOT NULL;

ALTER TABLE people
  MODIFY person_type ENUM('visitante', 'empleado', 'contratista', 'residente', 'domestico') NOT NULL DEFAULT 'visitante';

ALTER TABLE restrictions
  MODIFY level ENUM('informative', 'review', 'warning', 'block') NOT NULL DEFAULT 'review',
  ADD COLUMN valid_from DATETIME NULL AFTER reason,
  ADD COLUMN valid_until DATETIME NULL AFTER valid_from,
  ADD COLUMN scope_type ENUM('tenant', 'site', 'access_point') NOT NULL DEFAULT 'tenant' AFTER valid_until,
  ADD COLUMN scope_id BIGINT UNSIGNED NULL AFTER scope_type,
  ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER scope_id,
  ADD INDEX idx_restrictions_validity (tenant_id, level, valid_from, valid_until);

ALTER TABLE visits
  ADD COLUMN site_id BIGINT UNSIGNED NULL AFTER shift_id,
  ADD COLUMN access_point_id BIGINT UNSIGNED NULL AFTER site_id,
  ADD COLUMN device_id BIGINT UNSIGNED NULL AFTER access_point_id,
  ADD COLUMN operator_user_id BIGINT UNSIGNED NULL AFTER device_id,
  ADD COLUMN identification_method ENUM('huella', 'cedula', 'manual', 'qr', 'otro') NOT NULL DEFAULT 'manual' AFTER vehicle_plate,
  ADD COLUMN exit_type ENUM('normal', 'administrativa') NULL AFTER exit_observations,
  ADD COLUMN exit_by BIGINT UNSIGNED NULL AFTER exit_type,
  ADD COLUMN exit_reason VARCHAR(255) NULL AFTER exit_by,
  ADD CONSTRAINT fk_visits_site FOREIGN KEY (site_id) REFERENCES sites(id),
  ADD CONSTRAINT fk_visits_access_point FOREIGN KEY (access_point_id) REFERENCES access_points(id),
  ADD CONSTRAINT fk_visits_device FOREIGN KEY (device_id) REFERENCES devices(id),
  ADD CONSTRAINT fk_visits_operator FOREIGN KEY (operator_user_id) REFERENCES users(id),
  ADD CONSTRAINT fk_visits_exit_by FOREIGN KEY (exit_by) REFERENCES users(id),
  ADD INDEX idx_visits_scope (tenant_id, site_id, access_point_id, entered_at);

ALTER TABLE vehicles
  ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER shift_id,
  ADD COLUMN site_id BIGINT UNSIGNED NULL AFTER person_id,
  ADD COLUMN access_point_id BIGINT UNSIGNED NULL AFTER site_id,
  ADD COLUMN identification_method ENUM('huella', 'cedula', 'manual', 'qr', 'otro') NOT NULL DEFAULT 'manual' AFTER destination,
  ADD COLUMN exit_type ENUM('normal', 'administrativa') NULL AFTER exit_observations,
  ADD COLUMN exit_by BIGINT UNSIGNED NULL AFTER exit_type,
  ADD COLUMN exit_reason VARCHAR(255) NULL AFTER exit_by,
  ADD CONSTRAINT fk_vehicles_person FOREIGN KEY (person_id) REFERENCES people(id),
  ADD CONSTRAINT fk_vehicles_site FOREIGN KEY (site_id) REFERENCES sites(id),
  ADD CONSTRAINT fk_vehicles_access_point FOREIGN KEY (access_point_id) REFERENCES access_points(id),
  ADD CONSTRAINT fk_vehicles_exit_by FOREIGN KEY (exit_by) REFERENCES users(id),
  ADD INDEX idx_vehicles_scope (tenant_id, site_id, access_point_id, entered_at);

ALTER TABLE packages
  MODIFY status ENUM('pending', 'delivered', 'rejected', 'returned') NOT NULL DEFAULT 'pending',
  ADD COLUMN package_type ENUM('documento', 'paquete', 'mercancia', 'alimento', 'otro') NOT NULL DEFAULT 'paquete' AFTER tracking,
  ADD COLUMN courier_phone VARCHAR(50) NULL AFTER courier,
  ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER delivery_observations,
  ADD COLUMN return_reason VARCHAR(255) NULL AFTER rejection_reason,
  ADD COLUMN returned_at DATETIME NULL AFTER return_reason,
  ADD COLUMN returned_by BIGINT UNSIGNED NULL AFTER returned_at,
  ADD CONSTRAINT fk_packages_returned_by FOREIGN KEY (returned_by) REFERENCES users(id);

ALTER TABLE incidents
  MODIFY status ENUM('open', 'in_review', 'closed') NOT NULL DEFAULT 'open',
  ADD COLUMN resolution TEXT NULL AFTER closed_at,
  ADD COLUMN closed_by BIGINT UNSIGNED NULL AFTER resolution,
  ADD COLUMN linked_type ENUM('person', 'vehicle', 'package', 'access_point', 'unit') NULL AFTER closed_by,
  ADD COLUMN linked_id BIGINT UNSIGNED NULL AFTER linked_type,
  ADD CONSTRAINT fk_incidents_closed_by FOREIGN KEY (closed_by) REFERENCES users(id),
  ADD INDEX idx_incidents_link (tenant_id, linked_type, linked_id);

ALTER TABLE bookings
  MODIFY status ENUM('pre_reservation', 'pending', 'confirmed', 'pending_payment', 'paid', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
  ADD COLUMN guest_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER notes,
  ADD COLUMN qr_token_hash CHAR(64) NULL AFTER guest_count,
  ADD INDEX idx_bookings_status_dates (tenant_id, status, starts_at, ends_at);

ALTER TABLE residents
  ADD COLUMN password_hash VARCHAR(255) NULL AFTER person_id,
  ADD COLUMN password_set_at DATETIME NULL AFTER password_hash,
  ADD COLUMN last_login_at DATETIME NULL AFTER password_set_at;

CREATE TABLE IF NOT EXISTS employee_schedules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  employee_profile_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  tolerance_mins INT UNSIGNED NOT NULL DEFAULT 10,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_employee_schedules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_employee_schedules_profile FOREIGN KEY (employee_profile_id) REFERENCES employee_profiles(id),
  UNIQUE KEY uq_employee_schedule_day (employee_profile_id, day_of_week),
  INDEX idx_employee_schedules_tenant (tenant_id, day_of_week, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  employee_profile_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NULL,
  shift_id BIGINT UNSIGNED NULL,
  event_type ENUM('entrada', 'salida') NOT NULL,
  identification_method ENUM('huella', 'cedula', 'manual', 'qr', 'otro') NOT NULL DEFAULT 'manual',
  occurred_at DATETIME NOT NULL,
  source ENUM('online', 'offline', 'import') NOT NULL DEFAULT 'online',
  status ENUM('puntual', 'tarde', 'ausencia', 'incompleto', 'corregido') NOT NULL DEFAULT 'puntual',
  notes TEXT NULL,
  idempotency_key CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_attendance_profile FOREIGN KEY (employee_profile_id) REFERENCES employee_profiles(id),
  CONSTRAINT fk_attendance_person FOREIGN KEY (person_id) REFERENCES people(id),
  CONSTRAINT fk_attendance_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_attendance_shift FOREIGN KEY (shift_id) REFERENCES guard_shifts(id),
  UNIQUE KEY uq_attendance_idempotency (tenant_id, idempotency_key),
  INDEX idx_attendance_day (tenant_id, employee_profile_id, occurred_at),
  INDEX idx_attendance_status (tenant_id, status, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS record_corrections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  field_name VARCHAR(80) NOT NULL,
  previous_value TEXT NULL,
  new_value TEXT NULL,
  reason VARCHAR(255) NOT NULL,
  corrected_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_corrections_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_corrections_user FOREIGN KEY (corrected_by) REFERENCES users(id),
  INDEX idx_corrections_entity (tenant_id, entity_type, entity_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS restore_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  backup_job_id BIGINT UNSIGNED NOT NULL,
  confirmation_hash CHAR(64) NOT NULL,
  status ENUM('pending', 'approved', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  reason VARCHAR(255) NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_restore_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_restore_requester FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_restore_backup FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id),
  CONSTRAINT fk_restore_approver FOREIGN KEY (approved_by) REFERENCES users(id),
  INDEX idx_restore_status (tenant_id, status, created_at)
) ENGINE=InnoDB;

INSERT INTO permissions (code, name, description) VALUES
  ('employees.manage', 'Gestionar empleados', 'Crear fichas, horarios y asistencia de empleados'),
  ('contractors.manage', 'Gestionar contratistas', 'Gestionar contratistas, documentos y cualificaciones'),
  ('corrections.manage', 'Gestionar correcciones', 'Corregir datos conservando historial auditable'),
  ('attendance.view', 'Ver asistencia', 'Consultar asistencia, retardos y ausencias'),
  ('offline.resolve', 'Resolver conflictos offline', 'Revisar y resolver inconsistencias de sincronización'),
  ('backups.restore', 'Restaurar backups', 'Solicitar y aprobar restauraciones con doble confirmación'),
  ('reservation_guests.manage', 'Gestionar invitados de reservas', 'Crear invitados y controlar QR de eventos')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

DELETE FROM role_permissions WHERE role IN ('admin', 'supervisor', 'coordinator') AND permission_code = 'corrections.manage';

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, r.role, p.code, 1
FROM tenants t
CROSS JOIN (SELECT 'superadmin' AS role UNION ALL SELECT 'admin' UNION ALL SELECT 'supervisor' UNION ALL SELECT 'coordinator' UNION ALL SELECT 'guard' UNION ALL SELECT 'auditor') r
CROSS JOIN permissions p
WHERE (r.role = 'superadmin' OR (r.role IN ('admin', 'supervisor', 'coordinator') AND p.code IN ('employees.manage', 'contractors.manage', 'attendance.view', 'corrections.manage', 'reservation_guests.manage')) OR (r.role = 'supervisor' AND p.code IN ('offline.resolve')) OR (r.role = 'auditor' AND p.code IN ('attendance.view')))
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO schema_migrations (migration) VALUES ('2026_09_08_013_operational_completion.sql')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
