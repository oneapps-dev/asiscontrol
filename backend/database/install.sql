CREATE DATABASE IF NOT EXISTS vzwquesv_app
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE vzwquesv_app;

CREATE TABLE tenants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  legal_name VARCHAR(180) NULL,
  document_number VARCHAR(40) NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE sites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  address VARCHAR(220) NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'America/Bogota',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sites_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  INDEX idx_sites_tenant (tenant_id)
) ENGINE=InnoDB;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(180) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin', 'admin', 'supervisor', 'guard', 'auditor') NOT NULL DEFAULT 'guard',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_users_site FOREIGN KEY (site_id) REFERENCES sites(id),
  UNIQUE KEY uq_users_email (email),
  INDEX idx_users_tenant_role (tenant_id, role)
) ENGINE=InnoDB;

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

CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_tokens_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_password_tokens_user FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_password_tokens_hash (token_hash),
  INDEX idx_password_tokens_user (user_id, expires_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(180) NOT NULL,
  ip_address VARCHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_lookup (email, ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE guard_shifts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  checkpoint VARCHAR(140) NOT NULL,
  notes TEXT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_shifts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_shifts_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_shifts_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_shifts_tenant_active (tenant_id, ended_at),
  INDEX idx_shifts_user_started (user_id, started_at)
) ENGINE=InnoDB;

CREATE TABLE people (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  person_type ENUM('visitante', 'empleado', 'contratista', 'residente') NOT NULL DEFAULT 'visitante',
  document_type VARCHAR(30) NOT NULL,
  document_number VARCHAR(60) NOT NULL,
  full_name VARCHAR(180) NOT NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(180) NULL,
  company VARCHAR(180) NULL,
  photo_path VARCHAR(255) NULL,
  privacy_consent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_people_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  UNIQUE KEY uq_people_tenant_document (tenant_id, document_type, document_number),
  INDEX idx_people_search (tenant_id, full_name)
) ENGINE=InnoDB;

CREATE TABLE units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  tower VARCHAR(80) NOT NULL DEFAULT '',
  unit_code VARCHAR(60) NOT NULL,
  floor VARCHAR(30) NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_units_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_units_site FOREIGN KEY (site_id) REFERENCES sites(id),
  UNIQUE KEY uq_units_code (tenant_id, site_id, tower, unit_code),
  INDEX idx_units_tenant_site (tenant_id, site_id)
) ENGINE=InnoDB;

CREATE TABLE residents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NOT NULL,
  relationship VARCHAR(80) NOT NULL DEFAULT 'residente',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  receives_notifications TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_residents_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_residents_unit FOREIGN KEY (unit_id) REFERENCES units(id),
  CONSTRAINT fk_residents_person FOREIGN KEY (person_id) REFERENCES people(id),
  UNIQUE KEY uq_residents_unit_person (tenant_id, unit_id, person_id),
  INDEX idx_residents_tenant_status (tenant_id, status)
) ENGINE=InnoDB;

CREATE TABLE portal_login_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  resident_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_portal_tokens_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_portal_tokens_resident FOREIGN KEY (resident_id) REFERENCES residents(id),
  UNIQUE KEY uq_portal_tokens_hash (token_hash),
  INDEX idx_portal_tokens_resident (resident_id, expires_at)
) ENGINE=InnoDB;

CREATE TABLE restrictions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(30) NULL,
  document_number VARCHAR(60) NOT NULL,
  level ENUM('warning', 'block') NOT NULL DEFAULT 'warning',
  reason VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_restrictions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  INDEX idx_restrictions_lookup (tenant_id, document_number, is_active)
) ENGINE=InnoDB;

CREATE TABLE visits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NOT NULL,
  shift_id BIGINT UNSIGNED NOT NULL,
  destination VARCHAR(180) NOT NULL,
  host VARCHAR(180) NOT NULL,
  reason VARCHAR(220) NOT NULL,
  vehicle_plate VARCHAR(30) NULL,
  entered_at DATETIME NOT NULL,
  exited_at DATETIME NULL,
  exit_observations TEXT NULL,
  status ENUM('inside', 'exited') NOT NULL DEFAULT 'inside',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_visits_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_visits_person FOREIGN KEY (person_id) REFERENCES people(id),
  CONSTRAINT fk_visits_shift FOREIGN KEY (shift_id) REFERENCES guard_shifts(id),
  INDEX idx_visits_tenant_status (tenant_id, status),
  INDEX idx_visits_entered (tenant_id, entered_at)
) ENGINE=InnoDB;

CREATE TABLE vehicles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  shift_id BIGINT UNSIGNED NULL,
  plate VARCHAR(30) NOT NULL,
  driver_name VARCHAR(160) NOT NULL,
  vehicle_type VARCHAR(80) NOT NULL,
  destination VARCHAR(180) NOT NULL,
  entered_at DATETIME NOT NULL,
  exited_at DATETIME NULL,
  exit_observations TEXT NULL,
  status ENUM('inside', 'exited') NOT NULL DEFAULT 'inside',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vehicles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_vehicles_shift FOREIGN KEY (shift_id) REFERENCES guard_shifts(id),
  INDEX idx_vehicles_tenant_status (tenant_id, status),
  INDEX idx_vehicles_plate (tenant_id, plate)
) ENGINE=InnoDB;

CREATE TABLE packages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  shift_id BIGINT UNSIGNED NULL,
  tracking VARCHAR(100) NOT NULL,
  courier VARCHAR(120) NOT NULL,
  recipient VARCHAR(180) NOT NULL,
  destination VARCHAR(180) NOT NULL,
  received_at DATETIME NOT NULL,
  delivered_at DATETIME NULL,
  delivered_to VARCHAR(180) NULL,
  delivery_observations TEXT NULL,
  status ENUM('pending', 'delivered') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_packages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_packages_shift FOREIGN KEY (shift_id) REFERENCES guard_shifts(id),
  INDEX idx_packages_tenant_status (tenant_id, status),
  INDEX idx_packages_tracking (tenant_id, tracking)
) ENGINE=InnoDB;

CREATE TABLE incidents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  shift_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  severity ENUM('baja', 'media', 'alta') NOT NULL DEFAULT 'media',
  area VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  CONSTRAINT fk_incidents_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_incidents_shift FOREIGN KEY (shift_id) REFERENCES guard_shifts(id),
  CONSTRAINT fk_incidents_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_incidents_tenant_status (tenant_id, status)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  detail VARCHAR(255) NOT NULL,
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_audit_tenant_created (tenant_id, created_at),
  INDEX idx_audit_action (tenant_id, action)
) ENGINE=InnoDB;

CREATE TABLE invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  guest_name VARCHAR(180) NOT NULL,
  guest_document VARCHAR(60) NULL,
  destination VARCHAR(180) NOT NULL,
  host VARCHAR(180) NOT NULL,
  reason VARCHAR(220) NOT NULL,
  qr_token CHAR(64) NOT NULL,
  valid_from DATETIME NOT NULL,
  valid_until DATETIME NOT NULL,
  status ENUM('active', 'used', 'expired', 'cancelled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invitations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_invitations_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_invitations_user FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uq_invitations_token (qr_token),
  INDEX idx_invitations_tenant_status (tenant_id, status)
) ENGINE=InnoDB;

