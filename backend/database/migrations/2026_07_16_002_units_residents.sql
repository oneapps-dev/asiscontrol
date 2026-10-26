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
