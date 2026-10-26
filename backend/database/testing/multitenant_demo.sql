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

-- Full flow QA fixtures for isolated databases; rerunnable by natural identifiers.
INSERT IGNORE INTO user_scopes(tenant_id,user_id,scope_type,scope_id)
SELECT u.tenant_id,u.id,
 CASE WHEN u.role IN ('guard','tech_operator','coordinator','supervisor') THEN 'site' ELSE 'tenant' END,
 CASE WHEN u.role IN ('guard','tech_operator','coordinator','supervisor') THEN u.site_id ELSE u.tenant_id END
FROM users u WHERE u.email LIKE 'qa-%@asiscontrol.local';

INSERT INTO guard_shifts(tenant_id,site_id,user_id,checkpoint,notes,started_at)
SELECT t.id,s.id,u.id,'Puesto QA','Turno de prueba automático',DATE_SUB(NOW(),INTERVAL 1 HOUR)
FROM tenants t JOIN sites s ON s.tenant_id=t.id AND s.name='QA Sede Principal'
JOIN users u ON u.tenant_id=t.id AND u.role='guard'
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS(SELECT 1 FROM guard_shifts gs WHERE gs.tenant_id=t.id AND gs.user_id=u.id AND gs.ended_at IS NULL);

INSERT INTO units(tenant_id,site_id,tower,unit_code,floor,status)
SELECT t.id,s.id,'QA Torre A','101','1','active'
FROM tenants t JOIN sites s ON s.tenant_id=t.id AND s.name='QA Sede Principal'
WHERE t.name='QA Residencial Parque'
AND NOT EXISTS(SELECT 1 FROM units u WHERE u.tenant_id=t.id AND u.unit_code='101');

INSERT INTO people(tenant_id,person_type,document_type,document_number,full_name,privacy_consent_at)
SELECT t.id,'residente','CC',CONCAT('QARESID',t.id),'Residente QA',NOW()
FROM tenants t WHERE t.name='QA Residencial Parque'
AND NOT EXISTS(SELECT 1 FROM people p WHERE p.tenant_id=t.id AND p.document_number=CONCAT('QARESID',t.id));

INSERT INTO residents(tenant_id,unit_id,person_id,relationship,is_primary,receives_notifications,status)
SELECT t.id,u.id,p.id,'residente',1,1,'active'
FROM tenants t JOIN units u ON u.tenant_id=t.id AND u.unit_code='101'
JOIN people p ON p.tenant_id=t.id AND p.document_number=CONCAT('QARESID',t.id)
WHERE t.name='QA Residencial Parque'
AND NOT EXISTS(SELECT 1 FROM residents r WHERE r.tenant_id=t.id AND r.person_id=p.id);

INSERT INTO visits(tenant_id,person_id,shift_id,destination,host,reason,entered_at,status)
SELECT t.id,p.id,gs.id,'QA Sede Principal','Equipo QA','Ingreso de prueba multi-tenant',DATE_SUB(NOW(),INTERVAL 30 MINUTE),'inside'
FROM tenants t JOIN people p ON p.tenant_id=t.id AND p.document_number=CONCAT('QA',t.id,'0001')
JOIN guard_shifts gs ON gs.tenant_id=t.id AND gs.ended_at IS NULL
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS(SELECT 1 FROM visits v WHERE v.tenant_id=t.id AND v.person_id=p.id AND v.reason='Ingreso de prueba multi-tenant');

INSERT INTO vehicles(tenant_id,shift_id,plate,driver_name,vehicle_type,destination,entered_at,status)
SELECT t.id,gs.id,CONCAT('QA-',t.id),'Conductor QA','Automóvil','QA Sede Principal',DATE_SUB(NOW(),INTERVAL 20 MINUTE),'inside'
FROM tenants t JOIN guard_shifts gs ON gs.tenant_id=t.id AND gs.ended_at IS NULL
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS(SELECT 1 FROM vehicles v WHERE v.tenant_id=t.id AND v.plate=CONCAT('QA-',t.id));

