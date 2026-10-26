-- Migration 023: explicit cross-tenant memberships for authorized employees.
-- A membership never changes users.tenant_id (home identity) or grants access
-- by tenant ID alone. It is effective only while its origin and target validate.
CREATE TABLE IF NOT EXISTS tenant_user_memberships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  role_code VARCHAR(32) NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  origin ENUM('platform','delegated') NOT NULL,
  operator_tenant_id BIGINT UNSIGNED NULL,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  expires_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  CONSTRAINT fk_cross_member_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_cross_member_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_cross_member_role FOREIGN KEY (role_code) REFERENCES master_roles(code),
  CONSTRAINT fk_cross_member_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_cross_member_operator FOREIGN KEY (operator_tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_cross_member_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_cross_member_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
  UNIQUE KEY uq_cross_member_user_tenant (user_id,tenant_id),
  INDEX idx_cross_member_tenant_status (tenant_id,status,expires_at),
  INDEX idx_cross_member_user_status (user_id,status,expires_at),
  INDEX idx_cross_member_operator (operator_tenant_id,tenant_id),
  CONSTRAINT chk_cross_member_origin CHECK (
    (origin='platform' AND operator_tenant_id IS NULL)
    OR (origin='delegated' AND operator_tenant_id IS NOT NULL)
  )
) ENGINE=InnoDB;
