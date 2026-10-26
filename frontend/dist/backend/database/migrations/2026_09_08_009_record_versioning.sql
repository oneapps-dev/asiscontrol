-- Preserve deleted/archived person records and allow a new document version.
ALTER TABLE people
  ADD COLUMN record_status ENUM('active', 'archived', 'deleted') NOT NULL DEFAULT 'active' AFTER privacy_consent_at,
  ADD COLUMN version_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER record_status,
  ADD COLUMN supersedes_person_id BIGINT UNSIGNED NULL AFTER version_no,
  ADD COLUMN archived_at DATETIME NULL AFTER supersedes_person_id,
  ADD COLUMN archived_by BIGINT UNSIGNED NULL AFTER archived_at,
  ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by,
  DROP INDEX uq_people_tenant_document,
  ADD UNIQUE KEY uq_people_document_version (tenant_id, document_type, document_number, version_no),
  ADD INDEX idx_people_current_document (tenant_id, document_type, document_number, record_status),
  ADD CONSTRAINT fk_people_supersedes FOREIGN KEY (supersedes_person_id) REFERENCES people(id),
  ADD CONSTRAINT fk_people_archived_by FOREIGN KEY (archived_by) REFERENCES users(id);
