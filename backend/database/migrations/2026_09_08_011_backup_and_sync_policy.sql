-- Approved operating policy: monthly backups, 12-month retention, email-only notifications for now.
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

INSERT INTO backup_policies (tenant_id, schedule, retention_days, encryption_required, enabled, next_run_at, created_by)
SELECT 1, 'monthly', 365, 0, 1, DATE_ADD(CURRENT_DATE, INTERVAL 1 MONTH), 1
WHERE EXISTS (SELECT 1 FROM tenants WHERE id = 1)
  AND NOT EXISTS (SELECT 1 FROM backup_policies WHERE tenant_id = 1);

-- A remote event is never deleted; offline_conflicts stores the automatic decision note.
ALTER TABLE offline_conflicts
  ADD COLUMN decision ENUM('latest_wins', 'manual_review') NOT NULL DEFAULT 'manual_review' AFTER reason,
  ADD COLUMN system_note VARCHAR(500) NULL AFTER decision,
  ADD INDEX idx_offline_conflicts_decision (tenant_id, decision, created_at);
