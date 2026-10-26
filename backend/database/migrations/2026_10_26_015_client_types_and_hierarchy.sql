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