INSERT INTO packages(tenant_id,shift_id,tracking,courier,recipient,destination,received_at,status)
SELECT t.id,gs.id,CONCAT('QA-PQ-',t.id),'Transportadora QA','Destinatario QA','QA Sede Principal',NOW(),'pending'
FROM tenants t JOIN guard_shifts gs ON gs.tenant_id=t.id AND gs.ended_at IS NULL
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS(SELECT 1 FROM packages p WHERE p.tenant_id=t.id AND p.tracking=CONCAT('QA-PQ-',t.id));

INSERT INTO incidents(tenant_id,shift_id,user_id,title,severity,area,description,status)
SELECT t.id,gs.id,gs.user_id,'Incidente QA multicliente','media','Acceso QA','Evento simulado, no representa incidente real','open'
FROM tenants t JOIN guard_shifts gs ON gs.tenant_id=t.id AND gs.ended_at IS NULL
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS(SELECT 1 FROM incidents i WHERE i.tenant_id=t.id AND i.title='Incidente QA multicliente');

INSERT INTO devices(tenant_id,site_id,name,token_hash,status)
SELECT t.id,s.id,'Dispositivo ficticio QA',SHA2(CONCAT('QA-DEVICE-',t.id),256),'active'
FROM tenants t JOIN sites s ON s.tenant_id=t.id AND s.name='QA Sede Principal'
WHERE t.name IN ('QA Seguridad Norte','QA Residencial Parque','QA Industria Central')
AND NOT EXISTS(SELECT 1 FROM devices d WHERE d.tenant_id=t.id AND d.name='Dispositivo ficticio QA');

SELECT t.name,
 (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id) AS users,
 (SELECT COUNT(*) FROM visits v WHERE v.tenant_id=t.id) AS visits,
 (SELECT COUNT(*) FROM vehicles v WHERE v.tenant_id=t.id) AS vehicles,
 (SELECT COUNT(*) FROM packages p WHERE p.tenant_id=t.id) AS packages,
 (SELECT COUNT(*) FROM incidents i WHERE i.tenant_id=t.id) AS incidents
FROM tenants t WHERE t.name LIKE 'QA %' ORDER BY t.name;


-- Industrial QA structure: independent from residential unit/tenant fixtures.
INSERT INTO organization_nodes (tenant_id,site_id,parent_id,node_type,code,name,sort_order)
SELECT t.id,s.id,NULL,'building','EDIF-QA','Edificio principal QA',10
FROM tenants t JOIN sites s ON s.tenant_id=t.id AND s.name='QA Sede Principal'
WHERE t.name='QA Industria Central'
AND NOT EXISTS (SELECT 1 FROM organization_nodes n WHERE n.tenant_id=t.id AND n.site_id=s.id AND n.code='EDIF-QA');

INSERT INTO organization_nodes (tenant_id,site_id,parent_id,node_type,code,name,sort_order)
SELECT t.id,s.id,p.id,'floor','PISO-QA-1','Primer piso QA',20
FROM tenants t JOIN sites s ON s.tenant_id=t.id AND s.name='QA Sede Principal'
JOIN organization_nodes p ON p.tenant_id=t.id AND p.site_id=s.id AND p.code='EDIF-QA'
WHERE t.name='QA Industria Central'
AND NOT EXISTS (SELECT 1 FROM organization_nodes n WHERE n.tenant_id=t.id AND n.site_id=s.id AND n.code='PISO-QA-1');

INSERT INTO organization_nodes (tenant_id,site_id,parent_id,node_type,code,name,sort_order)
SELECT t.id,s.id,p.id,'area','AREA-QA','Producción QA',30
FROM tenants t JOIN sites s ON s.tenant_id=t.id AND s.name='QA Sede Principal'
JOIN organization_nodes p ON p.tenant_id=t.id AND p.site_id=s.id AND p.code='PISO-QA-1'
WHERE t.name='QA Industria Central'
AND NOT EXISTS (SELECT 1 FROM organization_nodes n WHERE n.tenant_id=t.id AND n.site_id=s.id AND n.code='AREA-QA');

INSERT INTO people (tenant_id,person_type,document_type,document_number,full_name,privacy_consent_at)
SELECT t.id,'empleado','CC',CONCAT('QAEMP',t.id),'Empleado industrial ficticio QA',NOW()
FROM tenants t WHERE t.name='QA Industria Central'
AND NOT EXISTS (SELECT 1 FROM people p WHERE p.tenant_id=t.id AND p.document_number=CONCAT('QAEMP',t.id));
