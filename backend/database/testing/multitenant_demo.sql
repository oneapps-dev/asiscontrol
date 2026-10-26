-- TEST DATA ONLY. Never execute against an existing production database.
-- Requires install.sql or migrations through 015, plus existing demo seed.
-- Does not modify real client records; do not enable outside an isolated QA database.
INSERT INTO tenants (name,legal_name,client_type_id,commercial_status)
SELECT 'QA Seguridad Norte','QA Seguridad Norte SAS',id,'active' FROM client_types
WHERE code='security_company' AND NOT EXISTS (SELECT 1 FROM tenants WHERE name='QA Seguridad Norte');

INSERT INTO tenants (name,legal_name,client_type_id,commercial_status)
SELECT 'QA Residencial Parque','QA Residencial Parque PH',id,'active' FROM client_types
WHERE code='residential' AND NOT EXISTS (SELECT 1 FROM tenants WHERE name='QA Residencial Parque');

INSERT INTO tenants (name,legal_name,client_type_id,commercial_status)
SELECT 'QA Industria Central','QA Industria Central SAS',id,'active' FROM client_types
WHERE code='industrial' AND NOT EXISTS (SELECT 1 FROM tenants WHERE name='QA Industria Central');

UPDATE tenants SET root_tenant_id=id WHERE root_tenant_id IS NULL;

INSERT INTO tenant_relationships (owner_tenant_id,operator_tenant_id,relationship_type)
SELECT own.id,oper.id,'managed_by' FROM tenants own JOIN tenants oper
 ON own.name='QA Residencial Parque' AND oper.name='QA Seguridad Norte'
ON DUPLICATE KEY UPDATE status='active';

INSERT INTO sites (tenant_id,name,address,timezone)
SELECT t.id,'QA Sede Principal','Dirección ficticia de pruebas','America/Bogota'
FROM tenants t WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
 AND NOT EXISTS (SELECT 1 FROM sites s WHERE s.tenant_id=t.id AND s.name='QA Sede Principal');

-- Generate test users using the existing demo password hash: the test seed must never run in production.
INSERT INTO users (tenant_id,site_id,name,email,password_hash,role,is_active)
SELECT t.id,s.id,CONCAT('QA Operador ',t.name),
 CONCAT('qa-',CASE t.name WHEN 'QA Seguridad Norte' THEN 'seguridad'
 WHEN 'QA Residencial Parque' THEN 'residencial' ELSE 'industria' END,'@asiscontrol.local'),
 demo.password_hash,
 CASE WHEN t.name='QA Seguridad Norte' THEN 'tech_operator' ELSE 'admin' END,1
FROM tenants t JOIN sites s ON s.tenant_id=t.id AND s.name='QA Sede Principal'
JOIN users demo ON demo.email='admin@asiscontrol.local'
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS (SELECT 1 FROM users u WHERE u.tenant_id=t.id AND u.email=CONCAT('qa-',CASE t.name WHEN 'QA Seguridad Norte' THEN 'seguridad' WHEN 'QA Residencial Parque' THEN 'residencial' ELSE 'industria' END,'@asiscontrol.local'));

INSERT INTO users (tenant_id,site_id,name,email,password_hash,role,is_active)
SELECT t.id,s.id,CONCAT('QA Vigilante ',t.name),
 CONCAT('qa-guard-',CASE t.name WHEN 'QA Seguridad Norte' THEN 'seguridad'
 WHEN 'QA Residencial Parque' THEN 'residencial' ELSE 'industria' END,'@asiscontrol.local'),
 demo.password_hash,'guard',1
FROM tenants t JOIN sites s ON s.tenant_id=t.id AND s.name='QA Sede Principal'
JOIN users demo ON demo.email='admin@asiscontrol.local'
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS (SELECT 1 FROM users u WHERE u.tenant_id=t.id AND u.role='guard');

INSERT INTO role_permissions (tenant_id,role,permission_code,granted)
SELECT t.id, rp.role,rp.permission_code,rp.granted
FROM tenants t JOIN role_permissions rp ON rp.tenant_id=1
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
ON DUPLICATE KEY UPDATE granted=VALUES(granted);

INSERT INTO tenant_subscriptions (tenant_id,plan_id,status,starts_on)
SELECT t.id,p.id,'active',CURRENT_DATE FROM tenants t JOIN subscription_plans p
 ON p.code='enterprise' AND p.billing_period='monthly'
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS (SELECT 1 FROM tenant_subscriptions sub WHERE sub.tenant_id=t.id);

INSERT INTO people (tenant_id,person_type,document_type,document_number,full_name,privacy_consent_at)
SELECT t.id,'visitante','CC',CONCAT('QA',t.id,'0001'),CONCAT('Visitante QA ',t.name),NOW()
FROM tenants t WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS (SELECT 1 FROM people p WHERE p.tenant_id=t.id AND p.document_number=CONCAT('QA',t.id,'0001'));

-- Validation: every QA customer is a distinct tenant and no QA operator is platform admin.
SELECT t.id,t.name,ct.code AS client_type,t.root_tenant_id,
 COUNT(DISTINCT u.id) AS test_users FROM tenants t JOIN client_types ct ON ct.id=t.client_type_id
LEFT JOIN users u ON u.tenant_id=t.id WHERE t.name LIKE 'QA %'
GROUP BY t.id,t.name,ct.code,t.root_tenant_id;
