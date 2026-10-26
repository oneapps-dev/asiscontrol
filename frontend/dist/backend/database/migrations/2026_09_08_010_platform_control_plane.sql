-- Provider control plane for customer accounts, subscriptions and metrics.
CREATE TABLE IF NOT EXISTS platform_admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  access_level ENUM('support', 'operator', 'owner') NOT NULL DEFAULT 'operator',
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_platform_admins_user FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_platform_admins_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  billing_period ENUM('monthly', 'annual', 'lifetime') NOT NULL DEFAULT 'monthly',
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  limits_json JSON NULL,
  features_json JSON NULL,
  status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subscription_plans_code_period (code, billing_period)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  status ENUM('trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired') NOT NULL DEFAULT 'trial',
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  auto_renew TINYINT(1) NOT NULL DEFAULT 0,
  external_reference VARCHAR(160) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenant_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_tenant_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
  CONSTRAINT fk_tenant_subscriptions_creator FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_tenant_subscriptions_lookup (tenant_id, status, ends_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_account_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_platform_invites_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_platform_invites_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_platform_invites_creator FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uq_platform_invites_token (token_hash),
  INDEX idx_platform_invites_tenant (tenant_id, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  tenant_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  detail VARCHAR(255) NOT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_platform_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
  CONSTRAINT fk_platform_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  INDEX idx_platform_audit_created (created_at),
  INDEX idx_platform_audit_tenant (tenant_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_metrics_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  metric_date DATE NOT NULL,
  active_users INT UNSIGNED NOT NULL DEFAULT 0,
  visits_count INT UNSIGNED NOT NULL DEFAULT 0,
  access_events_count INT UNSIGNED NOT NULL DEFAULT 0,
  bookings_count INT UNSIGNED NOT NULL DEFAULT 0,
  packages_count INT UNSIGNED NOT NULL DEFAULT 0,
  incidents_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenant_metrics_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  UNIQUE KEY uq_tenant_metrics_day (tenant_id, metric_date),
  INDEX idx_tenant_metrics_date (metric_date)
) ENGINE=InnoDB;

INSERT INTO subscription_plans (code, name, billing_period, price, limits_json, features_json)
VALUES
  ('starter', 'Starter', 'monthly', 180000, JSON_OBJECT('units', 50, 'sites', 1), JSON_ARRAY('visits', 'packages', 'incidents')),
  ('basic', 'Básico', 'monthly', 280000, JSON_OBJECT('units', 150, 'sites', 2), JSON_ARRAY('visits', 'packages', 'bookings', 'reports')),
  ('standard', 'Estándar', 'monthly', 420000, JSON_OBJECT('units', 400, 'sites', 3), JSON_ARRAY('all_mvp', 'portal', 'offline')),
  ('advanced', 'Avanzado', 'monthly', 650000, JSON_OBJECT('units', 800, 'sites', 6), JSON_ARRAY('all_mvp', 'portal', 'offline', 'devices')),
  ('enterprise', 'Enterprise', 'monthly', 1500000, JSON_OBJECT('units', 0, 'sites', 0), JSON_ARRAY('all_features', 'multi_tenant'))
ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price), limits_json = VALUES(limits_json), features_json = VALUES(features_json);

INSERT INTO platform_admins (user_id, access_level, status)
SELECT id, 'owner', 'active' FROM users WHERE role = 'admin' AND id = 1
ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), status = VALUES(status);

INSERT INTO tenant_subscriptions (tenant_id, plan_id, status, starts_on, auto_renew, created_by)
SELECT 1, id, 'active', CURRENT_DATE, 0, 1 FROM subscription_plans WHERE code = 'starter' AND billing_period = 'monthly'
  AND NOT EXISTS (SELECT 1 FROM tenant_subscriptions WHERE tenant_id = 1)
LIMIT 1;