CREATE TABLE approvals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NULL,
  decided_by BIGINT UNSIGNED NULL,
  subject_type VARCHAR(60) NOT NULL,
  subject_id BIGINT UNSIGNED NULL,
  status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  requested_at DATETIME NOT NULL,
  decided_at DATETIME NULL,
  CONSTRAINT fk_approvals_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_approvals_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_approvals_decided_by FOREIGN KEY (decided_by) REFERENCES users(id),
  INDEX idx_approvals_tenant_status (tenant_id, status)
) ENGINE=InnoDB;

CREATE TABLE file_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  owner_type VARCHAR(60) NOT NULL,
  owner_id BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(220) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_files_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
  INDEX idx_files_owner (tenant_id, owner_type, owner_id)
) ENGINE=InnoDB;

CREATE TABLE package_signatures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  receiver_name VARCHAR(180) NOT NULL,
  signature_path VARCHAR(255) NOT NULL,
  signed_at DATETIME NOT NULL,
  CONSTRAINT fk_signatures_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_signatures_package FOREIGN KEY (package_id) REFERENCES packages(id),
  INDEX idx_signatures_package (package_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('email', 'sms', 'whatsapp', 'in_app') NOT NULL DEFAULT 'email',
  recipient VARCHAR(180) NOT NULL,
  subject VARCHAR(180) NULL,
  body TEXT NOT NULL,
  status ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'queued',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NULL,
  next_attempt_at DATETIME NULL,
  error_message VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  INDEX idx_notifications_status (tenant_id, status, next_attempt_at)
) ENGINE=InnoDB;

CREATE TABLE import_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  job_type VARCHAR(80) NOT NULL,
  file_name VARCHAR(220) NOT NULL,
  status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  processed_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  CONSTRAINT fk_imports_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_imports_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_imports_tenant_status (tenant_id, status)
) ENGINE=InnoDB;

CREATE TABLE export_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  report_type VARCHAR(80) NOT NULL,
  filters_json JSON NULL,
  file_path VARCHAR(255) NULL,
  status ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  CONSTRAINT fk_exports_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_exports_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_exports_tenant_status (tenant_id, status)
) ENGINE=InnoDB;

CREATE TABLE parking_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  space_code VARCHAR(60) NOT NULL,
  assigned_to VARCHAR(180) NOT NULL,
  plate VARCHAR(30) NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_parking_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_parking_site FOREIGN KEY (site_id) REFERENCES sites(id),
  UNIQUE KEY uq_parking_space (tenant_id, site_id, space_code)
) ENGINE=InnoDB;

CREATE TABLE booking_resources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  resource_type VARCHAR(80) NOT NULL,
  capacity INT UNSIGNED NOT NULL DEFAULT 0,
  price_per_hour DECIMAL(12,2) NOT NULL DEFAULT 0,
  price_per_day DECIMAL(12,2) NOT NULL DEFAULT 0,
  color VARCHAR(20) NOT NULL DEFAULT '#2563eb',
  rules TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_booking_resources_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_booking_resources_site FOREIGN KEY (site_id) REFERENCES sites(id),
  INDEX idx_booking_resources_tenant (tenant_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE bookings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  resource_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  requester_name VARCHAR(180) NOT NULL,
  requester_phone VARCHAR(80) NULL,
  purpose VARCHAR(220) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('pending', 'confirmed', 'paid', 'cancelled') NOT NULL DEFAULT 'pending',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bookings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_bookings_resource FOREIGN KEY (resource_id) REFERENCES booking_resources(id),
  CONSTRAINT fk_bookings_user FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_bookings_resource_dates (tenant_id, resource_id, starts_at, ends_at),
  INDEX idx_bookings_status (tenant_id, status, starts_at)
) ENGINE=InnoDB;

CREATE TABLE offline_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  device_id VARCHAR(120) NOT NULL,
  action VARCHAR(100) NOT NULL,
  payload_json JSON NOT NULL,
  idempotency_key CHAR(64) NOT NULL,
  status ENUM('pending', 'synced', 'failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  synced_at DATETIME NULL,
  CONSTRAINT fk_offline_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  UNIQUE KEY uq_offline_idempotency (idempotency_key),
  INDEX idx_offline_status (tenant_id, status)
) ENGINE=InnoDB;

INSERT INTO tenants (id, name, legal_name, document_number)
VALUES (1, 'Conjunto Altos del Norte', 'Conjunto Altos del Norte P.H.', '900000001')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO sites (id, tenant_id, name, address)
VALUES (1, 1, 'Porteria Principal', 'Bogota, Colombia')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (id, tenant_id, site_id, name, email, password_hash, role)
VALUES
  (1, 1, 1, 'Ana Torres', 'admin@asiscontrol.local', '$2y$12$H8Wm2Qsj536V2XOvpz7R9OUgfCfT8qn5FdpfOoVA1hYtRZ/2Frqiq', 'admin'),
  (2, 1, 1, 'Carlos Mendoza', 'guardia@asiscontrol.local', '$2y$12$H8Wm2Qsj536V2XOvpz7R9OUgfCfT8qn5FdpfOoVA1hYtRZ/2Frqiq', 'guard')
ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role);

INSERT INTO permissions (code, name, description)
VALUES
  ('visits.manage', 'Gestionar visitas', 'Crear ingresos, salidas y redimir invitaciones'),
  ('vehicles.manage', 'Gestionar vehiculos', 'Registrar ingresos y salidas de vehiculos'),
  ('packages.manage', 'Gestionar paquetes', 'Registrar, entregar y firmar paquetes'),
  ('bookings.manage', 'Gestionar reservas', 'Crear, confirmar, pagar, cancelar y eliminar reservas de espacios'),
  ('incidents.manage', 'Gestionar incidentes', 'Crear y cerrar incidentes operativos'),
  ('approvals.manage', 'Gestionar aprobaciones', 'Crear y decidir solicitudes de aprobacion'),
  ('reports.view', 'Ver reportes', 'Consultar y exportar reportes operativos'),
  ('audit.view', 'Ver auditoria', 'Consultar y exportar auditoria'),
  ('settings.manage', 'Administrar configuracion', 'Gestionar sedes, usuarios, unidades, residentes y restricciones'),
  ('imports.manage', 'Gestionar importaciones', 'Cargar CSV administrativos'),
  ('files.manage', 'Gestionar adjuntos', 'Subir, listar y descargar evidencias')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT 1, 'superadmin', code, 1 FROM permissions
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT 1, 'admin', code, 1 FROM permissions
WHERE code NOT IN ('reports.view', 'audit.view')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
VALUES
  (1, 'supervisor', 'visits.manage', 1),
  (1, 'supervisor', 'vehicles.manage', 1),
  (1, 'supervisor', 'packages.manage', 1),
  (1, 'supervisor', 'bookings.manage', 1),
  (1, 'supervisor', 'incidents.manage', 1),
  (1, 'supervisor', 'approvals.manage', 1),
  (1, 'supervisor', 'reports.view', 1),
  (1, 'supervisor', 'audit.view', 1),
  (1, 'guard', 'visits.manage', 1),
  (1, 'guard', 'vehicles.manage', 1),
  (1, 'guard', 'packages.manage', 1),
  (1, 'guard', 'bookings.manage', 1),
  (1, 'guard', 'incidents.manage', 1),
  (1, 'guard', 'files.manage', 1),
  (1, 'auditor', 'reports.view', 1),
  (1, 'auditor', 'audit.view', 1)
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

DELETE FROM role_permissions WHERE role = 'supervisor' AND permission_code = 'approvals.manage';
DELETE FROM role_permissions WHERE role = 'guard' AND permission_code = 'bookings.manage';

