ALTER TABLE notifications
  ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN last_attempt_at DATETIME NULL AFTER attempt_count,
  ADD COLUMN next_attempt_at DATETIME NULL AFTER last_attempt_at;

ALTER TABLE notifications
  DROP INDEX idx_notifications_status,
  ADD INDEX idx_notifications_status (tenant_id, status, next_attempt_at);
