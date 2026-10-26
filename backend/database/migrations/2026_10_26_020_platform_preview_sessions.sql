-- Migration 020: CREAMOX read-only support previews.
-- A preview does NOT change the authenticated identity or the active tenant.
CREATE TABLE IF NOT EXISTS platform_preview_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  target_tenant_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  target_role VARCHAR(40) NOT NULL,
  nonce_hash CHAR(64) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('active','stopped','expired','revoked') NOT NULL DEFAULT 'active',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  last_accessed_at DATETIME NULL,
  ended_at DATETIME NULL,
  start_ip VARCHAR(45) NULL,
  end_ip VARCHAR(45) NULL,
  end_reason VARCHAR(80) NULL,
  context_json JSON NULL,
  CONSTRAINT fk_preview_actor FOREIGN KEY(actor_user_id) REFERENCES users(id),
  CONSTRAINT fk_preview_tenant FOREIGN KEY(target_tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_preview_target FOREIGN KEY(target_user_id) REFERENCES users(id),
  UNIQUE KEY uq_preview_nonce (nonce_hash),
  INDEX idx_preview_actor_state (actor_user_id,status,expires_at),
  INDEX idx_preview_tenant_started (target_tenant_id,started_at)
) ENGINE=InnoDB;