INSERT INTO restrictions (tenant_id, document_type, document_number, level, reason)
VALUES
  (1, 'CC', '1111111111', 'block', 'Ingreso bloqueado por administracion'),
  (1, 'CC', '79999111', 'warning', 'Solicitar soporte de ARL antes del ingreso');

INSERT INTO guard_shifts (id, tenant_id, site_id, user_id, checkpoint, notes, started_at)
VALUES (1, 1, 1, 2, 'Porteria Principal', 'Turno demo inicial', DATE_SUB(NOW(), INTERVAL 90 MINUTE))
ON DUPLICATE KEY UPDATE checkpoint = VALUES(checkpoint);

INSERT INTO people (id, tenant_id, person_type, document_type, document_number, full_name, phone, email, company, privacy_consent_at)
VALUES
  (1, 1, 'visitante', 'CC', '1032445678', 'Ricardo Mendoza', '3005550198', NULL, 'Independiente', NOW()),
  (2, 1, 'contratista', 'CC', '79999111', 'Diana Prieto', '3107778822', NULL, 'Mantenimiento Integral', NOW()),
  (3, 1, 'residente', 'CC', '52222444', 'Laura Gomez', '3002223344', 'laura.gomez@asiscontrol.local', NULL, NOW()),
  (4, 1, 'residente', 'CC', '80111222', 'Andres Rojas', '3015556677', 'andres.rojas@asiscontrol.local', NULL, NOW())
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone), email = VALUES(email), company = VALUES(company);

INSERT INTO units (id, tenant_id, site_id, tower, unit_code, floor, status)
VALUES
  (1, 1, 1, 'Torre A', '402', '4', 'active'),
  (2, 1, 1, 'Torre B', '702', '7', 'active'),
  (3, 1, 1, 'Torre C', '301', '3', 'active')
ON DUPLICATE KEY UPDATE floor = VALUES(floor), status = VALUES(status);

INSERT INTO residents (id, tenant_id, unit_id, person_id, relationship, is_primary, receives_notifications, status)
VALUES
  (1, 1, 1, 3, 'propietaria', 1, 1, 'active'),
  (2, 1, 3, 4, 'residente', 1, 1, 'active')
ON DUPLICATE KEY UPDATE relationship = VALUES(relationship), status = VALUES(status);

INSERT INTO visits (id, tenant_id, person_id, shift_id, destination, host, reason, vehicle_plate, entered_at, status)
VALUES
  (1, 1, 1, 1, 'Torre A - 402', 'Laura Gomez', 'Visita familiar', NULL, DATE_SUB(NOW(), INTERVAL 42 MINUTE), 'inside'),
  (2, 1, 2, 1, 'Cuarto tecnico', 'Administracion', 'Revision ascensor', 'KLM-428', DATE_SUB(NOW(), INTERVAL 118 MINUTE), 'inside')
ON DUPLICATE KEY UPDATE destination = VALUES(destination), status = VALUES(status);

INSERT INTO vehicles (id, tenant_id, shift_id, plate, driver_name, vehicle_type, destination, entered_at, status)
VALUES (1, 1, 1, 'KLM-428', 'Diana Prieto', 'Camioneta', 'Bahia de servicio', DATE_SUB(NOW(), INTERVAL 118 MINUTE), 'inside')
ON DUPLICATE KEY UPDATE driver_name = VALUES(driver_name), status = VALUES(status);

INSERT INTO packages (id, tenant_id, shift_id, tracking, courier, recipient, destination, received_at, status)
VALUES (1, 1, 1, 'ML-884192', 'Mercado Envio', 'Apartamento 702', 'Torre B', DATE_SUB(NOW(), INTERVAL 23 MINUTE), 'pending')
ON DUPLICATE KEY UPDATE recipient = VALUES(recipient), status = VALUES(status);

INSERT INTO booking_resources (id, tenant_id, site_id, name, resource_type, capacity, price_per_hour, price_per_day, color, rules)
VALUES
  (1, 1, 1, 'Salon social', 'Zona social', 60, 45000, 280000, '#2563eb', 'Requiere deposito, firma de reglamento y entrega de inventario.'),
  (2, 1, 1, 'Sala de reuniones', 'Sala empresarial', 12, 35000, 190000, '#7c3aed', 'Incluye proyector, wifi y tablero.')
ON DUPLICATE KEY UPDATE name = VALUES(name), price_per_hour = VALUES(price_per_hour), price_per_day = VALUES(price_per_day), rules = VALUES(rules);

INSERT INTO bookings (id, tenant_id, resource_id, created_by, requester_name, requester_phone, purpose, starts_at, ends_at, status, amount, notes)
VALUES (1, 1, 1, 1, 'Laura Gomez', '3002223344', 'Cumpleanos familiar', DATE_ADD(NOW(), INTERVAL 26 HOUR), DATE_ADD(NOW(), INTERVAL 31 HOUR), 'confirmed', 225000, 'Pendiente deposito.')
ON DUPLICATE KEY UPDATE status = VALUES(status), amount = VALUES(amount), notes = VALUES(notes);

INSERT INTO incidents (id, tenant_id, shift_id, user_id, title, severity, area, description, status)
VALUES (1, 1, 1, 2, 'Puerta peatonal con cierre lento', 'media', 'Acceso peatonal', 'Se reporta demora en el cierre automatico. Se informa a mantenimiento.', 'open')
ON DUPLICATE KEY UPDATE title = VALUES(title), status = VALUES(status);

INSERT INTO audit_logs (tenant_id, user_id, action, detail)
VALUES
  (1, 2, 'turno.inicio', 'Inicio de turno demo en Porteria Principal'),
  (1, 2, 'visita.ingreso', 'Ingreso de Ricardo Mendoza'),
  (1, 2, 'paquete.recibido', 'Paquete ML-884192 recibido');

INSERT INTO invitations (tenant_id, site_id, created_by, guest_name, guest_document, destination, host, reason, qr_token, valid_from, valid_until)
VALUES (1, 1, 1, 'Visitante QR Demo', '555123', 'Torre C - 301', 'Andres Rojas', 'Visita programada', SHA2(CONCAT('demo-qr-', NOW()), 256), NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY));

INSERT INTO parking_assignments (tenant_id, site_id, space_code, assigned_to, plate)
VALUES (1, 1, 'V-012', 'Apartamento 402', 'ABC-123')
ON DUPLICATE KEY UPDATE assigned_to = VALUES(assigned_to), plate = VALUES(plate);

-- Platform hardening: the install script is self-contained and includes the
-- same contract as the migration runner for a single fresh database create.
CREATE TABLE IF NOT EXISTS security_companies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  legal_name VARCHAR(180) NULL,
  document_number VARCHAR(40) NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_security_companies_document (document_number)
) ENGINE=InnoDB;

ALTER TABLE tenants
  ADD COLUMN security_company_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN tenant_type ENUM('direct', 'security_client', 'residential') NOT NULL DEFAULT 'direct' AFTER name,
  ADD INDEX idx_tenants_security_company (security_company_id),
  ADD CONSTRAINT fk_tenants_security_company FOREIGN KEY (security_company_id) REFERENCES security_companies(id);

ALTER TABLE users
  ADD COLUMN security_company_id BIGINT UNSIGNED NULL AFTER tenant_id,
  ADD INDEX idx_users_security_company (security_company_id),
  ADD CONSTRAINT fk_users_security_company FOREIGN KEY (security_company_id) REFERENCES security_companies(id);

