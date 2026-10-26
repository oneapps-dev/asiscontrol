USE vzwquesv_app;

INSERT INTO tenants (id, name, legal_name, document_number)
VALUES (1, 'Conjunto Altos del Norte', 'Conjunto Altos del Norte P.H.', '900000001')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO sites (id, tenant_id, name, address)
VALUES (1, 1, 'Porteria Principal', 'Bogota, Colombia')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (id, tenant_id, site_id, name, email, password_hash, role)
VALUES
  (1, 1, 1, 'Ana Torres', 'admin@asiscontrol.local', '$2y$12$H8Wm2Qsj536V2XOvpz7R9OUgfCfT8qn5FdpfOoVA1hYtRZ/2Frqiq', 'admin'),
  (2, 1, 1, 'Carlos Mendoza', 'guardia@asiscontrol.local', '$2y$12$H8Wm2Qsj536V2XOvpz7R9OUgfCfT8qn5FdpfOoVA1hYtRZ/2Frqiq', 'guard')
ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role);

INSERT INTO permissions (code, name, description)
VALUES
  ('visits.manage', 'Gestionar visitas', 'Crear ingresos, salidas y redimir invitaciones'),
  ('vehicles.manage', 'Gestionar vehiculos', 'Registrar ingresos y salidas de vehiculos'),
  ('packages.manage', 'Gestionar paquetes', 'Registrar, entregar y firmar paquetes'),
  ('bookings.manage', 'Gestionar reservas', 'Crear, confirmar, pagar, cancelar y eliminar reservas de espacios'),
  ('incidents.manage', 'Gestionar incidentes', 'Crear y cerrar incidentes operativos'),
  ('approvals.manage', 'Gestionar aprobaciones', 'Crear y decidir solicitudes de aprobacion'),
  ('reports.view', 'Ver reportes', 'Consultar y exportar reportes operativos'),
  ('audit.view', 'Ver auditoria', 'Consultar y exportar auditoria'),
  ('settings.manage', 'Administrar configuracion', 'Gestionar sedes, usuarios, unidades, residentes y restricciones'),
  ('imports.manage', 'Gestionar importaciones', 'Cargar CSV administrativos'),
  ('files.manage', 'Gestionar adjuntos', 'Subir, listar y descargar evidencias'),
  ('bookings.view', 'Consultar reservas', 'Ver calendario, disponibilidad y próximas reservas'),
  ('devices.manage', 'Gestionar dispositivos', 'Autorizar, validar y revocar dispositivos y puntos de acceso'),
  ('devices.view', 'Consultar dispositivos', 'Ver dispositivos y puntos de acceso autorizados'),
  ('devices.revoke', 'Revocar dispositivos', 'Revocar un dispositivo autorizado'),
  ('offline.resolve', 'Resolver conflictos offline', 'Revisar y resolver conflictos de sincronización'),
  ('backups.manage', 'Gestionar backups', 'Crear y consultar backups técnicos u operativos'),
  ('corrections.manage', 'Gestionar correcciones', 'Corregir registros conservando auditoría'),
  ('access_events.register', 'Registrar accesos', 'Registrar eventos de entrada y salida con idempotencia')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT 1, 'superadmin', code, 1 FROM permissions
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
SELECT 1, 'admin', code, 1 FROM permissions
WHERE code NOT IN ('reports.view', 'audit.view')
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
VALUES
  (1, 'supervisor', 'visits.manage', 1),
  (1, 'supervisor', 'vehicles.manage', 1),
  (1, 'supervisor', 'packages.manage', 1),
  (1, 'supervisor', 'bookings.manage', 1),
  (1, 'supervisor', 'incidents.manage', 1),
  (1, 'supervisor', 'approvals.manage', 1),
  (1, 'supervisor', 'reports.view', 1),
  (1, 'supervisor', 'audit.view', 1),
  (1, 'supervisor', 'bookings.view', 1),
  (1, 'supervisor', 'offline.resolve', 1),
  (1, 'supervisor', 'devices.manage', 1),
  (1, 'supervisor', 'devices.view', 1),
  (1, 'supervisor', 'backups.manage', 1),
  (1, 'supervisor', 'access_events.register', 1),
  (1, 'guard', 'visits.manage', 1),
  (1, 'guard', 'vehicles.manage', 1),
  (1, 'guard', 'packages.manage', 1),
  (1, 'guard', 'incidents.manage', 1),
  (1, 'guard', 'files.manage', 1),
  (1, 'auditor', 'reports.view', 1),
  (1, 'auditor', 'audit.view', 1)
