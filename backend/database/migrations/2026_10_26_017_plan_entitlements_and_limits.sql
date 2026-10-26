-- Migration 017: normalized, configurable SaaS limits and entitlements.
-- A capacity of zero means unlimited; an absent limit is also unlimited.
CREATE TABLE IF NOT EXISTS plan_limits (
 plan_id BIGINT UNSIGNED NOT NULL,
 resource_code VARCHAR(50) NOT NULL,
 capacity INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (plan_id,resource_code),
 CONSTRAINT fk_plan_limit_plan FOREIGN KEY(plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plan_entitlements (
 plan_id BIGINT UNSIGNED NOT NULL,
 feature_code VARCHAR(80) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (plan_id,feature_code),
 CONSTRAINT fk_plan_entitlement_plan FOREIGN KEY(plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB;

-- Safely translate legacy plan JSON capacity names without hardcoding prices.
INSERT INTO plan_limits (plan_id,resource_code,capacity)
SELECT id,'sites',CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json,'$.sites')) AS UNSIGNED)
FROM subscription_plans WHERE JSON_EXTRACT(limits_json,'$.sites') IS NOT NULL
ON DUPLICATE KEY UPDATE capacity=capacity;

INSERT INTO plan_limits (plan_id,resource_code,capacity)
SELECT id,'units',CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json,'$.units')) AS UNSIGNED)
FROM subscription_plans WHERE JSON_EXTRACT(limits_json,'$.units') IS NOT NULL
ON DUPLICATE KEY UPDATE capacity=capacity;