CREATE TABLE IF NOT EXISTS access_points (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL,
  mode ENUM('con_vigilancia', 'sin_vigilancia') NOT NULL DEFAULT 'con_vigilancia',
  capabilities_json JSON NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  geofence_radius_m INT UNSIGNED NULL,
  allowed_networks_json JSON NULL,
  status ENUM('active', 'inactive', 'revoked') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_access_points_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_access_points_site FOREIGN KEY (site_id) REFERENCES sites(id),
  UNIQUE KEY uq_access_points_code (tenant_id, site_id, code),
  INDEX idx_access_points_site_status (tenant_id, site_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  access_point_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_last4 CHAR(4) NULL,
  fingerprint_hash CHAR(64) NULL,
  registered_ip VARCHAR(64) NULL,
  user_agent TEXT NULL,
  last_latitude DECIMAL(10,7) NULL,
  last_longitude DECIMAL(10,7) NULL,
  status ENUM('pending', 'active', 'revoked') NOT NULL DEFAULT 'pending',
  authorized_by BIGINT UNSIGNED NULL,
  authorized_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_devices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_devices_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_devices_access_point FOREIGN KEY (access_point_id) REFERENCES access_points(id),
  CONSTRAINT fk_devices_authorized_by FOREIGN KEY (authorized_by) REFERENCES users(id),
  UNIQUE KEY uq_devices_token_hash (token_hash),
  INDEX idx_devices_scope (tenant_id, site_id, access_point_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_scopes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  scope_type ENUM('security_company', 'tenant', 'site', 'access_point') NOT NULL,
  scope_id BIGINT UNSIGNED NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_scopes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_user_scopes_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_user_scopes_granted_by FOREIGN KEY (granted_by) REFERENCES users(id),
  UNIQUE KEY uq_user_scope (user_id, scope_type, scope_id),
  INDEX idx_user_scopes_tenant (tenant_id, scope_type, scope_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS person_qualifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NOT NULL,
  qualification_type VARCHAR(60) NOT NULL,
  reference VARCHAR(160) NULL,
  valid_from DATE NULL,
  valid_until DATE NULL,
  metadata_json JSON NULL,
  status ENUM('active', 'inactive', 'expired') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_person_qualifications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_person_qualifications_person FOREIGN KEY (person_id) REFERENCES people(id),
  INDEX idx_person_qualifications_lookup (tenant_id, person_id, qualification_type, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NOT NULL,
  employee_code VARCHAR(80) NULL,
  schedule_start TIME NULL,
  schedule_end TIME NULL,
  tolerance_mins INT UNSIGNED NOT NULL DEFAULT 10,
  incomplete_after_hours DECIMAL(5,2) NOT NULL DEFAULT 14,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_employee_profiles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_employee_profiles_person FOREIGN KEY (person_id) REFERENCES people(id),
  UNIQUE KEY uq_employee_profiles_person (tenant_id, person_id),
  UNIQUE KEY uq_employee_profiles_code (tenant_id, employee_code),
  INDEX idx_employee_profiles_status (tenant_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS access_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NULL,
  person_quality VARCHAR(60) NULL,
  site_id BIGINT UNSIGNED NULL,
  access_point_id BIGINT UNSIGNED NULL,
  device_id BIGINT UNSIGNED NULL,
  operator_user_id BIGINT UNSIGNED NULL,
  event_type ENUM('entrada', 'salida', 'salida_administrativa') NOT NULL,
  identification_method ENUM('huella', 'cedula', 'manual', 'qr', 'otro') NOT NULL DEFAULT 'manual',
  occurred_at DATETIME NOT NULL,
  server_received_at DATETIME NULL,
  source ENUM('online', 'offline') NOT NULL DEFAULT 'online',
  idempotency_key CHAR(64) NOT NULL,
  status ENUM('accepted', 'review', 'rejected') NOT NULL DEFAULT 'accepted',
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_access_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_access_events_person FOREIGN KEY (person_id) REFERENCES people(id),
  CONSTRAINT fk_access_events_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_access_events_access_point FOREIGN KEY (access_point_id) REFERENCES access_points(id),
  CONSTRAINT fk_access_events_device FOREIGN KEY (device_id) REFERENCES devices(id),
  CONSTRAINT fk_access_events_operator FOREIGN KEY (operator_user_id) REFERENCES users(id),
  UNIQUE KEY uq_access_events_uuid (event_uuid),
  UNIQUE KEY uq_access_events_idempotency (idempotency_key),
  INDEX idx_access_events_scope_time (tenant_id, site_id, occurred_at),
  INDEX idx_access_events_person_time (tenant_id, person_id, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offline_conflicts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  local_event_uuid CHAR(36) NOT NULL,
  local_payload_json JSON NOT NULL,
  remote_event_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NOT NULL,
  decision ENUM('latest_wins', 'manual_review') NOT NULL DEFAULT 'manual_review',
  system_note VARCHAR(500) NULL,
  status ENUM('pending', 'resolved', 'dismissed') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  resolution_notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_offline_conflicts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_offline_conflicts_remote FOREIGN KEY (remote_event_id) REFERENCES access_events(id),
  CONSTRAINT fk_offline_conflicts_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
  UNIQUE KEY uq_offline_conflicts_event (tenant_id, local_event_uuid),
  INDEX idx_offline_conflicts_decision (tenant_id, decision, created_at),
  INDEX idx_offline_conflicts_status (tenant_id, status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservation_guests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  invitation_id BIGINT UNSIGNED NULL,
  guest_name VARCHAR(180) NOT NULL,
  guest_document VARCHAR(60) NULL,
  qr_token_hash CHAR(64) NULL,
  valid_from DATETIME NOT NULL,
  valid_until DATETIME NOT NULL,
  status ENUM('active', 'used', 'cancelled', 'expired') NOT NULL DEFAULT 'active',
  checked_in_at DATETIME NULL,
  checked_out_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservation_guests_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_reservation_guests_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),
  CONSTRAINT fk_reservation_guests_invitation FOREIGN KEY (invitation_id) REFERENCES invitations(id),
  UNIQUE KEY uq_reservation_guests_qr (qr_token_hash),
  INDEX idx_reservation_guests_booking (tenant_id, booking_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  backup_type ENUM('technical', 'operational', 'consolidated') NOT NULL DEFAULT 'technical',
  scope_json JSON NULL,
  file_path VARCHAR(255) NULL,
  checksum CHAR(64) NULL,
  status ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
  error_message VARCHAR(255) NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_jobs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_backup_jobs_user FOREIGN KEY (requested_by) REFERENCES users(id),
  INDEX idx_backup_jobs_status (tenant_id, status, created_at)
) ENGINE=InnoDB;

ALTER TABLE offline_queue
  ADD COLUMN event_uuid CHAR(36) NULL AFTER device_id,
  ADD COLUMN client_occurred_at DATETIME NULL AFTER payload_json,
  ADD COLUMN server_received_at DATETIME NULL AFTER client_occurred_at,
  ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
  ADD INDEX idx_offline_event_uuid (tenant_id, event_uuid);

ALTER TABLE file_attachments
  ADD COLUMN sha256 CHAR(64) NULL AFTER byte_size,
  ADD COLUMN captured_at DATETIME NULL AFTER uploaded_by,
  ADD COLUMN immutable_at DATETIME NULL AFTER captured_at,
  ADD INDEX idx_files_hash (tenant_id, sha256);

ALTER TABLE audit_logs
  ADD COLUMN module VARCHAR(80) NULL AFTER action,
  ADD COLUMN site_id BIGINT UNSIGNED NULL AFTER module,
  ADD COLUMN access_point_id BIGINT UNSIGNED NULL AFTER site_id,
  ADD COLUMN metadata_json JSON NULL AFTER detail,
  ADD CONSTRAINT fk_audit_site FOREIGN KEY (site_id) REFERENCES sites(id),
  ADD CONSTRAINT fk_audit_access_point FOREIGN KEY (access_point_id) REFERENCES access_points(id);

ALTER TABLE booking_resources
  ADD COLUMN pricing_mode ENUM('hora', 'dia', 'mixto') NOT NULL DEFAULT 'mixto' AFTER resource_type,
  ADD COLUMN deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER price_per_day,
  ADD COLUMN min_duration_mins INT UNSIGNED NULL AFTER deposit_amount,
  ADD COLUMN max_duration_days INT UNSIGNED NULL AFTER min_duration_mins;

ALTER TABLE bookings
  ADD COLUMN deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount,
  ADD COLUMN terms_accepted_at DATETIME NULL AFTER deposit_amount,
  ADD COLUMN source ENUM('admin', 'resident', 'system') NOT NULL DEFAULT 'admin' AFTER terms_accepted_at,
  ADD COLUMN cancelled_at DATETIME NULL AFTER source,
  ADD COLUMN cancellation_reason VARCHAR(255) NULL AFTER cancelled_at;

ALTER TABLE invitations
  ADD COLUMN booking_id BIGINT UNSIGNED NULL AFTER site_id,
  ADD CONSTRAINT fk_invitations_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),
  ADD INDEX idx_invitations_booking (tenant_id, booking_id);

CREATE TABLE IF NOT EXISTS schema_migrations (
  migration VARCHAR(180) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO schema_migrations (migration) VALUES
 ('2026_07_16_001_notifications_retries.sql'),
 ('2026_07_16_002_units_residents.sql'),
 ('2026_07_16_003_permissions.sql'),
 ('2026_07_18_004_portal_login_tokens.sql'),
 ('2026_07_18_005_exit_delivery_evidence_notes.sql'),
 ('2026_07_18_006_bookings_calendar.sql'),
 ('2026_09_08_007_platform_contract_hardening.sql'),
 ('2026_09_08_008_permission_alignment.sql')
 ,('2026_09_08_009_record_versioning.sql')
 ,('2026_09_08_010_platform_control_plane.sql')
 ,('2026_09_08_011_backup_and_sync_policy.sql')
 ,('2026_09_08_012_supervisor_device_visibility.sql')
ON DUPLICATE KEY UPDATE applied_at = applied_at;

ALTER TABLE people
  ADD COLUMN record_status ENUM('active', 'archived', 'deleted') NOT NULL DEFAULT 'active' AFTER privacy_consent_at,
  ADD COLUMN version_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER record_status,
  ADD COLUMN supersedes_person_id BIGINT UNSIGNED NULL AFTER version_no,
  ADD COLUMN archived_at DATETIME NULL AFTER supersedes_person_id,
  ADD COLUMN archived_by BIGINT UNSIGNED NULL AFTER archived_at,
  ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by,
  DROP INDEX uq_people_tenant_document,
  ADD UNIQUE KEY uq_people_document_version (tenant_id, document_type, document_number, version_no),
  ADD INDEX idx_people_current_document (tenant_id, document_type, document_number, record_status),
  ADD CONSTRAINT fk_people_supersedes FOREIGN KEY (supersedes_person_id) REFERENCES people(id),
  ADD CONSTRAINT fk_people_archived_by FOREIGN KEY (archived_by) REFERENCES users(id);

INSERT INTO permissions (code, name, description)
VALUES
  ('bookings.view', 'Consultar reservas', 'Ver calendario, disponibilidad y próximas reservas'),
  ('devices.manage', 'Gestionar dispositivos', 'Autorizar, validar y revocar dispositivos y puntos de acceso'),
  ('devices.view', 'Consultar dispositivos', 'Ver dispositivos y puntos de acceso autorizados'),
  ('devices.revoke', 'Revocar dispositivos', 'Revocar un dispositivo autorizado'),
  ('offline.resolve', 'Resolver conflictos offline', 'Revisar y resolver conflictos de sincronización'),
  ('backups.manage', 'Gestionar backups', 'Crear y consultar backups técnicos u operativos'),
  ('corrections.manage', 'Gestionar correcciones', 'Corregir registros conservando auditoría'),
  ('access_events.register', 'Registrar accesos', 'Registrar eventos de entrada y salida con idempotencia')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT 1, 'admin', code, 1 FROM permissions
WHERE code IN ('bookings.view', 'devices.view', 'devices.revoke', 'corrections.manage', 'access_events.register')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT 1, 'supervisor', code, 1 FROM permissions
WHERE code IN ('bookings.view', 'offline.resolve', 'devices.manage', 'devices.view', 'backups.manage', 'access_events.register')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT 1, 'guard', code, 1 FROM permissions
WHERE code IN ('bookings.view', 'access_events.register')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

-- Platform control plane: customer accounts, subscriptions and operational metrics.
CREATE TABLE IF NOT EXISTS platform_admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  access_level ENUM('support', 'operator', 'owner') NOT NULL DEFAULT 'operator',
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_platform_admins_user FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_platform_admins_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  billing_period ENUM('monthly', 'annual', 'lifetime') NOT NULL DEFAULT 'monthly',
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  limits_json JSON NULL,
  features_json JSON NULL,
  status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subscription_plans_code_period (code, billing_period)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  status ENUM('trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired') NOT NULL DEFAULT 'trial',
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  auto_renew TINYINT(1) NOT NULL DEFAULT 0,
  external_reference VARCHAR(160) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenant_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_tenant_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
  CONSTRAINT fk_tenant_subscriptions_creator FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_tenant_subscriptions_lookup (tenant_id, status, ends_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_account_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_platform_invites_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_platform_invites_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_platform_invites_creator FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uq_platform_invites_token (token_hash),
  INDEX idx_platform_invites_tenant (tenant_id, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  tenant_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  detail VARCHAR(255) NOT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_platform_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
  CONSTRAINT fk_platform_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  INDEX idx_platform_audit_created (created_at),
  INDEX idx_platform_audit_tenant (tenant_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_metrics_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  metric_date DATE NOT NULL,
  active_users INT UNSIGNED NOT NULL DEFAULT 0,
  visits_count INT UNSIGNED NOT NULL DEFAULT 0,
  access_events_count INT UNSIGNED NOT NULL DEFAULT 0,
  bookings_count INT UNSIGNED NOT NULL DEFAULT 0,
  packages_count INT UNSIGNED NOT NULL DEFAULT 0,
  incidents_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenant_metrics_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  UNIQUE KEY uq_tenant_metrics_day (tenant_id, metric_date),
  INDEX idx_tenant_metrics_date (metric_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  schedule ENUM('manual', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'monthly',
  retention_days INT UNSIGNED NOT NULL DEFAULT 365,
  destination_path VARCHAR(255) NULL,
  encryption_required TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at DATETIME NULL,
  next_run_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_policies_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_backup_policies_creator FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uq_backup_policies_tenant (tenant_id),
  INDEX idx_backup_policies_schedule (enabled, schedule, next_run_at)
) ENGINE=InnoDB;

INSERT INTO subscription_plans (code, name, billing_period, price, limits_json, features_json)
VALUES
  ('starter', 'Starter', 'monthly', 180000, JSON_OBJECT('units', 50, 'sites', 1), JSON_ARRAY('visits', 'packages', 'incidents')),
  ('basic', 'Básico', 'monthly', 280000, JSON_OBJECT('units', 150, 'sites', 2), JSON_ARRAY('visits', 'packages', 'bookings', 'reports')),
  ('standard', 'Estándar', 'monthly', 420000, JSON_OBJECT('units', 400, 'sites', 3), JSON_ARRAY('all_mvp', 'portal', 'offline')),
  ('advanced', 'Avanzado', 'monthly', 650000, JSON_OBJECT('units', 800, 'sites', 6), JSON_ARRAY('all_mvp', 'portal', 'offline', 'devices')),
  ('enterprise', 'Enterprise', 'monthly', 1500000, JSON_OBJECT('units', 0, 'sites', 0), JSON_ARRAY('all_features', 'multi_tenant'))
ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price), limits_json = VALUES(limits_json), features_json = VALUES(features_json);

INSERT INTO platform_admins (user_id, access_level, status)
SELECT id, 'owner', 'active' FROM users WHERE role = 'admin' AND id = 1
ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), status = VALUES(status);

INSERT INTO tenant_subscriptions (tenant_id, plan_id, status, starts_on, auto_renew, created_by)
SELECT 1, id, 'active', CURRENT_DATE, 0, 1 FROM subscription_plans WHERE code = 'starter' AND billing_period = 'monthly'
  AND NOT EXISTS (SELECT 1 FROM tenant_subscriptions WHERE tenant_id = 1)
LIMIT 1;

INSERT INTO backup_policies (tenant_id, schedule, retention_days, encryption_required, enabled, next_run_at, created_by)
SELECT 1, 'monthly', 365, 0, 1, DATE_ADD(CURRENT_DATE, INTERVAL 1 MONTH), 1
WHERE EXISTS (SELECT 1 FROM tenants WHERE id = 1)
  AND NOT EXISTS (SELECT 1 FROM backup_policies WHERE tenant_id = 1);

-- Operación avanzada (también disponible como migration 2026_09_08_013).
ALTER TABLE users MODIFY role ENUM('superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'auditor') NOT NULL DEFAULT 'guard';
ALTER TABLE role_permissions MODIFY role ENUM('superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'auditor') NOT NULL;
ALTER TABLE people MODIFY person_type ENUM('visitante', 'empleado', 'contratista', 'residente', 'domestico') NOT NULL DEFAULT 'visitante';
ALTER TABLE restrictions MODIFY level ENUM('informative', 'review', 'warning', 'block') NOT NULL DEFAULT 'review';
ALTER TABLE restrictions ADD COLUMN valid_from DATETIME NULL AFTER reason, ADD COLUMN valid_until DATETIME NULL AFTER valid_from, ADD COLUMN scope_type ENUM('tenant', 'site', 'access_point') NOT NULL DEFAULT 'tenant' AFTER valid_until, ADD COLUMN scope_id BIGINT UNSIGNED NULL AFTER scope_type, ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER scope_id;
ALTER TABLE visits ADD COLUMN site_id BIGINT UNSIGNED NULL AFTER shift_id, ADD COLUMN access_point_id BIGINT UNSIGNED NULL AFTER site_id, ADD COLUMN device_id BIGINT UNSIGNED NULL AFTER access_point_id, ADD COLUMN operator_user_id BIGINT UNSIGNED NULL AFTER device_id, ADD COLUMN identification_method ENUM('huella', 'cedula', 'manual', 'qr', 'otro') NOT NULL DEFAULT 'manual' AFTER vehicle_plate, ADD COLUMN exit_type ENUM('normal', 'administrativa') NULL AFTER exit_observations, ADD COLUMN exit_by BIGINT UNSIGNED NULL AFTER exit_type, ADD COLUMN exit_reason VARCHAR(255) NULL AFTER exit_by;
ALTER TABLE vehicles ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER shift_id, ADD COLUMN site_id BIGINT UNSIGNED NULL AFTER person_id, ADD COLUMN access_point_id BIGINT UNSIGNED NULL AFTER site_id, ADD COLUMN identification_method ENUM('huella', 'cedula', 'manual', 'qr', 'otro') NOT NULL DEFAULT 'manual' AFTER destination, ADD COLUMN exit_type ENUM('normal', 'administrativa') NULL AFTER exit_observations, ADD COLUMN exit_by BIGINT UNSIGNED NULL AFTER exit_type, ADD COLUMN exit_reason VARCHAR(255) NULL AFTER exit_by;
ALTER TABLE packages MODIFY status ENUM('pending', 'delivered', 'rejected', 'returned') NOT NULL DEFAULT 'pending';
ALTER TABLE packages ADD COLUMN package_type ENUM('documento', 'paquete', 'mercancia', 'alimento', 'otro') NOT NULL DEFAULT 'paquete' AFTER tracking, ADD COLUMN courier_phone VARCHAR(50) NULL AFTER courier, ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER delivery_observations, ADD COLUMN return_reason VARCHAR(255) NULL AFTER rejection_reason, ADD COLUMN returned_at DATETIME NULL AFTER return_reason, ADD COLUMN returned_by BIGINT UNSIGNED NULL AFTER returned_at;
ALTER TABLE incidents MODIFY status ENUM('open', 'in_review', 'closed') NOT NULL DEFAULT 'open';
ALTER TABLE incidents ADD COLUMN resolution TEXT NULL AFTER closed_at, ADD COLUMN closed_by BIGINT UNSIGNED NULL AFTER resolution, ADD COLUMN linked_type ENUM('person', 'vehicle', 'package', 'access_point', 'unit') NULL AFTER closed_by, ADD COLUMN linked_id BIGINT UNSIGNED NULL AFTER linked_type;
ALTER TABLE bookings MODIFY status ENUM('pre_reservation', 'pending', 'confirmed', 'pending_payment', 'paid', 'cancelled', 'expired') NOT NULL DEFAULT 'pending';
ALTER TABLE bookings ADD COLUMN guest_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER notes, ADD COLUMN qr_token_hash CHAR(64) NULL AFTER guest_count;
ALTER TABLE residents ADD COLUMN password_hash VARCHAR(255) NULL AFTER person_id, ADD COLUMN password_set_at DATETIME NULL AFTER password_hash, ADD COLUMN last_login_at DATETIME NULL AFTER password_set_at;

CREATE TABLE IF NOT EXISTS employee_schedules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, employee_profile_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, tolerance_mins INT UNSIGNED NOT NULL DEFAULT 10,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_employee_schedules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id), CONSTRAINT fk_employee_schedules_profile FOREIGN KEY (employee_profile_id) REFERENCES employee_profiles(id),
  UNIQUE KEY uq_employee_schedule_day (employee_profile_id, day_of_week), INDEX idx_employee_schedules_tenant (tenant_id, day_of_week, is_active)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS attendance_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, employee_profile_id BIGINT UNSIGNED NOT NULL, person_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NULL, shift_id BIGINT UNSIGNED NULL, event_type ENUM('entrada', 'salida') NOT NULL, identification_method ENUM('huella', 'cedula', 'manual', 'qr', 'otro') NOT NULL DEFAULT 'manual',
  occurred_at DATETIME NOT NULL, source ENUM('online', 'offline', 'import') NOT NULL DEFAULT 'online', status ENUM('puntual', 'tarde', 'ausencia', 'incompleto', 'corregido') NOT NULL DEFAULT 'puntual', notes TEXT NULL, idempotency_key CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_attendance_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id), CONSTRAINT fk_attendance_profile FOREIGN KEY (employee_profile_id) REFERENCES employee_profiles(id), CONSTRAINT fk_attendance_person FOREIGN KEY (person_id) REFERENCES people(id), CONSTRAINT fk_attendance_site FOREIGN KEY (site_id) REFERENCES sites(id), CONSTRAINT fk_attendance_shift FOREIGN KEY (shift_id) REFERENCES guard_shifts(id),
  UNIQUE KEY uq_attendance_idempotency (tenant_id, idempotency_key), INDEX idx_attendance_day (tenant_id, employee_profile_id, occurred_at), INDEX idx_attendance_status (tenant_id, status, occurred_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS record_corrections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, entity_type VARCHAR(60) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, field_name VARCHAR(80) NOT NULL, previous_value TEXT NULL, new_value TEXT NULL, reason VARCHAR(255) NOT NULL, corrected_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_corrections_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id), CONSTRAINT fk_corrections_user FOREIGN KEY (corrected_by) REFERENCES users(id), INDEX idx_corrections_entity (tenant_id, entity_type, entity_id, created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS restore_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NULL, requested_by BIGINT UNSIGNED NOT NULL, backup_job_id BIGINT UNSIGNED NOT NULL, confirmation_hash CHAR(64) NOT NULL,
  status ENUM('pending', 'approved', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending', reason VARCHAR(255) NOT NULL, approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL, finished_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_restore_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id), CONSTRAINT fk_restore_requester FOREIGN KEY (requested_by) REFERENCES users(id), CONSTRAINT fk_restore_backup FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id), CONSTRAINT fk_restore_approver FOREIGN KEY (approved_by) REFERENCES users(id), INDEX idx_restore_status (tenant_id, status, created_at)
) ENGINE=InnoDB;
INSERT INTO permissions (code, name, description) VALUES ('employees.manage', 'Gestionar empleados', 'Crear fichas, horarios y asistencia de empleados'), ('contractors.manage', 'Gestionar contratistas', 'Gestionar contratistas, documentos y cualificaciones'), ('corrections.manage', 'Gestionar correcciones', 'Corregir datos conservando historial auditable'), ('attendance.view', 'Ver asistencia', 'Consultar asistencia, retardos y ausencias'), ('offline.resolve', 'Resolver conflictos offline', 'Revisar y resolver inconsistencias de sincronización'), ('backups.restore', 'Restaurar backups', 'Solicitar y aprobar restauraciones con doble confirmación'), ('reservation_guests.manage', 'Gestionar invitados de reservas', 'Crear invitados y controlar QR de eventos') ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);
INSERT INTO role_permissions (tenant_id, role, permission_code, granted) SELECT t.id, r.role, p.code, 1 FROM tenants t CROSS JOIN (SELECT 'superadmin' AS role UNION ALL SELECT 'admin' UNION ALL SELECT 'supervisor' UNION ALL SELECT 'coordinator' UNION ALL SELECT 'guard' UNION ALL SELECT 'auditor') r CROSS JOIN permissions p WHERE (r.role = 'superadmin' OR (r.role IN ('admin', 'supervisor', 'coordinator') AND p.code IN ('employees.manage', 'contractors.manage', 'attendance.view', 'corrections.manage', 'reservation_guests.manage')) OR (r.role = 'supervisor' AND p.code IN ('offline.resolve')) OR (r.role = 'auditor' AND p.code IN ('attendance.view'))) ON DUPLICATE KEY UPDATE granted = VALUES(granted);
DELETE FROM role_permissions WHERE role IN ('admin', 'supervisor', 'coordinator') AND permission_code = 'corrections.manage';

INSERT INTO people (id, tenant_id, person_type, document_type, document_number, full_name, phone, email, company, privacy_consent_at)
VALUES (5, 1, 'empleado', 'CC', '1005006007', 'Natalia Castillo', '3004445566', 'natalia.castillo@asiscontrol.local', 'Creamox SAS', NOW()), (6, 1, 'domestico', 'CC', '1007008009', 'Jorge Salazar', '3016667788', NULL, 'Torre A - 402', NOW())
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone), email = VALUES(email), company = VALUES(company);
INSERT INTO employee_profiles (tenant_id, person_id, employee_code, schedule_start, schedule_end, tolerance_mins, incomplete_after_hours, status)
VALUES (1, 5, 'EMP-001', '07:00:00', '17:00:00', 10, 14, 'active')
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), schedule_start = VALUES(schedule_start), schedule_end = VALUES(schedule_end), status = VALUES(status);
INSERT INTO employee_schedules (tenant_id, employee_profile_id, day_of_week, start_time, end_time, tolerance_mins, is_active)
SELECT 1, id, 1, '07:00:00', '17:00:00', 10, 1 FROM employee_profiles WHERE tenant_id = 1 AND person_id = 5
ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), tolerance_mins = VALUES(tolerance_mins);
INSERT INTO person_qualifications (tenant_id, person_id, qualification_type, reference, valid_until, status)
VALUES (1, 2, 'arl', 'ARL-2026-001', DATE_ADD(CURRENT_DATE, INTERVAL 10 MONTH), 'active'), (1, 2, 'curso_alturas', 'ALT-2026-001', DATE_ADD(CURRENT_DATE, INTERVAL 6 MONTH), 'active')
ON DUPLICATE KEY UPDATE valid_until = VALUES(valid_until), status = VALUES(status);
UPDATE residents SET password_hash = '$2y$12$JGOpWFFRxUv1vE0FBPfTE.egR3XCAmg36QBaUt5PrK42YhvAnag9a', password_set_at = COALESCE(password_set_at, NOW()) WHERE id = 1;
INSERT INTO schema_migrations (migration) VALUES ('2026_09_08_013_operational_completion.sql') ON DUPLICATE KEY UPDATE applied_at = applied_at;

-- Cierre QA 2026-09-12 (también disponible como migración 014).
-- Este bloque se ejecuta después de la operación avanzada para que todas las
-- columnas base existan en instalaciones nuevas.
ALTER TABLE users MODIFY role ENUM('superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'auditor', 'resident', 'tech_operator') NOT NULL DEFAULT 'guard';
ALTER TABLE role_permissions MODIFY role ENUM('superadmin', 'admin', 'supervisor', 'coordinator', 'guard', 'auditor', 'resident', 'tech_operator') NOT NULL;
ALTER TABLE login_attempts ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER email, ADD COLUMN user_agent VARCHAR(4000) NULL AFTER ip_address, ADD COLUMN location VARCHAR(180) NULL AFTER user_agent;
ALTER TABLE vehicles ADD COLUMN parking_space VARCHAR(60) NULL AFTER destination, ADD INDEX idx_vehicles_parking (tenant_id, parking_space, status);
ALTER TABLE packages MODIFY package_type ENUM('documento', 'paquete', 'mercancia', 'alimento', 'servicios', 'otro') NOT NULL DEFAULT 'paquete', ADD COLUMN service_type VARCHAR(120) NULL AFTER package_type, ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER destination, ADD COLUMN recipient_resident_id BIGINT UNSIGNED NULL AFTER unit_id;
ALTER TABLE login_attempts ADD INDEX idx_login_attempts_user (user_id, attempted_at);
ALTER TABLE packages ADD INDEX idx_packages_unit (tenant_id, unit_id, status);
INSERT INTO permissions (code, name, description) VALUES ('bookings.view', 'Consultar reservas', 'Ver disponibilidad y reservas propias o asignadas'), ('access_events.register', 'Registrar accesos', 'Registrar entradas y salidas con trazabilidad'), ('employees.manage', 'Gestionar empleados', 'Crear fichas, horarios y asistencia'), ('shifts.monitor', 'Supervisar turnos', 'Consultar puestos activos y cerrar turnos con motivo') ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);
INSERT INTO role_permissions (tenant_id, role, permission_code, granted) SELECT id, 'admin', 'shifts.monitor', 1 FROM tenants ON DUPLICATE KEY UPDATE granted = VALUES(granted);
INSERT INTO role_permissions (tenant_id, role, permission_code, granted) SELECT t.id, 'resident', p.code, 1 FROM tenants t INNER JOIN permissions p ON p.code IN ('bookings.view') ON DUPLICATE KEY UPDATE granted = VALUES(granted);
INSERT INTO role_permissions (tenant_id, role, permission_code, granted) SELECT t.id, 'tech_operator', p.code, 1 FROM tenants t INNER JOIN permissions p ON p.code IN ('bookings.view', 'devices.view', 'access_events.register') ON DUPLICATE KEY UPDATE granted = VALUES(granted);
INSERT INTO schema_migrations (migration) VALUES ('2026_09_12_014_qa_completion.sql') ON DUPLICATE KEY UPDATE applied_at = applied_at;


-- Multiclient architecture migration 015 (new installations).
-- AsisControl 015: tenant hierarchy, explicit data ownership and delegated operations.
-- Run after migration 014. Existing tenants retain their IDs and data.
CREATE TABLE IF NOT EXISTS client_types (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  allows_children TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO client_types (code,name,allows_children) VALUES
 ('security_company','Empresa de seguridad',1),
 ('residential','Propiedad horizontal',0),
 ('industrial','Empresa industrial',0),
 ('corporate','Empresa corporativa',0),
 ('custom','Personalizado',0)
ON DUPLICATE KEY UPDATE name=VALUES(name),allows_children=VALUES(allows_children);

ALTER TABLE tenants
  ADD COLUMN client_type_id SMALLINT UNSIGNED NULL,
  ADD COLUMN parent_tenant_id BIGINT UNSIGNED NULL,
  ADD COLUMN root_tenant_id BIGINT UNSIGNED NULL,
  ADD COLUMN commercial_status VARCHAR(32) NOT NULL DEFAULT 'active',
  ADD CONSTRAINT fk_tenant_client_type FOREIGN KEY (client_type_id) REFERENCES client_types(id),
  ADD CONSTRAINT fk_tenant_parent FOREIGN KEY (parent_tenant_id) REFERENCES tenants(id),
  ADD CONSTRAINT fk_tenant_root FOREIGN KEY (root_tenant_id) REFERENCES tenants(id),
  ADD INDEX idx_tenants_parent (parent_tenant_id),
  ADD INDEX idx_tenants_root (root_tenant_id),
  ADD INDEX idx_tenants_client_type (client_type_id);

UPDATE tenants SET client_type_id = (SELECT id FROM client_types WHERE code='custom')
 WHERE client_type_id IS NULL;
UPDATE tenants SET root_tenant_id=id WHERE root_tenant_id IS NULL;
UPDATE tenants SET client_type_id=(SELECT id FROM client_types WHERE code='residential')
 WHERE id=1 AND name='Conjunto Altos del Norte';

CREATE TABLE IF NOT EXISTS tenant_relationships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_tenant_id BIGINT UNSIGNED NOT NULL,
  operator_tenant_id BIGINT UNSIGNED NOT NULL,
  relationship_type ENUM('managed_by','secondary_of') NOT NULL DEFAULT 'managed_by',
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  begins_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ends_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_relation_owner FOREIGN KEY(owner_tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_relation_operator FOREIGN KEY(operator_tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_relation_creator FOREIGN KEY(created_by) REFERENCES users(id),
  UNIQUE KEY uq_relation (owner_tenant_id,operator_tenant_id,relationship_type),
  INDEX idx_relation_operator (operator_tenant_id,status),
  CHECK (owner_tenant_id <> operator_tenant_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_features (
  tenant_id BIGINT UNSIGNED NOT NULL,
  feature_code VARCHAR(80) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(tenant_id,feature_code),
  CONSTRAINT fk_tenant_feature_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

INSERT INTO schema_migrations (migration) VALUES ('2026_10_26_015_client_types_and_hierarchy.sql') ON DUPLICATE KEY UPDATE applied_at=applied_at;


-- Multiclient roles baseline (fresh installs).
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

INSERT INTO schema_migrations (migration) VALUES ('2026_10_26_016_master_roles_and_scopes.sql') ON DUPLICATE KEY UPDATE applied_at=applied_at;

-- Commercial limits and entitlements for fresh installations.
-- Migration 017: normalized, configurable SaaS limits and entitlements.
-- A capacity of zero means unlimited; an absent limit is also unlimited.
CREATE TABLE IF NOT EXISTS plan_limits (
 plan_id BIGINT UNSIGNED NOT NULL,
 resource_code VARCHAR(50) NOT NULL,
 capacity INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (plan_id,resource_code),
 CONSTRAINT fk_plan_limit_plan FOREIGN KEY(plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plan_entitlements (
 plan_id BIGINT UNSIGNED NOT NULL,
 feature_code VARCHAR(80) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (plan_id,feature_code),
 CONSTRAINT fk_plan_entitlement_plan FOREIGN KEY(plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB;

-- Safely translate legacy plan JSON capacity names without hardcoding prices.
INSERT INTO plan_limits (plan_id,resource_code,capacity)
SELECT id,'sites',CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json,'$.sites')) AS UNSIGNED)
FROM subscription_plans WHERE JSON_EXTRACT(limits_json,'$.sites') IS NOT NULL
ON DUPLICATE KEY UPDATE capacity=capacity;

INSERT INTO plan_limits (plan_id,resource_code,capacity)
SELECT id,'units',CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json,'$.units')) AS UNSIGNED)
FROM subscription_plans WHERE JSON_EXTRACT(limits_json,'$.units') IS NOT NULL
ON DUPLICATE KEY UPDATE capacity=capacity;

INSERT INTO schema_migrations (migration) VALUES ('2026_10_26_017_plan_entitlements_and_limits.sql') ON DUPLICATE KEY UPDATE applied_at=applied_at;

-- Security company delegated client management.
-- Migration 018: a dedicated non-global permission for security operators.
INSERT INTO permissions (code,name,description)
VALUES ('clients.manage','Administrar clientes secundarios','Gestionar solo clientes subordinados y autorizados')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description);

INSERT IGNORE INTO master_role_permissions (role_code,permission_code)
VALUES ('tech_operator','clients.manage'),('superadmin','clients.manage');

INSERT INTO role_permissions (tenant_id,role,permission_code,granted)
SELECT t.id,'tech_operator','clients.manage',1 FROM tenants t
ON DUPLICATE KEY UPDATE granted=VALUES(granted);

INSERT INTO schema_migrations (migration) VALUES ('2026_10_26_018_security_operator_clients.sql') ON DUPLICATE KEY UPDATE applied_at=applied_at;
