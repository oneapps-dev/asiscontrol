-- Migration 021: configurable business/industrial structure.
-- No changes or deletions to the residential units, residents or sites model.
CREATE TABLE IF NOT EXISTS organization_nodes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  node_type ENUM('building','floor','area','department','office','section','other') NOT NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order INT NOT NULL DEFAULT 0,
  attributes_json JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_org_node_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_org_node_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_org_node_creator FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uq_org_node_code (tenant_id,site_id,code),
  UNIQUE KEY uq_org_node_scope (id,tenant_id,site_id),
  INDEX idx_org_node_parent (parent_id,tenant_id,site_id),
  INDEX idx_org_node_tenant_type (tenant_id,node_type,status),
  CONSTRAINT fk_org_node_parent_scope FOREIGN KEY (parent_id,tenant_id,site_id)
    REFERENCES organization_nodes(id,tenant_id,site_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organization_people (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  node_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NOT NULL,
  relationship ENUM('employee','contractor','supervisor','other') NOT NULL DEFAULT 'employee',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  starts_on DATE NULL,
  ends_on DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_org_person (tenant_id,node_id,person_id),
  INDEX idx_org_person_tenant (tenant_id,person_id,status),
  CONSTRAINT fk_org_person_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_org_person_node FOREIGN KEY (node_id) REFERENCES organization_nodes(id),
  CONSTRAINT fk_org_person_person FOREIGN KEY (person_id) REFERENCES people(id),
  CONSTRAINT fk_org_person_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;