ON DUPLICATE KEY UPDATE granted = VALUES(granted);

DELETE FROM role_permissions WHERE role = 'supervisor' AND permission_code = 'approvals.manage';

INSERT INTO restrictions (tenant_id, document_type, document_number, level, reason)
VALUES
  (1, 'CC', '1111111111', 'block', 'Ingreso bloqueado por administracion'),
  (1, 'CC', '79999111', 'warning', 'Solicitar soporte de ARL antes del ingreso');

INSERT INTO guard_shifts (id, tenant_id, site_id, user_id, checkpoint, notes, started_at)
VALUES (1, 1, 1, 2, 'Porteria Principal', 'Turno demo inicial', DATE_SUB(NOW(), INTERVAL 90 MINUTE))
ON DUPLICATE KEY UPDATE checkpoint = VALUES(checkpoint);

INSERT INTO people (id, tenant_id, person_type, document_type, document_number, full_name, phone, email, company, privacy_consent_at)
VALUES
  (1, 1, 'visitante', 'CC', '1032445678', 'Ricardo Mendoza', '3005550198', NULL, 'Independiente', NOW()),
  (2, 1, 'contratista', 'CC', '79999111', 'Diana Prieto', '3107778822', NULL, 'Mantenimiento Integral', NOW()),
  (3, 1, 'residente', 'CC', '52222444', 'Laura Gomez', '3002223344', 'laura.gomez@asiscontrol.local', NULL, NOW()),
  (4, 1, 'residente', 'CC', '80111222', 'Andres Rojas', '3015556677', 'andres.rojas@asiscontrol.local', NULL, NOW())
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone), email = VALUES(email), company = VALUES(company);

INSERT INTO units (id, tenant_id, site_id, tower, unit_code, floor, status)
VALUES
  (1, 1, 1, 'Torre A', '402', '4', 'active'),
  (2, 1, 1, 'Torre B', '702', '7', 'active'),
  (3, 1, 1, 'Torre C', '301', '3', 'active')
ON DUPLICATE KEY UPDATE floor = VALUES(floor), status = VALUES(status);

INSERT INTO residents (id, tenant_id, unit_id, person_id, relationship, is_primary, receives_notifications, status)
VALUES
  (1, 1, 1, 3, 'propietaria', 1, 1, 'active'),
  (2, 1, 3, 4, 'residente', 1, 1, 'active')
ON DUPLICATE KEY UPDATE relationship = VALUES(relationship), status = VALUES(status);

-- Datos demo de los flujos avanzados.
INSERT INTO people (id, tenant_id, person_type, document_type, document_number, full_name, phone, email, company, privacy_consent_at)
VALUES
  (5, 1, 'empleado', 'CC', '1005006007', 'Natalia Castillo', '3004445566', 'natalia.castillo@asiscontrol.local', 'Creamox SAS', NOW()),
  (6, 1, 'domestico', 'CC', '1007008009', 'Jorge Salazar', '3016667788', NULL, 'Torre A - 402', NOW())
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone), email = VALUES(email), company = VALUES(company), person_type = VALUES(person_type);

INSERT INTO employee_profiles (tenant_id, person_id, employee_code, schedule_start, schedule_end, tolerance_mins, incomplete_after_hours, status)
VALUES (1, 5, 'EMP-001', '07:00:00', '17:00:00', 10, 14, 'active')
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), schedule_start = VALUES(schedule_start), schedule_end = VALUES(schedule_end), status = VALUES(status);

INSERT INTO employee_schedules (tenant_id, employee_profile_id, day_of_week, start_time, end_time, tolerance_mins, is_active)
SELECT 1, id, 1, '07:00:00', '17:00:00', 10, 1 FROM employee_profiles WHERE tenant_id = 1 AND person_id = 5
ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), tolerance_mins = VALUES(tolerance_mins);

