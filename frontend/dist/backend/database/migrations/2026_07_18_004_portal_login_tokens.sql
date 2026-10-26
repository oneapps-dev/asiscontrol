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
