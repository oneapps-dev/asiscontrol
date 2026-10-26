CREATE TABLE IF NOT EXISTS booking_resources (
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

CREATE TABLE IF NOT EXISTS bookings (
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

INSERT INTO booking_resources (id, tenant_id, site_id, name, resource_type, capacity, price_per_hour, price_per_day, color, rules)
VALUES
  (1, 1, 1, 'Salon social', 'Zona social', 60, 45000, 280000, '#2563eb', 'Requiere deposito, firma de reglamento y entrega de inventario.'),
  (2, 1, 1, 'Sala de reuniones', 'Sala empresarial', 12, 35000, 190000, '#7c3aed', 'Incluye proyector, wifi y tablero.')
ON DUPLICATE KEY UPDATE name = VALUES(name), price_per_hour = VALUES(price_per_hour), price_per_day = VALUES(price_per_day), rules = VALUES(rules);

INSERT INTO permissions (code, name, description)
VALUES ('bookings.manage', 'Gestionar reservas', 'Crear, confirmar, pagar, cancelar y eliminar reservas de espacios')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
VALUES
  (1, 'supervisor', 'bookings.manage', 1),
  (1, 'guard', 'bookings.manage', 1)
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT 1, 'admin', 'bookings.manage', 1
WHERE EXISTS (SELECT 1 FROM permissions WHERE code = 'bookings.manage')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);