INSERT INTO person_qualifications (tenant_id, person_id, qualification_type, reference, valid_until, status)
VALUES (1, 2, 'arl', 'ARL-2026-001', DATE_ADD(CURRENT_DATE, INTERVAL 10 MONTH), 'active'), (1, 2, 'curso_alturas', 'ALT-2026-001', DATE_ADD(CURRENT_DATE, INTERVAL 6 MONTH), 'active')
ON DUPLICATE KEY UPDATE valid_until = VALUES(valid_until), status = VALUES(status);

UPDATE residents SET password_hash = '$2y$12$JGOpWFFRxUv1vE0FBPfTE.egR3XCAmg36QBaUt5PrK42YhvAnag9a', password_set_at = COALESCE(password_set_at, NOW()) WHERE id = 1;

INSERT INTO visits (id, tenant_id, person_id, shift_id, destination, host, reason, vehicle_plate, entered_at, status)
VALUES
  (1, 1, 1, 1, 'Torre A - 402', 'Laura Gomez', 'Visita familiar', NULL, DATE_SUB(NOW(), INTERVAL 42 MINUTE), 'inside'),
  (2, 1, 2, 1, 'Cuarto tecnico', 'Administracion', 'Revision ascensor', 'KLM-428', DATE_SUB(NOW(), INTERVAL 118 MINUTE), 'inside')
ON DUPLICATE KEY UPDATE destination = VALUES(destination), status = VALUES(status);

INSERT INTO vehicles (id, tenant_id, shift_id, plate, driver_name, vehicle_type, destination, entered_at, status)
VALUES (1, 1, 1, 'KLM-428', 'Diana Prieto', 'Camioneta', 'Bahia de servicio', DATE_SUB(NOW(), INTERVAL 118 MINUTE), 'inside')
ON DUPLICATE KEY UPDATE driver_name = VALUES(driver_name), status = VALUES(status);

INSERT INTO packages (id, tenant_id, shift_id, tracking, courier, recipient, destination, received_at, status)
VALUES (1, 1, 1, 'ML-884192', 'Mercado Envio', 'Apartamento 702', 'Torre B', DATE_SUB(NOW(), INTERVAL 23 MINUTE), 'pending')
ON DUPLICATE KEY UPDATE recipient = VALUES(recipient), status = VALUES(status);

INSERT INTO incidents (id, tenant_id, shift_id, user_id, title, severity, area, description, status)
VALUES (1, 1, 1, 2, 'Puerta peatonal con cierre lento', 'media', 'Acceso peatonal', 'Se reporta demora en el cierre automatico. Se informa a mantenimiento.', 'open')
ON DUPLICATE KEY UPDATE title = VALUES(title), status = VALUES(status);

INSERT INTO audit_logs (tenant_id, user_id, action, detail)
VALUES
  (1, 2, 'turno.inicio', 'Inicio de turno demo en Porteria Principal'),
  (1, 2, 'visita.ingreso', 'Ingreso de Ricardo Mendoza'),
  (1, 2, 'paquete.recibido', 'Paquete ML-884192 recibido');

INSERT INTO invitations (tenant_id, site_id, created_by, guest_name, guest_document, destination, host, reason, qr_token, valid_from, valid_until)
VALUES (1, 1, 1, 'Visitante QR Demo', '555123', 'Torre C - 301', 'Andres Rojas', 'Visita programada', SHA2(CONCAT('demo-qr-', NOW()), 256), NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY));

INSERT INTO parking_assignments (tenant_id, site_id, space_code, assigned_to, plate)
VALUES (1, 1, 'V-012', 'Apartamento 402', 'ABC-123')
ON DUPLICATE KEY UPDATE assigned_to = VALUES(assigned_to), plate = VALUES(plate);

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

INSERT INTO backup_policies (tenant_id, schedule, retention_days, encryption_required, enabled, next_run_at, created_by)
SELECT 1, 'monthly', 365, 0, 1, DATE_ADD(CURRENT_DATE, INTERVAL 1 MONTH), 1
WHERE EXISTS (SELECT 1 FROM tenants WHERE id = 1)
  AND NOT EXISTS (SELECT 1 FROM backup_policies WHERE tenant_id = 1);
