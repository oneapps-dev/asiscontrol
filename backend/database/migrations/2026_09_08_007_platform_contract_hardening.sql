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
  status ENUM('pending', 'resolved', 'dismissed') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  resolution_notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_offline_conflicts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_offline_conflicts_remote FOREIGN KEY (remote_event_id) REFERENCES access_events(id),
  CONSTRAINT fk_offline_conflicts_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
  UNIQUE KEY uq_offline_conflicts_event (tenant_id, local_event_uuid),
  INDEX idx_offline_conflicts_status (tenant_id, status, created_at)
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
SELECT t.id, 'admin', p.code, 1
FROM tenants t
JOIN permissions p ON p.code IN ('bookings.view', 'devices.view', 'devices.revoke', 'corrections.manage', 'access_events.register')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, 'supervisor', p.code, 1
FROM tenants t
JOIN permissions p ON p.code IN ('bookings.view', 'offline.resolve', 'devices.manage', 'backups.manage', 'access_events.register')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT t.id, 'guard', p.code, 1
FROM tenants t
JOIN permissions p ON p.code IN ('bookings.view', 'access_events.register')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);
