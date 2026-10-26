<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/multitenant.php';
require __DIR__ . '/plan_limits.php';
require __DIR__ . '/tenant_hierarchy.php';
require __DIR__ . '/platform_support.php';
require __DIR__ . '/organization.php';

$method = $_SERVER['REQUEST_METHOD'];
$apiStage = 'router';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/backend/api#', '', $path);
$path = preg_replace('#^/api#', '', $path);
$path = '/' . trim((string) $path, '/');

$publicPostRoutes = ['/auth/login', '/auth/password/request', '/auth/password/reset', '/portal/auth/request', '/portal/auth/verify', '/portal/auth/login', '/platform/account/setup'];
$isPortalRoute = str_starts_with($path, '/portal/');
$isPublicPost = $method === 'POST' && in_array($path, $publicPostRoutes, true);
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !$isPublicPost) {
    if ($isPortalRoute) {
        require_portal_auth();
    } else {
        require_auth();
    }
    require_csrf();
}

try {
    platform_preview_readonly_guard($method,$path);
    enforce_legacy_scope_gate($method,$path);
    handle_multitenant_routes($method, $path);
    handle_plan_routes($method, $path);
    handle_tenant_hierarchy_routes($method, $path);
    handle_platform_preview_routes($method, $path);
    handle_organization_routes($method, $path);
    if ($method === 'GET' && $path === '/health') {
        $apiStage = 'health.database';
        try {
            // Check the configured database as well as the PHP route. This
            // makes hosting diagnostics distinguish routing from credentials.
            $pdo = db();
            $requiredTables = ['tenants', 'users', 'login_attempts'];
            $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
            $tableStmt = $pdo->prepare(
                'SELECT TABLE_NAME FROM information_schema.tables
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')'
            );
            $tableStmt->execute($requiredTables);
            $existingTables = array_map('strval', $tableStmt->fetchAll(PDO::FETCH_COLUMN));
            $missingTables = array_values(array_diff($requiredTables, $existingTables));
            if ($missingTables) {
                json_response([
                    'ok' => false,
                    'service' => 'Asiscontrol API',
                    'database' => 'ok',
                    'schema' => 'incompleto',
                    'missing_tables' => $missingTables,
                    'message' => 'Ejecuta backend/database/install.sql o backend/scripts/run_migrations.php.',
                ], 503);
            }
            $requiredColumns = [
                'tenants' => ['id', 'name', 'status'],
                'users' => ['id', 'tenant_id', 'name', 'email', 'role', 'password_hash', 'is_active'],
                'login_attempts' => ['email', 'ip_address', 'success', 'attempted_at'],
            ];
            $missingColumns = [];
            foreach ($requiredColumns as $table => $columns) {
                $columnPlaceholders = implode(',', array_fill(0, count($columns), '?'));
                $columnStmt = $pdo->prepare(
                    'SELECT COLUMN_NAME FROM information_schema.columns
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                       AND COLUMN_NAME IN (' . $columnPlaceholders . ')'
                );
                $columnStmt->execute(array_merge([$table], $columns));
                $existingColumns = array_map('strval', $columnStmt->fetchAll(PDO::FETCH_COLUMN));
                foreach (array_diff($columns, $existingColumns) as $column) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
            if ($missingColumns) {
                json_response([
                    'ok' => false,
                    'service' => 'Asiscontrol API',
                    'database' => 'ok',
                    'schema' => 'incompleto',
                    'missing_columns' => array_values($missingColumns),
                    'message' => 'La base fue creada con una versión anterior. Ejecuta las migraciones de Asiscontrol.',
                ], 503);
            }
            // Execute the same read-only join used by the login endpoint. A
            // schema can contain all expected names while the hosting SQL
            // mode or grants still prevent authentication queries.
            try {
                $pdo->query("SELECT u.id FROM users u INNER JOIN tenants t ON t.id = u.tenant_id WHERE u.is_active = 1 AND t.status = 'active' LIMIT 1");
                $pdo->query('SELECT email, ip_address, success, attempted_at FROM login_attempts LIMIT 1');
            } catch (Throwable $exception) {
                error_log('[Asiscontrol] health authentication probe failed: ' . $exception->getMessage());
                $probePayload = [
                    'ok' => false,
                    'service' => 'Asiscontrol API',
                    'database' => 'ok',
                    'schema' => 'ok',
                    'auth_query' => 'failed',
                    'request_id' => $_SERVER['ASISCONTROL_REQUEST_ID'] ?? null,
                ];
                $probeConfig = require dirname(__DIR__) . '/config/config.php';
                if (($probeConfig['app_env'] ?? 'local') === 'local' || !empty($probeConfig['app_debug'])) {
                    $probePayload['detail'] = $exception->getMessage();
                }
                json_response($probePayload, 503);
            }
            json_response(['ok' => true, 'service' => 'Asiscontrol API', 'database' => 'ok', 'schema' => 'ok']);
        } catch (Throwable $exception) {
            error_log('[Asiscontrol] health database check failed: ' . $exception->getMessage());
            $payload = [
                'ok' => false,
                'service' => 'Asiscontrol API',
                'database' => 'unavailable',
                'request_id' => $_SERVER['ASISCONTROL_REQUEST_ID'] ?? null,
            ];
            $healthConfig = require dirname(__DIR__) . '/config/config.php';
            if (($healthConfig['app_env'] ?? 'local') === 'local' || !empty($healthConfig['app_debug'])) {
                $payload['detail'] = $exception->getMessage();
            }
            json_response($payload, 503);
        }
    }

    if ($method === 'POST' && $path === '/auth/login') {
        $apiStage = 'login.input';
        $data = request_json();
        require_fields($data, ['email', 'password']);
        $loginEmail = strtolower(trim((string) $data['email']));
        $apiStage = 'login.rate_limit';
        // Older installations may not have the optional rate-limit table yet.
        // The helper logs that condition and lets the migration be completed
        // without making every valid login fail with HTTP 500.
        assert_login_allowed($loginEmail);

        $apiStage = 'login.lookup';
        // Use SQL single quotes for enum literals. This remains valid even on
        // hosts that enable ANSI_QUOTES at the server level.
        $stmt = db()->prepare(
            "SELECT u.id, u.tenant_id, u.name, u.email, u.role, u.password_hash, t.name AS tenant_name
             FROM users u
             INNER JOIN tenants t ON t.id = u.tenant_id
             WHERE u.email = :email AND u.is_active = 1 AND t.status = 'active'
             LIMIT 1"
        );
        $stmt->execute(['email' => $loginEmail]);
        $user = $stmt->fetch();

        // Residents authenticate through the same login screen. Their
        // resident profile remains authoritative; the tenant's system user
        // id is used only where audit foreign keys require a users row.
        $residentLogin = false;
        if (!$user) {
            $resident = one_row(
                "SELECT r.id AS resident_id, r.tenant_id, r.unit_id, r.password_hash,
                        p.full_name AS name, p.email, s.id AS site_id, s.name AS site_name,
                        t.name AS tenant_name
                 FROM residents r
                 INNER JOIN people p ON p.id = r.person_id
                 INNER JOIN units u ON u.id = r.unit_id
                 INNER JOIN sites s ON s.id = u.site_id
                 INNER JOIN tenants t ON t.id = r.tenant_id
                 WHERE p.email = :email AND r.status = 'active' AND p.record_status = 'active'
                 LIMIT 1",
                ['email' => $loginEmail]
            );
            if ($resident && !empty($resident['password_hash'])) {
                $systemUser = one_row('SELECT id FROM users WHERE tenant_id = :tenant_id AND is_active = 1 ORDER BY id LIMIT 1', ['tenant_id' => $resident['tenant_id']]);
                if ($systemUser) {
                    $user = [
                        'id' => (int) $systemUser['id'],
                        'tenant_id' => (int) $resident['tenant_id'],
                        'name' => $resident['name'],
                        'email' => $resident['email'],
                        'role' => 'resident',
                        'password_hash' => $resident['password_hash'],
                        'tenant_name' => $resident['tenant_name'],
                        'site_id' => $resident['site_id'],
                        'site_name' => $resident['site_name'],
                        'resident_id' => $resident['resident_id'],
                        'unit_id' => $resident['unit_id'],
                    ];
                    $residentLogin = true;
                }
            }
        }

        if (!$user || !password_verify((string) $data['password'], (string) $user['password_hash'])) {
            record_login_attempt($loginEmail, false);
            json_response(['error' => 'Credenciales invalidas'], 401);
        }

        $apiStage = 'login.record_attempt';
        record_login_attempt($loginEmail, true, (int) ($user['id'] ?? 0));
        // Rotate the session identifier after authentication to prevent
        // session-fixation attacks while preserving the CSRF token lifecycle.
        $apiStage = 'login.session';
        session_regenerate_id(true);
        unset($user['password_hash']);
        $user['platform_admin'] = is_platform_admin($user);
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
        if ($residentLogin && !empty($user['resident_id'])) {
            db()->prepare('UPDATE residents SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $user['resident_id']]);
        }
        audit('sesion.inicio', 'Inicio de sesión' . ($residentLogin ? ' de residente #' . (int) ($user['resident_id'] ?? 0) : '') . ' desde IP ' . client_ip());
        json_response(['user' => $user, 'csrf_token' => csrf_token()]);
    }

    if ($method === 'POST' && $path === '/auth/password/request') {
        $data = request_json();
        require_fields($data, ['email']);
        create_password_reset_request((string) $data['email']);
        json_response(['ok' => true, 'message' => 'Si el correo existe, se enviaran instrucciones.']);
    }

    if ($method === 'POST' && $path === '/auth/password/reset') {
        $data = request_json();
        require_fields($data, ['token', 'password']);
        reset_password((string) $data['token'], (string) $data['password']);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/portal/auth/request') {
        $data = request_json();
        require_fields($data, ['email']);
        $token = create_portal_login_request((string) $data['email']);
        $payload = ['ok' => true, 'message' => 'Si el correo pertenece a un residente activo, se enviara un token de acceso.'];
        $config = require dirname(__DIR__) . '/config/config.php';
        if (($config['app_env'] ?? 'local') === 'local' && $token) {
            $payload['debug_token'] = $token;
        }
        json_response($payload);
    }

    if ($method === 'POST' && $path === '/portal/auth/verify') {
        $data = request_json();
        require_fields($data, ['token']);
        $resident = verify_portal_login((string) $data['token']);
        session_regenerate_id(true);
        $_SESSION['portal_resident'] = $resident;
        json_response(['resident' => $resident, 'csrf_token' => csrf_token()]);
    }

    if ($method === 'POST' && $path === '/portal/auth/login') {
        $data = request_json();
        require_fields($data, ['email', 'password']);
        $portalEmail = strtolower(trim((string) $data['email']));
        assert_login_allowed($portalEmail);
        $resident = one_row(
            'SELECT r.id, r.tenant_id, r.unit_id, r.person_id, p.full_name, p.email, p.phone, u.tower, u.unit_code, u.floor, s.id AS site_id, s.name AS site_name, r.password_hash
             FROM residents r INNER JOIN people p ON p.id = r.person_id INNER JOIN units u ON u.id = r.unit_id INNER JOIN sites s ON s.id = u.site_id
             WHERE p.email = :email AND r.status = "active" AND p.record_status = "active" LIMIT 1',
            ['email' => $portalEmail]
        );
        if (!$resident || empty($resident['password_hash']) || !password_verify((string) $data['password'], (string) $resident['password_hash'])) {
            record_login_attempt($portalEmail, false);
            json_response(['error' => 'Correo o contraseña de residente inválidos'], 401);
        }
        record_login_attempt($portalEmail, true);
        db()->prepare('UPDATE residents SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $resident['id']]);
        session_regenerate_id(true);
        unset($resident['password_hash']);
        $_SESSION['portal_resident'] = $resident;
        $_SESSION['portal_last_activity'] = time();
        json_response(['resident' => $resident, 'csrf_token' => csrf_token()]);
    }

    if ($method === 'POST' && $path === '/portal/auth/logout') {
        unset($_SESSION['portal_resident']);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/portal/me') {
        json_response(['resident' => require_portal_auth(), 'csrf_token' => csrf_token()]);
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        session_destroy();
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/me') {
        $user = require_auth();
        $user['platform_admin'] = is_platform_admin($user);
        $_SESSION['user'] = $user;
        json_response(['user' => $user, 'csrf_token' => csrf_token()]);
    }

    if ($method === 'POST' && $path === '/platform/account/setup') {
        $data = request_json();
        require_fields($data, ['token', 'password']);
        if (strlen((string) $data['password']) < 10) {
            json_response(['error' => 'La contraseña debe tener al menos 10 caracteres'], 422);
        }
        $invite = one_row(
            'SELECT id, user_id, tenant_id FROM platform_account_invites
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            ['token_hash' => hash('sha256', trim((string) $data['token']))]
        );
        if (!$invite) {
            json_response(['error' => 'Invitación inválida o vencida'], 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, name = COALESCE(NULLIF(:name, ""), name), is_active = 1 WHERE id = :user_id AND tenant_id = :tenant_id');
        $stmt->execute([
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'name' => trim((string) ($data['name'] ?? '')),
            'user_id' => $invite['user_id'],
            'tenant_id' => $invite['tenant_id'],
        ]);
        $stmt = $pdo->prepare('UPDATE platform_account_invites SET used_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $invite['id']]);
        $pdo->commit();
        platform_audit(null, (int) $invite['tenant_id'], 'cuenta.activada', 'Cuenta de cliente activada');
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/shifts') {
        require_permission('shifts.monitor');
        json_response(['data' => scope_filter_rows('shifts',list_rows(
            'SELECT gs.*, u.name AS user_name, s.name AS site_name
             FROM guard_shifts gs INNER JOIN users u ON u.id = gs.user_id INNER JOIN sites s ON s.id = gs.site_id
             WHERE gs.tenant_id = :tenant_id ORDER BY gs.ended_at IS NULL DESC, gs.started_at DESC LIMIT 200',
            ['tenant_id' => tenant_id()]
        ),require_auth())]);
    }

    if ($method === 'POST' && preg_match('#^/shifts/(\d+)/force-close$#', $path, $matches)) {
        $user = require_permission('shifts.monitor');
        $data = request_json();
        require_fields($data, ['reason']);
        $stmt = db()->prepare('UPDATE guard_shifts SET ended_at = NOW(), notes = CONCAT(COALESCE(notes, ""), :note) WHERE id = :id AND tenant_id = :tenant_id AND ended_at IS NULL');
        $stmt->execute(['note' => "\nCierre forzado: " . trim((string) $data['reason']), 'id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        audit('turno.cierre_forzado', 'Cierre forzado del turno #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/shifts') {
        $user = require_auth();
        $data = request_json();
        require_fields($data, ['site_id', 'checkpoint']);
        require_tenant_scope('site',(int)$data['site_id']);
        $stmt = db()->prepare(
            'INSERT INTO guard_shifts (tenant_id, site_id, user_id, checkpoint, notes, started_at)
             VALUES (:tenant_id, :site_id, :user_id, :checkpoint, :notes, NOW())'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'site_id' => $data['site_id'],
            'user_id' => $user['id'],
            'checkpoint' => $data['checkpoint'],
            'notes' => $data['notes'] ?? null,
        ]);
        audit('turno.inicio', 'Inicio de turno');
        json_response(['id' => db()->lastInsertId()], 201);
    }

    if ($method === 'POST' && preg_match('#^/shifts/(\d+)/close$#', $path, $matches)) {
        $user = require_auth();
        $stmt = db()->prepare('UPDATE guard_shifts SET ended_at = NOW() WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id AND ended_at IS NULL');
        $stmt->execute(['id' => $matches[1], 'tenant_id' => $user['tenant_id'], 'user_id' => $user['id']]);
        audit('turno.cierre', 'Cierre de turno');
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/visits') {
        $stmt = db()->prepare(
            'SELECT v.*, p.full_name, p.document_type, p.document_number
             FROM visits v
             INNER JOIN people p ON p.id = v.person_id
             WHERE v.tenant_id = :tenant_id
             ORDER BY v.entered_at DESC
             LIMIT 200'
        );
        $stmt->execute(['tenant_id' => tenant_id()]);
        json_response(['data' => scope_filter_rows('visits',$stmt->fetchAll(),require_auth())]);
    }

    if ($method === 'GET' && $path === '/people') {
        require_tenant_wide_scope();
        json_response(['data' => list_rows(
            'SELECT p.*, latest.version_no AS latest_version_no
             FROM people p
             LEFT JOIN people latest ON latest.tenant_id = p.tenant_id
               AND latest.document_type = p.document_type
               AND latest.document_number = p.document_number
               AND latest.version_no = (SELECT MAX(v.version_no) FROM people v WHERE v.tenant_id = p.tenant_id AND v.document_type = p.document_type AND v.document_number = p.document_number)
             WHERE p.tenant_id = :tenant_id
             ORDER BY p.full_name, p.version_no DESC LIMIT 500',
            ['tenant_id' => tenant_id()]
        )]);
    }

    if ($method === 'POST' && preg_match('#^/people/(\d+)/archive$#', $path, $matches)) {
        $user = require_permission('settings.manage');
        $data = request_json();
        $recordStatus = (string) ($data['status'] ?? 'archived');
        if (!in_array($recordStatus, ['archived', 'deleted'], true)) {
            json_response(['error' => 'Estado de archivo invalido'], 422);
        }
        $stmt = db()->prepare(
            'UPDATE people SET record_status = :record_status, archived_at = NOW(), archived_by = :archived_by, archive_reason = :archive_reason
             WHERE id = :id AND tenant_id = :tenant_id AND record_status = "active"'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => $user['tenant_id'],
            'record_status' => $recordStatus,
            'archived_by' => $user['id'],
            'archive_reason' => trim((string) ($data['reason'] ?? '')) ?: null,
        ]);
        audit('persona.archivada', 'Persona #' . $matches[1] . ' marcada como ' . humanize_code_label($recordStatus));
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/visits') {
        $user = require_permission('visits.manage');
        $data = request_json();
        require_fields($data, ['shift_id', 'document_type', 'document_number', 'full_name', 'destination', 'host', 'reason']);
        $data['shift_id'] = active_shift_id_for($user, $data['shift_id']);
        if (!$data['shift_id']) json_response(['error' => 'Selecciona un turno activo para registrar la visita', 'error_code' => 'ACTIVE_SHIFT_REQUIRED'], 422);
        $shiftSiteId=validated_shift_site((int)$data['shift_id'],$user,!empty($data['site_id'])?(int)$data['site_id']:null);
        $pdo = db();
        $pdo->beginTransaction();

        $personId = upsert_person($pdo, (int) $user['tenant_id'], $data);
        $stmt = $pdo->prepare(
            'INSERT INTO visits (tenant_id, person_id, shift_id, site_id, operator_user_id, identification_method, destination, host, reason, vehicle_plate, entered_at, status)
             VALUES (:tenant_id, :person_id, :shift_id, :site_id, :operator_user_id, :identification_method, :destination, :host, :reason, :vehicle_plate, NOW(), "inside")'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'person_id' => $personId,
            'shift_id' => $data['shift_id'],
            'site_id' => $shiftSiteId,
            'operator_user_id' => $user['id'],
            'identification_method' => in_array(($data['identification_method'] ?? 'manual'), ['huella', 'cedula', 'manual', 'qr', 'otro'], true) ? $data['identification_method'] : 'manual',
            'destination' => $data['destination'],
            'host' => $data['host'],
            'reason' => $data['reason'],
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
        ]);
        $visitId = $pdo->lastInsertId();
        $pdo->commit();
        audit('visita.ingreso', 'Registro de ingreso #' . $visitId);
        json_response(['id' => $visitId], 201);
    }

    if ($method === 'POST' && preg_match('#^/visits/(\d+)/exit$#', $path, $matches)) {
        require_permission('visits.manage');
        require_scoped_record('visits',(int)$matches[1]);
        $data = request_json();
        $stmt = db()->prepare(
            'UPDATE visits
             SET status = "exited", exited_at = NOW(), exit_observations = :exit_observations, exit_type = "normal", exit_by = :exit_by
             WHERE id = :id AND tenant_id = :tenant_id AND status = "inside"'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => tenant_id(),
            'exit_observations' => trim((string) ($data['observations'] ?? '')) ?: null,
            'exit_by' => $_SESSION['user']['id'] ?? null,
        ]);
        audit('visita.salida', 'Registro de salida #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/bootstrap') {
        $tenantId = tenant_id();
        $bootstrapUser = require_auth();
        $bootstrapPermissions = permissions_for_role($tenantId, (string) $bootstrapUser['role']);
        $canViewDevices = (string) $bootstrapUser['role'] === 'superadmin' || in_array('devices.view', $bootstrapPermissions, true);
        $isResident = (string) ($bootstrapUser['role'] ?? '') === 'resident';
        $residentProfile = $isResident ? portal_resident_by_id((int) ($bootstrapUser['resident_id'] ?? 0), $tenantId) : null;
        $residentDestination = $residentProfile ? portal_resident_destination($residentProfile) : '';
        $bootstrapSites = $isResident && $residentProfile
            ? list_rows('SELECT * FROM sites WHERE tenant_id = :tenant_id AND id = :site_id', ['tenant_id' => $tenantId, 'site_id' => $residentProfile['site_id']])
            : list_rows('SELECT * FROM sites WHERE tenant_id = :tenant_id ORDER BY name', ['tenant_id' => $tenantId]);
        $bootstrapVisits = $isResident && $residentProfile
            ? list_rows('SELECT v.*, p.person_type, p.full_name, p.document_type, p.document_number, p.phone, p.company FROM visits v INNER JOIN people p ON p.id = v.person_id WHERE v.tenant_id = :tenant_id AND (v.host = :host OR v.destination = :destination) ORDER BY v.entered_at DESC LIMIT 100', ['tenant_id' => $tenantId, 'host' => $residentProfile['full_name'], 'destination' => $residentDestination])
            : visits_for_tenant($tenantId);
        $bootstrapBookings = $isResident && $residentProfile
            ? list_rows('SELECT b.*, br.name AS resource_name, br.color AS resource_color, br.resource_type FROM bookings b INNER JOIN booking_resources br ON br.id = b.resource_id WHERE b.tenant_id = :tenant_id AND b.requester_name = :requester_name ORDER BY b.starts_at ASC LIMIT 100', ['tenant_id' => $tenantId, 'requester_name' => $residentProfile['full_name']])
            : bookings_for_tenant($tenantId);
        $bootstrapInvitations = $isResident && $residentProfile
            ? list_rows('SELECT * FROM invitations WHERE tenant_id = :tenant_id AND destination = :destination AND host = :host ORDER BY created_at DESC LIMIT 100', ['tenant_id' => $tenantId, 'destination' => $residentDestination, 'host' => $residentProfile['full_name']])
            : list_rows('SELECT * FROM invitations WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 200', ['tenant_id' => $tenantId]);
        $bootstrapPayload = [
            'csrf_token' => csrf_token(),
            'platform_admin' => is_platform_admin($bootstrapUser),
            'data' => [
                'sites' => $bootstrapSites,
                'access_points' => $isResident ? [] : ($canViewDevices ? access_points_for_tenant($tenantId) : []),
                'devices' => $isResident ? [] : ($canViewDevices ? devices_for_tenant($tenantId) : []),
                'active_shift' => $isResident ? null : one_row(
                    'SELECT * FROM guard_shifts WHERE tenant_id = :tenant_id AND user_id = :user_id AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1',
                    ['tenant_id' => $tenantId, 'user_id' => $bootstrapUser['id']]
                ),
                'visits' => $bootstrapVisits,
                'vehicles' => $isResident ? [] : list_rows('SELECT * FROM vehicles WHERE tenant_id = :tenant_id ORDER BY entered_at DESC LIMIT 200', ['tenant_id' => $tenantId]),
                'booking_resources' => booking_resources_for_tenant($tenantId),
                'bookings' => $bootstrapBookings,
                'packages' => $isResident ? [] : packages_for_tenant($tenantId),
                'units' => $isResident && $residentProfile ? list_rows('SELECT u.*, s.name AS site_name FROM units u INNER JOIN sites s ON s.id = u.site_id WHERE u.tenant_id = :tenant_id AND u.id = :unit_id', ['tenant_id' => $tenantId, 'unit_id' => $residentProfile['unit_id']]) : units_for_tenant($tenantId),
                'residents' => $isResident && $residentProfile ? [$residentProfile] : residents_for_tenant($tenantId),
                'parking' => $isResident ? [] : list_rows('SELECT * FROM parking_assignments WHERE tenant_id = :tenant_id AND status = "active" ORDER BY space_code', ['tenant_id' => $tenantId]),
                'permissions' => $bootstrapPermissions,
                'invitations' => $bootstrapInvitations,
                'incidents' => $isResident ? [] : list_rows('SELECT * FROM incidents WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 200', ['tenant_id' => $tenantId]),
                'restrictions' => $isResident ? [] : list_rows('SELECT * FROM restrictions WHERE tenant_id = :tenant_id AND is_active = 1 AND (valid_from IS NULL OR valid_from <= NOW()) AND (valid_until IS NULL OR valid_until >= NOW()) ORDER BY created_at DESC', ['tenant_id' => $tenantId]),
                'audit' => $isResident ? [] : list_rows('SELECT * FROM audit_logs WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 80', ['tenant_id' => $tenantId]),
            ],
        ];
        json_response(scope_filter_bootstrap_payload($bootstrapPayload,$bootstrapUser));
    }

    if ($method === 'GET' && $path === '/sites') {
        json_response(['data' => scope_filter_rows('sites',list_rows('SELECT * FROM sites WHERE tenant_id = :tenant_id ORDER BY name', ['tenant_id' => tenant_id()]),require_auth())]);
    }

    if ($method === 'POST' && $path === '/sites') {
        require_permission('settings.manage');
        $data = request_json();
        require_fields($data, ['name']);
        start_plan_capacity_guard(tenant_id(),'sites');
        $stmt = db()->prepare(
            'INSERT INTO sites (tenant_id, name, address, timezone)
             VALUES (:tenant_id, :name, :address, :timezone)'
        );
        $stmt->execute([
            'tenant_id' => tenant_id(),
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'timezone' => $data['timezone'] ?? 'America/Bogota',
        ]);
        $createdSiteId=(int)db()->lastInsertId();
        finish_plan_capacity_guard();
        audit('sede.creada', 'Sede creada: ' . $data['name']);
        json_response(['id' => $createdSiteId], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/sites/(\d+)$#', $path, $matches)) {
        require_permission('settings.manage');
        $data = request_json();
        $stmt = db()->prepare(
            'UPDATE sites SET name = :name, address = :address, timezone = :timezone
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => tenant_id(),
            'name' => $data['name'] ?? '',
            'address' => $data['address'] ?? null,
            'timezone' => $data['timezone'] ?? 'America/Bogota',
        ]);
        audit('sede.actualizada', 'Sede #' . $matches[1] . ' actualizada');
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/access-points') {
        require_permission('devices.view');
        json_response(['data' => scope_filter_rows('access_points',access_points_for_tenant(tenant_id()),require_auth())]);
    }

    if ($method === 'POST' && $path === '/access-points') {
        $user = require_permission('devices.manage');
        $data = request_json();
        require_fields($data, ['site_id', 'code', 'name']);
        $config = require dirname(__DIR__) . '/config/config.php';
        $site = one_row(
            'SELECT id FROM sites WHERE id = :site_id AND tenant_id = :tenant_id LIMIT 1',
            ['site_id' => (int) $data['site_id'], 'tenant_id' => (int) $user['tenant_id']]
        );
        if (!$site) {
            json_response(['error' => 'Sede no encontrada'], 404);
        }
        require_tenant_scope('site',(int)$data['site_id']);
        start_plan_capacity_guard((int)$user['tenant_id'],'access_points');
        $stmt = db()->prepare(
            'INSERT INTO access_points (tenant_id, site_id, code, name, mode, capabilities_json, latitude, longitude, geofence_radius_m, allowed_networks_json, status)
             VALUES (:tenant_id, :site_id, :code, :name, :mode, :capabilities_json, :latitude, :longitude, :geofence_radius_m, :allowed_networks_json, "active")'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'site_id' => (int) $data['site_id'],
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'mode' => in_array(($data['mode'] ?? 'con_vigilancia'), ['con_vigilancia', 'sin_vigilancia'], true) ? $data['mode'] : 'con_vigilancia',
            'capabilities_json' => json_encode(is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [], JSON_UNESCAPED_UNICODE),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'geofence_radius_m' => !empty($data['geofence_radius_m']) ? max(25, (int) $data['geofence_radius_m']) : (int) ($config['geofence']['default_radius_m'] ?? 150),
            'allowed_networks_json' => json_encode(array_values(array_filter(array_map('trim', is_array($data['allowed_networks'] ?? null) ? $data['allowed_networks'] : []))), JSON_UNESCAPED_UNICODE),
        ]);
        $createdAccessPointId=(int)db()->lastInsertId();
        finish_plan_capacity_guard();
        audit('punto_acceso.creado', 'Punto de acceso ' . $data['code']);
        json_response(['id' => $createdAccessPointId], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/access-points/(\d+)$#', $path, $matches)) {
        $user = require_permission('devices.manage');
        $data = request_json();
        require_fields($data, ['code', 'name']);
        $config = require dirname(__DIR__) . '/config/config.php';
        $stmt = db()->prepare(
            'UPDATE access_points
             SET code = :code, name = :name, mode = :mode, capabilities_json = :capabilities_json,
                 latitude = :latitude, longitude = :longitude, geofence_radius_m = :geofence_radius_m,
                 allowed_networks_json = :allowed_networks_json, status = :status
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => $user['tenant_id'],
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'mode' => in_array(($data['mode'] ?? 'con_vigilancia'), ['con_vigilancia', 'sin_vigilancia'], true) ? $data['mode'] : 'con_vigilancia',
            'capabilities_json' => json_encode(is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [], JSON_UNESCAPED_UNICODE),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'geofence_radius_m' => !empty($data['geofence_radius_m']) ? max(25, (int) $data['geofence_radius_m']) : (int) ($config['geofence']['default_radius_m'] ?? 150),
            'allowed_networks_json' => json_encode(array_values(array_filter(array_map('trim', is_array($data['allowed_networks'] ?? null) ? $data['allowed_networks'] : []))), JSON_UNESCAPED_UNICODE),
            'status' => in_array(($data['status'] ?? 'active'), ['active', 'inactive'], true) ? $data['status'] : 'active',
        ]);
        audit('punto_acceso.actualizado', 'Punto de acceso #' . $matches[1] . ' actualizado');
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/devices') {
        require_permission('devices.view');
        json_response(['data' => scope_filter_rows('devices',devices_for_tenant(tenant_id()),require_auth())]);
    }

    if ($method === 'POST' && $path === '/devices') {
        $user = require_permission('devices.manage');
        $data = request_json();
        require_fields($data, ['site_id', 'name']);
        $site = one_row(
            'SELECT id FROM sites WHERE id = :site_id AND tenant_id = :tenant_id LIMIT 1',
            ['site_id' => (int) $data['site_id'], 'tenant_id' => (int) $user['tenant_id']]
        );
        if (!$site) {
            json_response(['error' => 'Sede no encontrada'], 404);
        }
        $accessPointId = !empty($data['access_point_id']) ? (int) $data['access_point_id'] : null;
        if ($accessPointId) {
            $accessPoint = one_row(
                'SELECT id FROM access_points WHERE id = :id AND tenant_id = :tenant_id AND site_id = :site_id LIMIT 1',
                ['id' => $accessPointId, 'tenant_id' => (int) $user['tenant_id'], 'site_id' => (int) $data['site_id']]
            );
            if (!$accessPoint) {
                json_response(['error' => 'Punto de acceso no encontrado'], 404);
            }
        }
        $token = bin2hex(random_bytes(32));
        $stmt = db()->prepare(
            'INSERT INTO devices (tenant_id, site_id, access_point_id, name, token_hash, token_last4, fingerprint_hash, registered_ip, user_agent, status, authorized_by, authorized_at)
             VALUES (:tenant_id, :site_id, :access_point_id, :name, :token_hash, :token_last4, :fingerprint_hash, :registered_ip, :user_agent, "active", :authorized_by, NOW())'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'site_id' => (int) $data['site_id'],
            'access_point_id' => $accessPointId,
            'name' => trim((string) $data['name']),
            'token_hash' => hash('sha256', $token),
            'token_last4' => substr($token, -4),
            'fingerprint_hash' => !empty($data['fingerprint']) ? hash('sha256', (string) $data['fingerprint']) : null,
            'registered_ip' => client_ip(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 4000) ?: null,
            'authorized_by' => $user['id'],
        ]);
        audit('dispositivo.autorizado', 'Dispositivo ' . $data['name']);
        json_response(['id' => db()->lastInsertId(), 'device_token' => $token], 201);
    }

    if ($method === 'POST' && preg_match('#^/devices/(\d+)/revoke$#', $path, $matches)) {
        require_permission('devices.revoke');
        $stmt = db()->prepare(
            'UPDATE devices SET status = "revoked", revoked_at = NOW() WHERE id = :id AND tenant_id = :tenant_id AND status <> "revoked"'
        );
        $stmt->execute(['id' => $matches[1], 'tenant_id' => tenant_id()]);
        audit('dispositivo.revocado', 'Dispositivo #' . $matches[1] . ' revocado');
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/users') {
        require_permission('settings.manage');
        json_response(['data' => list_rows(
            'SELECT id, tenant_id, site_id, name, email, role, is_active, created_at, updated_at
             FROM users
             WHERE tenant_id = :tenant_id
             ORDER BY name',
            ['tenant_id' => tenant_id()]
        )]);
    }

    if ($method === 'POST' && $path === '/users') {
        require_permission('settings.manage');
        $data = request_json();
        require_fields($data, ['name', 'email', 'password', 'role']);
        if (!in_array((string) $data['role'], ['admin', 'supervisor', 'coordinator', 'guard', 'auditor', 'resident', 'tech_operator'], true)) {
            json_response(['error' => 'Rol de usuario inválido'], 422);
        }
        if (!empty($data['site_id'])) assert_owned_site((int) $data['site_id'], tenant_id());
        validate_role_assignment(require_auth(), tenant_id(), (string)$data['role']);
        if (in_array((string)$data['role'],['guard','supervisor','coordinator','tech_operator'],true) && empty($data['site_id'])) {
            json_response(['error'=>'Se requiere sede para un usuario operativo'],422);
        }
        start_user_capacity_guard(tenant_id(),(string)$data['role'],!isset($data['is_active']) || (bool)$data['is_active']);
        $stmt = db()->prepare(
            'INSERT INTO users (tenant_id, site_id, name, email, password_hash, role, is_active)
             VALUES (:tenant_id, :site_id, :name, :email, :password_hash, :role, :is_active)'
        );
        $stmt->execute([
            'tenant_id' => tenant_id(),
            'site_id' => $data['site_id'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'],
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);
        $createdId=(int)db()->lastInsertId();
        $newScope=empty($data['site_id']) ? ['type'=>'tenant','id'=>tenant_id()] : ['type'=>'site','id'=>(int)$data['site_id']];
        db()->prepare('INSERT INTO user_scopes(tenant_id,user_id,scope_type,scope_id,granted_by) VALUES(:tenant,:user,:type,:scope_id,:actor)')
            ->execute(['tenant'=>tenant_id(),'user'=>$createdId,'type'=>$newScope['type'],'scope_id'=>$newScope['id'],'actor'=>require_auth()['id']]);
        finish_plan_capacity_guard();
        audit('usuario.creado', 'Usuario creado: ' . $data['email']);
        json_response(['id' => $createdId], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/users/(\d+)$#', $path, $matches)) {
        require_permission('settings.manage');
        $data = request_json();
        if (!in_array((string) ($data['role'] ?? 'guard'), ['admin', 'supervisor', 'coordinator', 'guard', 'auditor', 'resident', 'tech_operator'], true)) {
            json_response(['error' => 'Rol de usuario inválido'], 422);
        }
        if (!empty($data['site_id'])) assert_owned_site((int) $data['site_id'], tenant_id());
        if (is_platform_admin_target((int) $matches[1])) json_response(['error' => 'La cuenta global solo se modifica desde CREAMOX'], 403);
        $currentUser = one_row('SELECT name, role, site_id, is_active FROM users WHERE id = :id AND tenant_id = :tenant_id', ['id' => $matches[1], 'tenant_id' => tenant_id()]);
        if (!$currentUser) json_response(['error' => 'Usuario no encontrado'], 404);
        validate_role_assignment(require_auth(),tenant_id(),(string)($data['role']??$currentUser['role']));
        $fields = [
            'site_id' => $data['site_id'] ?? $currentUser['site_id'],
            'name' => $data['name'] ?? $currentUser['name'],
            'role' => $data['role'] ?? $currentUser['role'],
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : (int) $currentUser['is_active'],
            'id' => $matches[1],
            'tenant_id' => tenant_id(),
        ];
        $sql = 'UPDATE users SET site_id = :site_id, name = :name, role = :role, is_active = :is_active';
        if (!empty($data['password'])) {
            $sql .= ', password_hash = :password_hash';
            $fields['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = :id AND tenant_id = :tenant_id';
        $newRole=(string)$fields['role'];
        $newActive=(bool)$fields['is_active'];
        if (in_array($newRole,['guard','supervisor','coordinator','tech_operator'],true)
            && empty($fields['site_id'])) {
            json_response(['error'=>'Se requiere sede para un usuario operativo'],422);
        }
        start_user_capacity_guard(tenant_id(),$newRole,$newActive,
            (string)$currentUser['role'],(bool)$currentUser['is_active']);
        $stmt = db()->prepare($sql);
        $stmt->execute($fields);
        if ($newRole!==(string)$currentUser['role']
            && in_array($newRole,['guard','supervisor','coordinator','tech_operator'],true)) {
            // A former tenant-wide administrator must not retain their old tenant grant.
            db()->prepare('DELETE FROM user_scopes WHERE user_id=:user AND tenant_id=:tenant AND scope_type="tenant"')
                ->execute(['user'=>$matches[1],'tenant'=>tenant_id()]);
        }
        if ($newActive && in_array($newRole,['guard','supervisor','coordinator','tech_operator'],true)) {
            db()->prepare('INSERT IGNORE INTO user_scopes(tenant_id,user_id,scope_type,scope_id,granted_by)
                VALUES(:tenant,:user,"site",:site,:actor)')
                ->execute(['tenant'=>tenant_id(),'user'=>$matches[1],'site'=>$fields['site_id'],
                    'actor'=>require_auth()['id']]);
        }
        finish_plan_capacity_guard();
        audit('usuario.actualizado', 'Usuario #' . $matches[1] . ' actualizado');
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/restrictions') {
        require_tenant_wide_scope();
        json_response(['data' => list_rows('SELECT * FROM restrictions WHERE tenant_id = :tenant_id AND is_active = 1 AND (valid_from IS NULL OR valid_from <= NOW()) AND (valid_until IS NULL OR valid_until >= NOW()) ORDER BY created_at DESC', ['tenant_id' => tenant_id()])]);
    }

    if ($method === 'POST' && $path === '/restrictions') {
        require_permission('settings.manage');
        $data = request_json();
        require_fields($data, ['document_number', 'reason', 'level']);
        if (!in_array((string) $data['level'], ['informative', 'review', 'warning', 'block'], true)) {
            json_response(['error' => 'Nivel de restricción inválido'], 422);
        }
        $stmt = db()->prepare(
            'INSERT INTO restrictions (tenant_id, document_type, document_number, level, reason)
             VALUES (:tenant_id, :document_type, :document_number, :level, :reason)'
        );
        $stmt->execute([
            'tenant_id' => tenant_id(),
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'],
            'level' => $data['level'],
            'reason' => $data['reason'],
        ]);
        audit('restriccion.creada', 'Restriccion para documento ' . $data['document_number']);
        json_response(['id' => db()->lastInsertId()], 201);
    }

    if ($method === 'GET' && $path === '/vehicles') {
        json_response(['data' => scope_filter_rows('vehicles',list_rows('SELECT * FROM vehicles WHERE tenant_id = :tenant_id ORDER BY entered_at DESC LIMIT 200', ['tenant_id' => tenant_id()]),require_auth())]);
    }

    if ($method === 'GET' && $path === '/parking') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $params = ['tenant_id' => tenant_id()];
        $where = 'tenant_id = :tenant_id';
        if ($query !== '') {
            $where .= ' AND (space_code LIKE :query OR assigned_to LIKE :query OR plate LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        json_response(['data' => scope_filter_rows('parking',list_rows(
            'SELECT * FROM parking_assignments WHERE ' . $where . ' ORDER BY space_code LIMIT 200',
            $params
        ),require_auth())]);
    }

    if ($method === 'POST' && $path === '/parking') {
        require_permission('settings.manage');
        $data = request_json();
        require_fields($data, ['site_id', 'space_code', 'assigned_to']);
        $stmt = db()->prepare(
            'INSERT INTO parking_assignments (tenant_id, site_id, space_code, assigned_to, plate, status)
             VALUES (:tenant_id, :site_id, :space_code, :assigned_to, :plate, :status)'
        );
        $stmt->execute([
            'tenant_id' => tenant_id(),
            'site_id' => $data['site_id'],
            'space_code' => strtoupper((string) $data['space_code']),
            'assigned_to' => $data['assigned_to'],
            'plate' => !empty($data['plate']) ? strtoupper((string) $data['plate']) : null,
            'status' => $data['status'] ?? 'active',
        ]);
        audit('parqueadero.creado', 'Cupo ' . strtoupper((string) $data['space_code']));
        json_response(['id' => db()->lastInsertId()], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/parking/(\d+)$#', $path, $matches)) {
        require_permission('settings.manage');
        $data = request_json();
        $stmt = db()->prepare(
            'UPDATE parking_assignments
             SET site_id = :site_id, space_code = :space_code, assigned_to = :assigned_to, plate = :plate, status = :status
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => tenant_id(),
            'site_id' => $data['site_id'],
            'space_code' => strtoupper((string) $data['space_code']),
            'assigned_to' => $data['assigned_to'],
            'plate' => !empty($data['plate']) ? strtoupper((string) $data['plate']) : null,
            'status' => $data['status'] ?? 'active',
        ]);
        audit('parqueadero.actualizado', 'Cupo #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/booking-resources') {
        json_response(['data' => scope_filter_rows('booking_resources',booking_resources_for_tenant(tenant_id()),require_auth())]);
    }

    if ($method === 'POST' && $path === '/booking-resources') {
        require_permission('bookings.manage');
        $data = request_json();
        require_fields($data, ['name', 'resource_type']);
        $stmt = db()->prepare(
            'INSERT INTO booking_resources (tenant_id, site_id, name, resource_type, pricing_mode, capacity, price_per_hour, price_per_day, deposit_amount, min_duration_mins, max_duration_days, color, rules, is_active)
             VALUES (:tenant_id, :site_id, :name, :resource_type, :pricing_mode, :capacity, :price_per_hour, :price_per_day, :deposit_amount, :min_duration_mins, :max_duration_days, :color, :rules, 1)'
        );
        $stmt->execute([
            'tenant_id' => tenant_id(),
            'site_id' => $data['site_id'] ?? null,
            'name' => $data['name'],
            'resource_type' => $data['resource_type'],
            'pricing_mode' => in_array(($data['pricing_mode'] ?? 'mixto'), ['hora', 'dia', 'mixto'], true) ? $data['pricing_mode'] : 'mixto',
            'capacity' => (int) ($data['capacity'] ?? 0),
            'price_per_hour' => (float) ($data['price_per_hour'] ?? 0),
            'price_per_day' => (float) ($data['price_per_day'] ?? 0),
            'deposit_amount' => (float) ($data['deposit_amount'] ?? 0),
            'min_duration_mins' => !empty($data['min_duration_mins']) ? (int) $data['min_duration_mins'] : null,
            'max_duration_days' => !empty($data['max_duration_days']) ? (int) $data['max_duration_days'] : null,
            'color' => $data['color'] ?? '#2563eb',
            'rules' => trim((string) ($data['rules'] ?? '')) ?: null,
        ]);
        audit('reserva.recurso_creado', 'Recurso reservable ' . $data['name']);
        json_response(['id' => db()->lastInsertId()], 201);
    }

    if ($method === 'GET' && $path === '/bookings') {
        require_permission('bookings.view');
        json_response(['data' => scope_filter_rows('bookings',bookings_for_tenant(tenant_id(), [
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'resource_id' => $_GET['resource_id'] ?? null,
        ]),require_auth())]);
    }

    if ($method === 'POST' && $path === '/bookings') {
        $user = require_permission('bookings.manage');
        $data = request_json();
        require_fields($data, ['resource_id', 'requester_name', 'purpose', 'starts_at', 'ends_at']);
        $resourceId = (int) $data['resource_id'];
        $startsAt = (string) $data['starts_at'];
        $endsAt = (string) $data['ends_at'];
        ensure_booking_window_available((int) $user['tenant_id'], $resourceId, $startsAt, $endsAt);
        $stmt = db()->prepare(
            'INSERT INTO bookings (tenant_id, resource_id, created_by, requester_name, requester_phone, purpose, starts_at, ends_at, status, amount, deposit_amount, terms_accepted_at, source, notes)
             VALUES (:tenant_id, :resource_id, :created_by, :requester_name, :requester_phone, :purpose, :starts_at, :ends_at, :status, :amount, :deposit_amount, :terms_accepted_at, :source, :notes)'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'resource_id' => $resourceId,
            'created_by' => $user['id'],
            'requester_name' => $data['requester_name'],
            'requester_phone' => $data['requester_phone'] ?? null,
            'purpose' => $data['purpose'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => in_array(($data['status'] ?? 'pending'), ['pre_reservation', 'pending', 'pending_payment', 'confirmed', 'paid'], true) ? $data['status'] : 'pending',
            'amount' => (float) ($data['amount'] ?? 0),
            'deposit_amount' => (float) ($data['deposit_amount'] ?? 0),
            'terms_accepted_at' => !empty($data['terms_accepted']) ? date('Y-m-d H:i:s') : null,
            'source' => in_array(($data['source'] ?? 'admin'), ['admin', 'resident', 'system'], true) ? $data['source'] : 'admin',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ]);
        $bookingId = db()->lastInsertId();
        audit('reserva.creada', 'Reserva #' . $bookingId . ' creada');
        json_response(['id' => $bookingId], 201);
    }

    if ($method === 'POST' && preg_match('#^/bookings/(\d+)/status$#', $path, $matches)) {
        require_permission('bookings.manage');
        $data = request_json();
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['pre_reservation', 'pending', 'pending_payment', 'confirmed', 'paid', 'cancelled', 'expired'], true)) {
            json_response(['error' => 'Estado de reserva invalido'], 422);
        }
        $stmt = db()->prepare(
            'UPDATE bookings
             SET status = :status,
                 paid_at = CASE WHEN :status_paid = "paid" THEN NOW() ELSE paid_at END,
                 cancelled_at = CASE WHEN :status_cancelled = "cancelled" THEN NOW() ELSE cancelled_at END,
                 cancellation_reason = CASE WHEN :status_cancelled_reason = "cancelled" THEN :cancellation_reason ELSE cancellation_reason END
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => tenant_id(),
            'status' => $status,
            'status_paid' => $status,
            'status_cancelled' => $status,
            'status_cancelled_reason' => $status,
            'cancellation_reason' => trim((string) ($data['cancellation_reason'] ?? '')) ?: null,
        ]);
        audit('reserva.estado', 'Reserva #' . $matches[1] . ' actualizada a ' . humanize_code_label($status));
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && preg_match('#^/bookings/(\d+)/guests$#', $path, $matches)) {
        require_permission('bookings.view');
        json_response(['data' => list_rows('SELECT * FROM reservation_guests WHERE booking_id = :booking_id AND tenant_id = :tenant_id ORDER BY created_at', ['booking_id' => $matches[1], 'tenant_id' => tenant_id()])]);
    }

    if ($method === 'POST' && preg_match('#^/bookings/(\d+)/guests$#', $path, $matches)) {
        $user = require_permission('reservation_guests.manage');
        $data = request_json();
        require_fields($data, ['guest_name']);
        $booking = one_row('SELECT id, starts_at, ends_at FROM bookings WHERE id = :id AND tenant_id = :tenant_id AND status NOT IN ("cancelled", "expired") LIMIT 1', ['id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        if (!$booking) json_response(['error' => 'Reserva no encontrada o no disponible'], 404);
        $token = bin2hex(random_bytes(24));
        $stmt = db()->prepare('INSERT INTO reservation_guests (tenant_id, booking_id, guest_name, guest_document, qr_token_hash, valid_from, valid_until, status) VALUES (:tenant_id, :booking_id, :guest_name, :guest_document, :qr_token_hash, :valid_from, :valid_until, "active")');
        $stmt->execute(['tenant_id' => $user['tenant_id'], 'booking_id' => $matches[1], 'guest_name' => trim((string) $data['guest_name']), 'guest_document' => trim((string) ($data['guest_document'] ?? '')) ?: null, 'qr_token_hash' => hash('sha256', $token), 'valid_from' => $data['valid_from'] ?? $booking['starts_at'], 'valid_until' => $data['valid_until'] ?? $booking['ends_at']]);
        $guestId = db()->lastInsertId();
        audit('reserva.invitado_creado', 'Invitado agregado a reserva #' . $matches[1]);
        json_response(['id' => $guestId, 'qr_token' => $token], 201);
    }

    if ($method === 'POST' && preg_match('#^/reservation-guests/(\d+)/(check-in|check-out)$#', $path, $matches)) {
        $user = require_permission('reservation_guests.manage');
        $field = $matches[2] === 'check-in' ? 'checked_in_at' : 'checked_out_at';
        $status = $matches[2] === 'check-in' ? 'used' : 'active';
        db()->prepare('UPDATE reservation_guests SET ' . $field . ' = NOW(), status = :status WHERE id = :id AND tenant_id = :tenant_id AND status <> "cancelled"')->execute(['status' => $status, 'id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        audit('reserva.invitado_' . ($matches[2] === 'check-in' ? 'ingreso' : 'salida'), 'Control de invitado de reserva #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'DELETE' && preg_match('#^/bookings/(\d+)$#', $path, $matches)) {
        require_permission('bookings.manage');
        $stmt = db()->prepare('DELETE FROM bookings WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute(['id' => $matches[1], 'tenant_id' => tenant_id()]);
        audit('reserva.eliminada', 'Reserva #' . $matches[1] . ' eliminada');
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/vehicles') {
        $user = require_permission('vehicles.manage');
        $data = request_json();
        require_fields($data, ['plate', 'driver_name', 'vehicle_type', 'destination']);
        $data['shift_id'] = active_shift_id_for($user, $data['shift_id'] ?? null);
        $shiftSiteId=$data['shift_id']?validated_shift_site((int)$data['shift_id'],$user,!empty($data['site_id'])?(int)$data['site_id']:null):null;
        $parkingSpace = trim((string) ($data['parking_space'] ?? '')) ?: null;
        if ($parkingSpace) {
            $space = one_row('SELECT id FROM parking_assignments WHERE tenant_id = :tenant_id AND space_code = :space_code AND status = "active" LIMIT 1', ['tenant_id' => tenant_id(), 'space_code' => $parkingSpace]);
            if (!$space) json_response(['error' => 'El parqueadero seleccionado no está disponible'], 422);
            $occupied = one_row('SELECT id FROM vehicles WHERE tenant_id = :tenant_id AND parking_space = :parking_space AND status = "inside" LIMIT 1', ['tenant_id' => tenant_id(), 'parking_space' => $parkingSpace]);
            if ($occupied) json_response(['error' => 'El parqueadero seleccionado ya está ocupado'], 409);
        }
        $stmt = db()->prepare(
            'INSERT INTO vehicles (tenant_id, shift_id, person_id, site_id, plate, driver_name, vehicle_type, destination, parking_space, identification_method, entered_at, status)
             VALUES (:tenant_id, :shift_id, :person_id, :site_id, :plate, :driver_name, :vehicle_type, :destination, :parking_space, :identification_method, NOW(), "inside")'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'shift_id' => $data['shift_id'],
            'person_id' => !empty($data['person_id']) ? (int) $data['person_id'] : null,
            'site_id' => $shiftSiteId,
            'plate' => strtoupper((string) $data['plate']),
            'driver_name' => $data['driver_name'],
            'vehicle_type' => $data['vehicle_type'],
            'destination' => $data['destination'],
            'parking_space' => $parkingSpace,
            'identification_method' => in_array(($data['identification_method'] ?? 'manual'), ['huella', 'cedula', 'manual', 'qr', 'otro'], true) ? $data['identification_method'] : 'manual',
        ]);
        audit('vehiculo.ingreso', 'Ingreso vehiculo ' . strtoupper((string) $data['plate']));
        json_response(['id' => db()->lastInsertId()], 201);
    }

    if ($method === 'POST' && preg_match('#^/vehicles/(\d+)/exit$#', $path, $matches)) {
        require_permission('vehicles.manage');
        require_scoped_record('vehicles',(int)$matches[1]);
        $data = request_json();
        $stmt = db()->prepare(
            'UPDATE vehicles
             SET status = "exited", exited_at = NOW(), exit_observations = :exit_observations, exit_type = "normal", exit_by = :exit_by
             WHERE id = :id AND tenant_id = :tenant_id AND status = "inside"'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => tenant_id(),
            'exit_observations' => trim((string) ($data['observations'] ?? '')) ?: null,
            'exit_by' => $_SESSION['user']['id'] ?? null,
        ]);
        audit('vehiculo.salida', 'Salida vehiculo #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/permissions') {
        require_permission('settings.manage');
        json_response(['data' => permissions_matrix(tenant_id())]);
    }

    if ($method === 'PUT' && preg_match('#^/permissions/(superadmin|admin|supervisor|coordinator|guard|auditor|resident|tech_operator)$#', $path, $matches)) {
        require_platform_admin();
        $data = request_json();
        $permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
        replace_role_permissions(tenant_id(), $matches[1], $permissions);
        audit('permisos.actualizados', 'Permisos actualizados para rol ' . $matches[1]);
        json_response(['ok' => true]);
    }

    /* Operación avanzada: empleados, contratistas y personal doméstico. */
    if ($method === 'GET' && $path === '/employees') {
        require_permission('attendance.view');
        $rows = list_rows(
            'SELECT p.id AS person_id, p.document_type, p.document_number, p.full_name, p.phone, p.email, p.company,
                    ep.id AS employee_profile_id, ep.employee_code, ep.schedule_start, ep.schedule_end,
                    ep.tolerance_mins, ep.incomplete_after_hours, ep.status AS employee_status,
                    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.employee_profile_id = ep.id AND DATE(ar.occurred_at) = CURRENT_DATE) AS today_events
             FROM people p INNER JOIN employee_profiles ep ON ep.person_id = p.id
             WHERE p.tenant_id = :tenant_id AND p.record_status = "active"
             ORDER BY p.full_name LIMIT 500',
            ['tenant_id' => tenant_id()]
        );
        json_response(['data' => $rows]);
    }

    if ($method === 'POST' && $path === '/employees') {
        $user = require_permission('employees.manage');
        $data = request_json();
        require_fields($data, ['document_type', 'document_number', 'full_name']);
        $pdo = db();
        $pdo->beginTransaction();
        $personId = upsert_person($pdo, (int) $user['tenant_id'], [
            'person_type' => 'empleado',
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'],
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'company' => $data['company'] ?? null,
        ]);
        $stmt = $pdo->prepare(
            'INSERT INTO employee_profiles (tenant_id, person_id, employee_code, schedule_start, schedule_end, tolerance_mins, incomplete_after_hours, status)
             VALUES (:tenant_id, :person_id, :employee_code, :schedule_start, :schedule_end, :tolerance_mins, :incomplete_after_hours, :status)
             ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), schedule_start = VALUES(schedule_start), schedule_end = VALUES(schedule_end), tolerance_mins = VALUES(tolerance_mins), incomplete_after_hours = VALUES(incomplete_after_hours), status = VALUES(status), deleted_at = NULL'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'], 'person_id' => $personId,
            'employee_code' => trim((string) ($data['employee_code'] ?? '')) ?: null,
            'schedule_start' => $data['schedule_start'] ?? null, 'schedule_end' => $data['schedule_end'] ?? null,
            'tolerance_mins' => max(0, (int) ($data['tolerance_mins'] ?? 10)),
            'incomplete_after_hours' => max(1, (float) ($data['incomplete_after_hours'] ?? 14)),
            'status' => ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ]);
        $profile = one_row('SELECT id FROM employee_profiles WHERE tenant_id = :tenant_id AND person_id = :person_id LIMIT 1', ['tenant_id' => $user['tenant_id'], 'person_id' => $personId]);
        $pdo->commit();
        audit('empleado.creado', 'Ficha de empleado ' . $data['full_name']);
        json_response(['id' => $personId, 'employee_profile_id' => (int) ($profile['id'] ?? 0)], 201);
    }

    if ($method === 'PATCH' && preg_match('#^/employees/(\d+)$#', $path, $matches)) {
        $user = require_permission('employees.manage');
        $data = request_json();
        $person = one_row('SELECT p.*, ep.id AS employee_profile_id FROM people p INNER JOIN employee_profiles ep ON ep.person_id = p.id WHERE p.id = :id AND p.tenant_id = :tenant_id LIMIT 1', ['id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        if (!$person) json_response(['error' => 'Empleado no encontrado'], 404);
        $pdo = db();
        $pdo->beginTransaction();
        foreach (['full_name', 'phone', 'email', 'company', 'document_number'] as $field) {
            if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $person[$field]) {
                insert_correction($pdo, (int) $user['tenant_id'], 'person', (int) $matches[1], $field, $person[$field], $data[$field], (string) ($data['reason'] ?? 'Actualización de ficha de empleado'), (int) $user['id']);
            }
        }
        $pdo->prepare('UPDATE people SET full_name = :full_name, phone = :phone, email = :email, company = :company, document_number = :document_number WHERE id = :id AND tenant_id = :tenant_id')->execute([
            'full_name' => $data['full_name'] ?? $person['full_name'], 'phone' => $data['phone'] ?? $person['phone'], 'email' => $data['email'] ?? $person['email'], 'company' => $data['company'] ?? $person['company'], 'document_number' => $data['document_number'] ?? $person['document_number'], 'id' => $matches[1], 'tenant_id' => $user['tenant_id'],
        ]);
        $pdo->prepare('UPDATE employee_profiles SET employee_code = :employee_code, schedule_start = :schedule_start, schedule_end = :schedule_end, tolerance_mins = :tolerance_mins, incomplete_after_hours = :incomplete_after_hours, status = :status WHERE id = :id AND tenant_id = :tenant_id')->execute([
            'employee_code' => array_key_exists('employee_code', $data) ? (trim((string) $data['employee_code']) ?: null) : $person['employee_code'],
            'schedule_start' => $data['schedule_start'] ?? $person['schedule_start'], 'schedule_end' => $data['schedule_end'] ?? $person['schedule_end'],
            'tolerance_mins' => max(0, (int) ($data['tolerance_mins'] ?? $person['tolerance_mins'] ?? 10)), 'incomplete_after_hours' => max(1, (float) ($data['incomplete_after_hours'] ?? $person['incomplete_after_hours'] ?? 14)), 'status' => ($data['status'] ?? $person['employee_status']) === 'inactive' ? 'inactive' : 'active', 'id' => $person['employee_profile_id'], 'tenant_id' => $user['tenant_id'],
        ]);
        $pdo->commit();
        audit('empleado.actualizado', 'Ficha de empleado #' . $matches[1] . ' actualizada');
        json_response(['ok' => true]);
    }

    if ($method === 'PUT' && preg_match('#^/employees/(\d+)/schedule$#', $path, $matches)) {
        $user = require_permission('employees.manage');
        $data = request_json();
        require_fields($data, ['day_of_week', 'start_time', 'end_time']);
        $profile = one_row('SELECT id FROM employee_profiles WHERE person_id = :person_id AND tenant_id = :tenant_id LIMIT 1', ['person_id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        if (!$profile) json_response(['error' => 'Empleado no encontrado'], 404);
        $day = max(1, min(7, (int) $data['day_of_week']));
        db()->prepare('INSERT INTO employee_schedules (tenant_id, employee_profile_id, day_of_week, start_time, end_time, tolerance_mins, is_active) VALUES (:tenant_id, :profile_id, :day, :start_time, :end_time, :tolerance, :active) ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), tolerance_mins = VALUES(tolerance), is_active = VALUES(is_active)')->execute([
            'tenant_id' => $user['tenant_id'], 'profile_id' => $profile['id'], 'day' => $day, 'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'tolerance' => max(0, (int) ($data['tolerance_mins'] ?? 10)), 'active' => array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
        ]);
        audit('empleado.horario', 'Horario actualizado para empleado #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/attendance') {
        require_permission('attendance.view');
        $where = ['ar.tenant_id = :tenant_id'];
        $params = ['tenant_id' => tenant_id()];
        if (!empty($_GET['from'])) { $where[] = 'ar.occurred_at >= :from_date'; $params['from_date'] = $_GET['from'] . ' 00:00:00'; }
        if (!empty($_GET['to'])) { $where[] = 'ar.occurred_at <= :to_date'; $params['to_date'] = $_GET['to'] . ' 23:59:59'; }
        if (!empty($_GET['employee_id'])) { $where[] = 'ar.person_id = :employee_id'; $params['employee_id'] = (int) $_GET['employee_id']; }
        json_response(['data' => list_rows('SELECT ar.*, p.full_name, p.document_number, ep.employee_code, s.name AS site_name FROM attendance_records ar INNER JOIN people p ON p.id = ar.person_id INNER JOIN employee_profiles ep ON ep.id = ar.employee_profile_id LEFT JOIN sites s ON s.id = ar.site_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ar.occurred_at DESC LIMIT 500', $params)]);
    }

    if ($method === 'POST' && $path === '/kiosk/attendance') {
        // A kiosk uses the same authenticated operator/device session as the
        // access desk, but identifies an employee by code, document or email.
        $user = require_permission('access_events.register');
        $data = request_json();
        require_fields($data, ['employee_identifier', 'event_type', 'occurred_at']);
        $data['shift_id'] = active_shift_id_for($user, $data['shift_id'] ?? null);
        $eventType = (string) $data['event_type'];
        if (!in_array($eventType, ['entrada', 'salida'], true)) json_response(['error' => 'Tipo de asistencia inválido'], 422);
        $identifier = trim((string) $data['employee_identifier']);
        $profile = one_row(
            'SELECT ep.*, p.id AS person_id, p.full_name, p.document_number, p.email, p.record_status
             FROM employee_profiles ep INNER JOIN people p ON p.id = ep.person_id
             WHERE ep.tenant_id = :tenant_id AND (ep.employee_code = :code OR p.document_number = :document OR LOWER(p.email) = LOWER(:email))
             ORDER BY ep.id DESC LIMIT 1',
            ['tenant_id' => $user['tenant_id'], 'code' => $identifier, 'document' => $identifier, 'email' => $identifier]
        );
        if (!$profile) json_response(['error' => 'No encontramos un empleado activo con ese identificador'], 404);
        if ((string) ($profile['status'] ?? 'active') !== 'active' || (string) ($profile['record_status'] ?? 'active') !== 'active') json_response(['error' => 'El empleado está inactivo y no puede registrar asistencia'], 422);
        $occurredAt = (string) $data['occurred_at'];
        if (strtotime($occurredAt) === false) json_response(['error' => 'Fecha de asistencia inválida'], 422);
        $status = 'puntual';
        if ($eventType === 'entrada' && !empty($profile['schedule_start'])) {
            $scheduled = strtotime(date('Y-m-d', strtotime($occurredAt)) . ' ' . $profile['schedule_start']);
            if ($scheduled && strtotime($occurredAt) > $scheduled + ((int) $profile['tolerance_mins'] * 60)) $status = 'tarde';
        }
        $idempotency = trim((string) ($data['idempotency_key'] ?? '')) ?: hash('sha256', implode('|', [$user['tenant_id'], $profile['id'], $eventType, $occurredAt]));
        $existing = one_row('SELECT id, status FROM attendance_records WHERE tenant_id = :tenant_id AND idempotency_key = :idempotency LIMIT 1', ['tenant_id' => $user['tenant_id'], 'idempotency' => substr($idempotency, 0, 64)]);
        if ($existing) json_response(['id' => $existing['id'], 'status' => $existing['status'], 'duplicate' => true]);
        db()->prepare('INSERT INTO attendance_records (tenant_id, employee_profile_id, person_id, site_id, shift_id, event_type, identification_method, occurred_at, source, status, notes, idempotency_key) VALUES (:tenant_id, :profile_id, :person_id, :site_id, :shift_id, :event_type, :method, :occurred_at, :source, :status, :notes, :idempotency)')->execute([
            'tenant_id' => $user['tenant_id'], 'profile_id' => $profile['id'], 'person_id' => $profile['person_id'],
            'site_id' => !empty($data['site_id']) ? (int) $data['site_id'] : ($user['site_id'] ?? null),
            'shift_id' => !empty($data['shift_id']) && is_numeric($data['shift_id']) ? (int) $data['shift_id'] : null,
            'event_type' => $eventType, 'method' => in_array(($data['identification_method'] ?? 'manual'), ['huella', 'cedula', 'manual', 'qr', 'otro'], true) ? $data['identification_method'] : 'manual',
            'occurred_at' => $occurredAt, 'source' => ($data['source'] ?? 'online') === 'offline' ? 'offline' : 'online', 'status' => $status,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'idempotency' => substr($idempotency, 0, 64),
        ]);
        audit('asistencia.registrada', 'Marcación de kiosco: ' . $profile['full_name'] . ' (' . $eventType . ')');
        json_response(['id' => db()->lastInsertId(), 'status' => $status], 201);
    }

    if ($method === 'POST' && $path === '/attendance') {
        $user = require_permission('employees.manage');
        $data = request_json();
        require_fields($data, ['employee_profile_id', 'event_type', 'occurred_at']);
        $data['shift_id'] = active_shift_id_for($user, $data['shift_id'] ?? null);
        if (!in_array((string) $data['event_type'], ['entrada', 'salida'], true)) json_response(['error' => 'Tipo de asistencia invalido'], 422);
        $profile = one_row('SELECT ep.*, p.id AS person_id, p.full_name FROM employee_profiles ep INNER JOIN people p ON p.id = ep.person_id WHERE ep.id = :id AND ep.tenant_id = :tenant_id LIMIT 1', ['id' => $data['employee_profile_id'], 'tenant_id' => $user['tenant_id']]);
        if (!$profile) json_response(['error' => 'Empleado no encontrado'], 404);
        if ((string) ($profile['status'] ?? 'active') !== 'active' || (string) ($profile['record_status'] ?? 'active') === 'deleted') {
            json_response(['error' => 'El empleado está inactivo y no puede registrar asistencia'], 422);
        }
        $occurredAt = (string) $data['occurred_at'];
        $status = 'puntual';
        if ((string) $data['event_type'] === 'entrada' && !empty($profile['schedule_start'])) {
            $scheduled = strtotime(date('Y-m-d', strtotime($occurredAt)) . ' ' . $profile['schedule_start']);
            if ($scheduled && strtotime($occurredAt) > $scheduled + ((int) $profile['tolerance_mins'] * 60)) $status = 'tarde';
        }
        $stmt = db()->prepare('INSERT INTO attendance_records (tenant_id, employee_profile_id, person_id, site_id, shift_id, event_type, identification_method, occurred_at, source, status, notes, idempotency_key) VALUES (:tenant_id, :profile_id, :person_id, :site_id, :shift_id, :event_type, :method, :occurred_at, :source, :status, :notes, :idempotency)');
        $stmt->execute(['tenant_id' => $user['tenant_id'], 'profile_id' => $profile['id'], 'person_id' => $profile['person_id'], 'site_id' => $data['site_id'] ?? $user['site_id'] ?? null, 'shift_id' => $data['shift_id'] ?? null, 'event_type' => $data['event_type'], 'method' => in_array(($data['identification_method'] ?? 'manual'), ['huella', 'cedula', 'manual', 'qr', 'otro'], true) ? $data['identification_method'] : 'manual', 'occurred_at' => $occurredAt, 'source' => in_array(($data['source'] ?? 'online'), ['online', 'offline', 'import'], true) ? $data['source'] : 'online', 'status' => $status, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'idempotency' => $data['idempotency_key'] ?? null]);
        audit('asistencia.registrada', 'Asistencia de ' . $profile['full_name'] . ': ' . $data['event_type']);
        json_response(['id' => db()->lastInsertId(), 'status' => $status], 201);
    }

    if ($method === 'GET' && $path === '/contractors') {
        require_permission('contractors.manage');
        json_response(['data' => list_rows('SELECT p.*, (SELECT COUNT(*) FROM person_qualifications q WHERE q.person_id = p.id AND q.tenant_id = p.tenant_id AND q.status = "active") AS qualification_count FROM people p WHERE p.tenant_id = :tenant_id AND p.person_type = "contratista" AND p.record_status = "active" ORDER BY p.full_name LIMIT 500', ['tenant_id' => tenant_id()])]);
    }

    if ($method === 'POST' && $path === '/contractors') {
        $user = require_permission('contractors.manage');
        $data = request_json();
        require_fields($data, ['document_type', 'document_number', 'full_name', 'company']);
        $pdo = db();
        $pdo->beginTransaction();
        $personId = upsert_person($pdo, (int) $user['tenant_id'], ['person_type' => 'contratista', 'document_type' => $data['document_type'], 'document_number' => $data['document_number'], 'full_name' => $data['full_name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'company' => $data['company']]);
        foreach (is_array($data['qualifications'] ?? null) ? $data['qualifications'] : [] as $qualification) {
            if (!is_array($qualification) || trim((string) ($qualification['type'] ?? '')) === '') continue;
            $pdo->prepare('INSERT INTO person_qualifications (tenant_id, person_id, qualification_type, reference, valid_from, valid_until, metadata_json, status) VALUES (:tenant_id, :person_id, :type, :reference, :valid_from, :valid_until, :metadata, :status)')->execute(['tenant_id' => $user['tenant_id'], 'person_id' => $personId, 'type' => $qualification['type'], 'reference' => $qualification['reference'] ?? null, 'valid_from' => $qualification['valid_from'] ?? null, 'valid_until' => $qualification['valid_until'] ?? null, 'metadata' => json_encode($qualification['metadata'] ?? [], JSON_UNESCAPED_UNICODE), 'status' => ($qualification['status'] ?? 'active')]);
        }
        if (!in_array((string) $user['role'], ['admin', 'superadmin'], true)) {
            $pdo->prepare('INSERT INTO approvals (tenant_id, requested_by, subject_type, subject_id, notes, requested_at) VALUES (:tenant_id, :requested_by, "contractor_access", :subject_id, :notes, NOW())')->execute(['tenant_id' => $user['tenant_id'], 'requested_by' => $user['id'], 'subject_id' => $personId, 'notes' => 'Validar documentos, ARL y autorización temporal de ' . $data['full_name']]);
        }
        $pdo->commit();
        audit('contratista.creado', 'Contratista ' . $data['full_name']);
        json_response(['id' => $personId], 201);
    }

    if ($method === 'GET' && $path === '/domestic-staff') {
        require_permission('employees.manage');
        json_response(['data' => list_rows('SELECT p.* FROM people p WHERE p.tenant_id = :tenant_id AND p.person_type = "domestico" AND p.record_status = "active" ORDER BY p.full_name LIMIT 500', ['tenant_id' => tenant_id()])]);
    }

    if ($method === 'POST' && $path === '/domestic-staff') {
        $user = require_permission('employees.manage');
        $data = request_json();
        require_fields($data, ['document_type', 'document_number', 'full_name', 'destination']);
        $personId = upsert_person(db(), (int) $user['tenant_id'], ['person_type' => 'domestico', 'document_type' => $data['document_type'], 'document_number' => $data['document_number'], 'full_name' => $data['full_name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'company' => $data['destination']]);
        audit('domestico.creado', 'Personal doméstico ' . $data['full_name']);
        json_response(['id' => $personId], 201);
    }

    /* Correcciones y salidas administrativas preservan el registro anterior. */
    if ($method === 'GET' && $path === '/corrections') {
        require_permission('corrections.manage');
        json_response(['data' => list_rows('SELECT rc.*, u.name AS corrected_by_name FROM record_corrections rc LEFT JOIN users u ON u.id = rc.corrected_by WHERE rc.tenant_id = :tenant_id ORDER BY rc.created_at DESC LIMIT 500', ['tenant_id' => tenant_id()])]);
    }

    if ($method === 'POST' && $path === '/corrections') {
        $user = require_permission('corrections.manage');
        $data = request_json();
        require_fields($data, ['entity_type', 'entity_id', 'field_name', 'new_value', 'reason']);
        $allowed = ['visit' => ['destination', 'host', 'reason', 'vehicle_plate'], 'vehicle' => ['driver_name', 'vehicle_type', 'destination', 'plate'], 'incident' => ['title', 'area', 'description', 'severity'], 'booking' => ['requester_name', 'purpose', 'starts_at', 'ends_at', 'notes']];
        $entity = (string) $data['entity_type'];
        $table = ['visit' => 'visits', 'vehicle' => 'vehicles', 'incident' => 'incidents', 'booking' => 'bookings'][$entity] ?? null;
        $field = (string) $data['field_name'];
        if (!$table || !in_array($field, $allowed[$entity] ?? [], true)) json_response(['error' => 'Campo no habilitado para corrección'], 422);
        $row = one_row('SELECT * FROM ' . $table . ' WHERE id = :id AND tenant_id = :tenant_id LIMIT 1', ['id' => (int) $data['entity_id'], 'tenant_id' => $user['tenant_id']]);
        if (!$row) json_response(['error' => 'Registro no encontrado'], 404);
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE ' . $table . ' SET ' . $field . ' = :new_value WHERE id = :id AND tenant_id = :tenant_id')->execute(['new_value' => $data['new_value'], 'id' => (int) $data['entity_id'], 'tenant_id' => $user['tenant_id']]);
        insert_correction($pdo, (int) $user['tenant_id'], $entity, (int) $data['entity_id'], $field, $row[$field] ?? null, $data['new_value'], (string) $data['reason'], (int) $user['id']);
        $pdo->commit();
        audit('correccion.aplicada', 'Corrección de ' . $entity . ' #' . $data['entity_id'] . ' en ' . $field);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/visits/(\d+)/admin-exit$#', $path, $matches)) {
        $user = require_role(['admin', 'superadmin']);
        $data = request_json();
        require_fields($data, ['reason']);
        db()->prepare('UPDATE visits SET status = "exited", exited_at = NOW(), exit_observations = :observations, exit_type = "administrativa", exit_by = :exit_by, exit_reason = :reason WHERE id = :id AND tenant_id = :tenant_id AND status = "inside"')->execute(['observations' => trim((string) ($data['observations'] ?? '')) ?: null, 'exit_by' => $user['id'], 'reason' => trim((string) $data['reason']), 'id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        audit('visita.salida_administrativa', 'Salida administrativa de visita #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/visits/admin-exit-employees') {
        $user = require_role(['admin', 'superadmin']);
        $data = request_json();
        require_fields($data, ['reason']);
        $reason = trim((string) $data['reason']);
        $pdo = db();
        $stmt = $pdo->prepare(
            'UPDATE visits v INNER JOIN people p ON p.id = v.person_id
             SET v.status = "exited", v.exited_at = NOW(), v.exit_observations = :observations,
                 v.exit_type = "administrativa", v.exit_by = :exit_by, v.exit_reason = :reason
             WHERE v.tenant_id = :tenant_id AND v.status = "inside" AND p.person_type = "empleado"'
        );
        $stmt->execute([
            'observations' => trim((string) ($data['observations'] ?? '')) ?: null,
            'exit_by' => $user['id'], 'reason' => $reason, 'tenant_id' => $user['tenant_id'],
        ]);
        $count = $stmt->rowCount();
        audit('empleados.salida_administrativa', 'Salida administrativa de ' . $count . ' empleados');
        json_response(['ok' => true, 'count' => $count]);
    }

    if ($method === 'POST' && preg_match('#^/vehicles/(\d+)/admin-exit$#', $path, $matches)) {
        $user = require_role(['admin', 'superadmin']);
        $data = request_json();
        require_fields($data, ['reason']);
        db()->prepare('UPDATE vehicles SET status = "exited", exited_at = NOW(), exit_observations = :observations, exit_type = "administrativa", exit_by = :exit_by, exit_reason = :reason WHERE id = :id AND tenant_id = :tenant_id AND status = "inside"')->execute(['observations' => trim((string) ($data['observations'] ?? '')) ?: null, 'exit_by' => $user['id'], 'reason' => trim((string) $data['reason']), 'id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        audit('vehiculo.salida_administrativa', 'Salida administrativa de vehículo #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/units') {
        json_response(['data' => scope_filter_rows('units',units_for_tenant(tenant_id()),require_auth())]);
    }

    if ($method === 'POST' && $path === '/units') {
        require_permission('settings.manage');
        $data = request_json();
        require_fields($data, ['site_id', 'unit_code']);
        require_tenant_scope('site',(int)$data['site_id']);
        start_plan_capacity_guard(tenant_id(),'units');
        $stmt = db()->prepare(
            'INSERT INTO units (tenant_id, site_id, tower, unit_code, floor, status)
             VALUES (:tenant_id, :site_id, :tower, :unit_code, :floor, :status)'
        );
        $stmt->execute([
            'tenant_id' => tenant_id(),
            'site_id' => $data['site_id'],
            'tower' => trim((string) ($data['tower'] ?? '')),
            'unit_code' => $data['unit_code'],
            'floor' => $data['floor'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
        $createdUnitId=(int)db()->lastInsertId();
        finish_plan_capacity_guard();
        audit('unidad.creada', 'Unidad ' . trim(($data['tower'] ?? '') . ' ' . $data['unit_code']));
        json_response(['id' => $createdUnitId], 201);
    }

    if ($method === 'GET' && $path === '/residents') {
        json_response(['data' => residents_for_tenant(tenant_id())]);
    }

    if ($method === 'POST' && preg_match('#^/residents/(\d+)/password$#', $path, $matches)) {
        $user = require_permission('settings.manage');
        $data = request_json();
        require_fields($data, ['password']);
        if (strlen((string) $data['password']) < 8) json_response(['error' => 'La contraseña debe tener al menos 8 caracteres'], 422);
        $resident = one_row('SELECT id FROM residents WHERE id = :id AND tenant_id = :tenant_id LIMIT 1', ['id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        if (!$resident) json_response(['error' => 'Residente no encontrado'], 404);
        db()->prepare('UPDATE residents SET password_hash = :password_hash, password_set_at = NOW() WHERE id = :id AND tenant_id = :tenant_id')->execute(['password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT), 'id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        audit('residente.password', 'Contraseña de portal actualizada para residente #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/residents') {
        require_permission('settings.manage');
        $user = require_auth();
        $data = request_json();
        require_fields($data, ['unit_id', 'document_type', 'document_number', 'full_name']);

        $pdo = db();
        $pdo->beginTransaction();
        $personPayload = [
            'person_type' => 'residente',
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'],
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'company' => null,
        ];
        $personId = upsert_person($pdo, (int) $user['tenant_id'], $personPayload);
        $stmt = $pdo->prepare(
            'INSERT INTO residents (tenant_id, unit_id, person_id, relationship, is_primary, receives_notifications, status)
             VALUES (:tenant_id, :unit_id, :person_id, :relationship, :is_primary, :receives_notifications, :status)'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'unit_id' => $data['unit_id'],
            'person_id' => $personId,
            'relationship' => $data['relationship'] ?? 'residente',
            'is_primary' => !empty($data['is_primary']) ? 1 : 0,
            'receives_notifications' => array_key_exists('receives_notifications', $data) ? (int) (bool) $data['receives_notifications'] : 1,
            'status' => $data['status'] ?? 'active',
        ]);
        $residentId = $pdo->lastInsertId();
        $pdo->commit();
        audit('residente.creado', 'Residente ' . $data['full_name']);
        json_response(['id' => $residentId], 201);
    }

    if ($method === 'GET' && $path === '/packages') {
        json_response(['data' => scope_filter_rows('packages',packages_for_tenant(tenant_id()),require_auth())]);
    }

    if ($method === 'GET' && $path === '/portal/packages') {
        $resident = require_portal_auth();
        json_response(['data' => portal_packages_for_resident($resident)]);
    }

    if ($method === 'GET' && $path === '/portal/invitations') {
        $resident = require_portal_auth();
        json_response(['data' => list_rows(
            'SELECT guest_name, guest_document, destination, host, reason, qr_token, valid_from, valid_until, status, created_at
             FROM invitations
             WHERE tenant_id = :tenant_id AND destination = :destination AND host = :host
             ORDER BY created_at DESC
             LIMIT 80',
            [
                'tenant_id' => $resident['tenant_id'],
                'destination' => portal_resident_destination($resident),
                'host' => $resident['full_name'],
            ]
        )]);
    }

    if ($method === 'GET' && $path === '/portal/bookings') {
        $resident = require_portal_auth();
        json_response(['data' => list_rows('SELECT b.*, br.name AS resource_name FROM bookings b INNER JOIN booking_resources br ON br.id = b.resource_id WHERE b.tenant_id = :tenant_id AND b.requester_name = :requester_name ORDER BY b.starts_at DESC LIMIT 100', ['tenant_id' => $resident['tenant_id'], 'requester_name' => $resident['full_name']])]);
    }

    if ($method === 'POST' && $path === '/portal/bookings') {
        $resident = require_portal_auth();
        $data = request_json();
        require_fields($data, ['resource_id', 'purpose', 'starts_at', 'ends_at']);
        $resourceId = (int) $data['resource_id'];
        $startsAt = (string) $data['starts_at'];
        $endsAt = (string) $data['ends_at'];
        ensure_booking_window_available((int) $resident['tenant_id'], $resourceId, $startsAt, $endsAt);
        $stmt = db()->prepare(
            'INSERT INTO bookings (tenant_id, resource_id, created_by, requester_name, requester_phone, purpose, starts_at, ends_at, status, amount, deposit_amount, terms_accepted_at, source, notes)
             VALUES (:tenant_id, :resource_id, :created_by, :requester_name, :requester_phone, :purpose, :starts_at, :ends_at, "pre_reservation", 0, 0, :terms_accepted_at, "resident", :notes)'
        );
        $stmt->execute([
            'tenant_id' => $resident['tenant_id'],
            'resource_id' => $resourceId,
            'created_by' => portal_system_user_id((int) $resident['tenant_id']),
            'requester_name' => $resident['full_name'],
            'requester_phone' => $resident['phone'] ?? null,
            'purpose' => trim((string) $data['purpose']),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'terms_accepted_at' => !empty($data['terms_accepted']) ? date('Y-m-d H:i:s') : null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ]);
        $bookingId = db()->lastInsertId();
        audit('reserva.pre_reserva', 'Pre-reserva #' . $bookingId . ' creada por residente');
        json_response(['id' => $bookingId, 'status' => 'pre_reservation'], 201);
    }

    if ($method === 'GET' && $path === '/portal/activity') {
        $resident = require_portal_auth();
        $destination = portal_resident_destination($resident);
        json_response(['data' => list_rows('SELECT "visita" AS tipo, v.id, v.entered_at AS ocurrido_at, v.status, v.destination AS detalle FROM visits v INNER JOIN people p ON p.id = v.person_id WHERE v.tenant_id = :tenant_id AND v.host = :host UNION ALL SELECT "paquete" AS tipo, pk.id, pk.received_at AS ocurrido_at, pk.status, pk.tracking AS detalle FROM packages pk WHERE pk.tenant_id = :tenant_id_packages AND pk.destination LIKE :destination ORDER BY ocurrido_at DESC LIMIT 120', ['tenant_id' => $resident['tenant_id'], 'host' => $resident['full_name'], 'tenant_id_packages' => $resident['tenant_id'], 'destination' => '%' . $destination . '%'])]);
    }

    if ($method === 'POST' && $path === '/portal/invitations') {
        $resident = require_portal_auth();
        $data = request_json();
        require_fields($data, ['guest_name', 'reason', 'valid_until']);
        $token = hash('sha256', random_bytes(32) . microtime(true));
        $stmt = db()->prepare(
            'INSERT INTO invitations (tenant_id, site_id, created_by, guest_name, guest_document, destination, host, reason, qr_token, valid_from, valid_until)
             VALUES (:tenant_id, :site_id, :created_by, :guest_name, :guest_document, :destination, :host, :reason, :qr_token, NOW(), :valid_until)'
        );
        $stmt->execute([
            'tenant_id' => $resident['tenant_id'],
            'site_id' => $resident['site_id'],
            'created_by' => portal_system_user_id((int) $resident['tenant_id']),
            'guest_name' => $data['guest_name'],
            'guest_document' => $data['guest_document'] ?? null,
            'destination' => portal_resident_destination($resident),
            'host' => $resident['full_name'],
            'reason' => $data['reason'],
            'qr_token' => $token,
            'valid_until' => $data['valid_until'],
        ]);
        json_response(['id' => db()->lastInsertId(), 'qr_token' => $token], 201);
    }

    if ($method === 'POST' && $path === '/packages') {
        $user = require_permission('packages.manage');
        $data = request_json();
        require_fields($data, ['tracking', 'courier']);
        $data['shift_id'] = active_shift_id_for($user, $data['shift_id'] ?? null);
        $unitId = !empty($data['unit_id']) ? (int) $data['unit_id'] : null;
        $residentId = !empty($data['recipient_resident_id']) ? (int) $data['recipient_resident_id'] : null;
        $destination = trim((string) ($data['destination'] ?? ''));
        $recipient = trim((string) ($data['recipient'] ?? ''));
        if ($unitId) {
            require_tenant_scope('unit',$unitId);
            $unit = one_row('SELECT id, tower, unit_code FROM units WHERE id = :id AND tenant_id = :tenant_id AND status = "active" LIMIT 1', ['id' => $unitId, 'tenant_id' => tenant_id()]);
            if (!$unit) json_response(['error' => 'Unidad de destino no encontrada'], 422);
            $destination = trim(($unit['tower'] ? $unit['tower'] . ' - ' : '') . $unit['unit_code']);
        }
        if ($residentId) {
            $resident = one_row('SELECT r.id, r.unit_id, p.full_name FROM residents r INNER JOIN people p ON p.id = r.person_id WHERE r.id = :id AND r.tenant_id = :tenant_id AND r.status = "active" LIMIT 1', ['id' => $residentId, 'tenant_id' => tenant_id()]);
            if (!$resident) json_response(['error' => 'Residente destinatario no encontrado'], 422);
            $recipient = $resident['full_name'];
            $unitId = $unitId ?: (int) $resident['unit_id'];
            require_tenant_scope('unit',$unitId);
        }
        if ($recipient === '' || $destination === '') json_response(['error' => 'Selecciona unidad y persona destinataria'], 422);
        $stmt = db()->prepare(
            'INSERT INTO packages (tenant_id, shift_id, tracking, package_type, service_type, courier, courier_phone, recipient, destination, unit_id, recipient_resident_id, received_at, status)
             VALUES (:tenant_id, :shift_id, :tracking, :package_type, :service_type, :courier, :courier_phone, :recipient, :destination, :unit_id, :recipient_resident_id, NOW(), "pending")'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'shift_id' => $data['shift_id'] ?? null,
            'tracking' => $data['tracking'],
            'package_type' => in_array(($data['package_type'] ?? 'paquete'), ['documento', 'paquete', 'mercancia', 'alimento', 'servicios', 'otro'], true) ? $data['package_type'] : 'otro',
            'service_type' => trim((string) ($data['service_type'] ?? '')) ?: null,
            'courier' => $data['courier'],
            'courier_phone' => trim((string) ($data['courier_phone'] ?? '')) ?: null,
            'recipient' => $recipient,
            'destination' => $destination,
            'unit_id' => $unitId,
            'recipient_resident_id' => $residentId,
        ]);
        $packageId = db()->lastInsertId();
        queue_role_notifications(
            tenant_id(),
            ['superadmin', 'admin', 'supervisor'],
            'Nuevo paquete recibido en Asiscontrol',
            "Se recibio el paquete {$data['tracking']} de {$data['courier']} para {$data['recipient']} ({$data['destination']})."
        );
        audit('paquete.recibido', 'Paquete ' . $data['tracking'] . ' recibido');
        json_response(['id' => $packageId], 201);
    }

    if ($method === 'POST' && preg_match('#^/packages/(\d+)/deliver$#', $path, $matches)) {
        require_permission('packages.manage');
        require_scoped_record('packages',(int)$matches[1]);
        $data = request_json();
        $stmt = db()->prepare(
            'UPDATE packages
             SET status = "delivered",
                 delivered_at = NOW(),
                 delivered_to = :delivered_to,
                 delivery_observations = :delivery_observations
             WHERE id = :id AND tenant_id = :tenant_id AND status = "pending"'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => tenant_id(),
            'delivered_to' => trim((string) ($data['receiver_name'] ?? '')) ?: null,
            'delivery_observations' => trim((string) ($data['observations'] ?? '')) ?: null,
        ]);
        audit('paquete.entregado', 'Paquete #' . $matches[1] . ' entregado');
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/packages/(\d+)/(reject|return)$#', $path, $matches)) {
        $user = require_permission('packages.manage');
        require_scoped_record('packages',(int)$matches[1]);
        $data = request_json();
        require_fields($data, ['reason']);
        $action = $matches[2];
        $status = $action === 'reject' ? 'rejected' : 'returned';
        $stmt = db()->prepare('UPDATE packages SET status = :status, rejection_reason = CASE WHEN :status_rejected = "rejected" THEN :reason ELSE rejection_reason END, return_reason = CASE WHEN :status_returned = "returned" THEN :reason ELSE return_reason END, returned_at = CASE WHEN :status_returned_date = "returned" THEN NOW() ELSE returned_at END, returned_by = CASE WHEN :status_returned_by = "returned" THEN :user_id ELSE returned_by END WHERE id = :id AND tenant_id = :tenant_id AND status = "pending"');
        $stmt->execute(['status' => $status, 'status_rejected' => $status, 'status_returned' => $status, 'status_returned_date' => $status, 'status_returned_by' => $status, 'reason' => trim((string) $data['reason']), 'user_id' => $user['id'], 'id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        audit('paquete.' . $status, 'Paquete #' . $matches[1] . ' marcado como ' . humanize_code_label($status));
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/packages/(\d+)/signature$#', $path, $matches)) {
        require_permission('packages.manage');
        require_scoped_record('packages',(int)$matches[1]);
        $data = request_json();
        require_fields($data, ['receiver_name', 'signature_data_url']);
        $signatureId = store_package_signature(
            tenant_id(),
            (int) $matches[1],
            (string) $data['receiver_name'],
            (string) $data['signature_data_url'],
            trim((string) ($data['observations'] ?? '')) ?: null
        );
        audit('paquete.firma', 'Firma de paquete #' . $matches[1]);
        json_response(['id' => $signatureId], 201);
    }

    if ($method === 'GET' && $path === '/invitations') {
        json_response(['data' => scope_filter_rows('invitations',list_rows('SELECT * FROM invitations WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 200', ['tenant_id' => tenant_id()]),require_auth())]);
    }

    if ($method === 'GET' && $path === '/approvals') {
        json_response(['data' => approvals_for_tenant(tenant_id())]);
    }

    if ($method === 'POST' && $path === '/approvals') {
        $user = require_permission('approvals.manage');
        $data = request_json();
        require_fields($data, ['subject_type', 'notes']);
        $stmt = db()->prepare(
            'INSERT INTO approvals (tenant_id, requested_by, subject_type, subject_id, notes, requested_at)
             VALUES (:tenant_id, :requested_by, :subject_type, :subject_id, :notes, NOW())'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'requested_by' => $user['id'],
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_id'] ?? null,
            'notes' => $data['notes'],
        ]);
        audit('aprobacion.solicitada', 'Solicitud de ' . humanize_code_label((string) $data['subject_type']));
        json_response(['id' => db()->lastInsertId()], 201);
    }

    if ($method === 'POST' && preg_match('#^/approvals/(\d+)/(approve|reject)$#', $path, $matches)) {
        $user = require_permission('approvals.manage');
        $status = $matches[2] === 'approve' ? 'approved' : 'rejected';
        $stmt = db()->prepare(
            'UPDATE approvals
             SET status = :status, decided_by = :decided_by, decided_at = NOW()
             WHERE id = :id AND tenant_id = :tenant_id AND status = "pending"'
        );
        $stmt->execute([
            'status' => $status,
            'decided_by' => $user['id'],
            'id' => $matches[1],
            'tenant_id' => $user['tenant_id'],
        ]);
        audit('aprobacion.' . $status, 'Solicitud #' . $matches[1] . ': ' . humanize_code_label($status));
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/invitations') {
        $user = require_role(['admin', 'superadmin']);
        $data = request_json();
        require_fields($data, ['site_id', 'guest_name', 'destination', 'host', 'reason', 'valid_from', 'valid_until']);
        $token = hash('sha256', random_bytes(32) . microtime(true));
        $stmt = db()->prepare(
            'INSERT INTO invitations (tenant_id, site_id, created_by, guest_name, guest_document, destination, host, reason, qr_token, valid_from, valid_until)
             VALUES (:tenant_id, :site_id, :created_by, :guest_name, :guest_document, :destination, :host, :reason, :qr_token, :valid_from, :valid_until)'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'site_id' => $data['site_id'],
            'created_by' => $user['id'],
            'guest_name' => $data['guest_name'],
            'guest_document' => $data['guest_document'] ?? null,
            'destination' => $data['destination'],
            'host' => $data['host'],
            'reason' => $data['reason'],
            'qr_token' => $token,
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
        ]);
        $invitationId = db()->lastInsertId();
        queue_role_notifications(
            (int) $user['tenant_id'],
            ['superadmin', 'admin', 'supervisor'],
            'Nueva invitacion QR creada',
            "Se creo una invitacion para {$data['guest_name']} hacia {$data['destination']} con anfitrion {$data['host']}. Token: {$token}"
        );
        audit('invitacion.creada', 'Invitacion para ' . $data['guest_name']);
        json_response(['id' => $invitationId, 'qr_token' => $token], 201);
    }

    if ($method === 'POST' && preg_match('#^/invitations/(\d+)/cancel$#', $path, $matches)) {
        require_permission('visits.manage');
        $stmt = db()->prepare('UPDATE invitations SET status = "cancelled" WHERE id = :id AND tenant_id = :tenant_id AND status = "active"');
        $stmt->execute(['id' => $matches[1], 'tenant_id' => tenant_id()]);
        audit('invitacion.cancelada', 'Invitacion #' . $matches[1]);
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && preg_match('#^/invitations/(\d+)/print.html$#', $path, $matches)) {
        html_invitation_print(tenant_id(), (int) $matches[1]);
    }

    if ($method === 'POST' && $path === '/invitations/redeem') {
        $user = require_permission('visits.manage');
        $data = request_json();
        require_fields($data, ['qr_token', 'shift_id', 'document_type', 'document_number']);
        $data['shift_id'] = active_shift_id_for($user, $data['shift_id']);
        $invitation = one_row(
            'SELECT * FROM invitations
             WHERE tenant_id = :tenant_id AND qr_token = :qr_token AND status = "active" AND valid_from <= NOW() AND valid_until >= NOW()
             LIMIT 1',
            ['tenant_id' => $user['tenant_id'], 'qr_token' => $data['qr_token']]
        );

        if (!$invitation) {
            json_response(['error' => 'Invitacion invalida, vencida o usada'], 422);
        }
        $currentSite=validated_shift_site((int)$data['shift_id'],$user);
        if ((int)$invitation['site_id']!==$currentSite) {
            json_response(['error'=>'Invitación fuera de tu sede','error_code'=>'SCOPE_DENIED'],403);
        }

        $pdo = db();
        $pdo->beginTransaction();
        $personId = upsert_person($pdo, (int) $user['tenant_id'], [
            'person_type' => 'visitante',
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'],
            'full_name' => $data['full_name'] ?? $invitation['guest_name'],
            'phone' => $data['phone'] ?? null,
            'company' => null,
        ]);
        $stmt = $pdo->prepare(
            'INSERT INTO visits (tenant_id, person_id, shift_id, destination, host, reason, entered_at, status)
             VALUES (:tenant_id, :person_id, :shift_id, :destination, :host, :reason, NOW(), "inside")'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'person_id' => $personId,
            'shift_id' => $data['shift_id'],
            'destination' => $invitation['destination'],
            'host' => $invitation['host'],
            'reason' => $invitation['reason'],
        ]);
        $visitId = $pdo->lastInsertId();
        $stmt = $pdo->prepare('UPDATE invitations SET status = "used" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute(['id' => $invitation['id'], 'tenant_id' => $user['tenant_id']]);
        $pdo->commit();
        audit('invitacion.usada', 'Invitacion convertida en visita #' . $visitId);
        json_response(['id' => $visitId], 201);
    }

    if ($method === 'GET' && $path === '/incidents') {
        json_response(['data' => scope_filter_rows('incidents',list_rows('SELECT * FROM incidents WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 200', ['tenant_id' => tenant_id()]),require_auth())]);
    }

    if ($method === 'POST' && $path === '/incidents') {
        $user = require_permission('incidents.manage');
        $data = request_json();
        require_fields($data, ['title', 'severity', 'area', 'description']);
        $data['shift_id'] = active_shift_id_for($user, $data['shift_id'] ?? null);
        $stmt = db()->prepare(
            'INSERT INTO incidents (tenant_id, shift_id, user_id, title, severity, area, description, status, linked_type, linked_id)
             VALUES (:tenant_id, :shift_id, :user_id, :title, :severity, :area, :description, "open", :linked_type, :linked_id)'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'shift_id' => $data['shift_id'] ?? null,
            'user_id' => $user['id'],
            'title' => $data['title'],
            'severity' => $data['severity'],
            'area' => $data['area'],
            'description' => $data['description'],
            'linked_type' => in_array(($data['linked_type'] ?? ''), ['person', 'vehicle', 'package', 'access_point', 'unit'], true) ? $data['linked_type'] : null,
            'linked_id' => !empty($data['linked_id']) ? (int) $data['linked_id'] : null,
        ]);
        audit('incidente.creado', $data['title']);
        json_response(['id' => db()->lastInsertId()], 201);
    }

    if ($method === 'POST' && preg_match('#^/incidents/(\d+)/review$#', $path, $matches)) {
        require_permission('incidents.manage');
        require_scoped_record('incidents',(int)$matches[1]);
        db()->prepare('UPDATE incidents SET status = "in_review" WHERE id = :id AND tenant_id = :tenant_id AND status = "open"')->execute(['id' => $matches[1], 'tenant_id' => tenant_id()]);
        audit('incidente.en_revision', 'Incidente #' . $matches[1] . ' en revisión');
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/incidents/(\d+)/close$#', $path, $matches)) {
        $user = require_permission('incidents.manage');
        require_scoped_record('incidents',(int)$matches[1]);
        $data = request_json();
        if ((string) $user['role'] === 'guard' && !empty($data['severity']) && $data['severity'] === 'alta') json_response(['error' => 'Los incidentes de alta severidad deben cerrarse por un administrador'], 403);
        $stmt = db()->prepare('UPDATE incidents SET status = "closed", closed_at = NOW(), closed_by = :closed_by, resolution = :resolution WHERE id = :id AND tenant_id = :tenant_id AND status IN ("open", "in_review")');
        $stmt->execute(['id' => $matches[1], 'tenant_id' => $user['tenant_id'], 'closed_by' => $user['id'], 'resolution' => trim((string) ($data['resolution'] ?? '')) ?: null]);
        audit('incidente.cerrado', 'Incidente #' . $matches[1] . ' cerrado');
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/audit') {
        require_permission('audit.view');
        if (actor_visible_site_ids(require_auth())!==null) json_response(['error'=>'Auditoría global no disponible en alcances de sede'],403);
        json_response(['data' => list_rows('SELECT * FROM audit_logs WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 120', ['tenant_id' => tenant_id()])]);
    }

    if ($method === 'GET' && $path === '/audit.csv') {
        require_permission('audit.view');
        $rows = audit_for_tenant(tenant_id(), [
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'action' => $_GET['action'] ?? null,
        ]);
        csv_response(
            'asiscontrol-auditoria.csv',
            ['id', 'fecha', 'usuario_id', 'accion', 'detalle', 'ip'],
            array_map(static fn (array $row): array => [
                $row['id'],
                $row['created_at'],
                $row['user_id'],
                humanize_code_label((string) $row['action']),
                humanize_code_text((string) $row['detail']),
                $row['ip_address'],
            ], $rows)
        );
    }

    if ($method === 'GET' && $path === '/audit.html') {
        require_permission('audit.view');
        html_audit_report(tenant_id(), [
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'action' => $_GET['action'] ?? null,
        ]);
    }

    if ($method === 'GET' && $path === '/jobs/imports') {
        require_permission('reports.view');
        json_response(['data' => list_rows(
            'SELECT id, job_type, file_name, status, total_rows, processed_rows, error_message, created_at, finished_at
             FROM import_jobs
             WHERE tenant_id = :tenant_id
             ORDER BY created_at DESC
             LIMIT 100',
            ['tenant_id' => tenant_id()]
        )]);
    }

    if ($method === 'GET' && $path === '/jobs/exports') {
        require_permission('reports.view');
        json_response(['data' => list_rows(
            'SELECT id, report_type, filters_json, file_path, status, created_at, finished_at
             FROM export_jobs
             WHERE tenant_id = :tenant_id
             ORDER BY created_at DESC
             LIMIT 100',
            ['tenant_id' => tenant_id()]
        )]);
    }

    if ($method === 'GET' && $path === '/notifications') {
        require_permission('reports.view');
        json_response(['data' => list_rows(
            'SELECT id, channel, recipient, subject, status, error_message, sent_at, created_at
             FROM notifications
             WHERE tenant_id = :tenant_id
             ORDER BY created_at DESC
             LIMIT 100',
            ['tenant_id' => tenant_id()]
        )]);
    }

    if ($method === 'GET' && $path === '/reports/summary') {
        require_permission('reports.view');
        json_response(['data' => report_summary(tenant_id())]);
    }

    if ($method === 'GET' && $path === '/reports/visits.csv') {
        require_permission('reports.view');
        $rows = visits_for_tenant(tenant_id(), [
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'status' => $_GET['status'] ?? null,
        ]);
        csv_response(
            'asiscontrol-visitas.csv',
            ['id', 'persona', 'tipo_documento', 'documento', 'destino', 'autoriza', 'motivo', 'placa', 'ingreso', 'salida', 'estado'],
            array_map(static fn (array $row): array => [
                $row['id'],
                $row['full_name'],
                $row['document_type'],
                $row['document_number'],
                $row['destination'],
                $row['host'],
                $row['reason'],
                $row['vehicle_plate'],
                $row['entered_at'],
                $row['exited_at'],
                humanize_code_label((string) $row['status']),
            ], $rows)
        );
    }

    if ($method === 'GET' && $path === '/reports/visits.xlsx') {
        require_permission('reports.view');
        $rows = visits_for_tenant(tenant_id(), [
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'status' => $_GET['status'] ?? null,
        ]);
        $headers = ['id', 'persona', 'tipo_documento', 'documento', 'destino', 'autoriza', 'motivo', 'placa', 'ingreso', 'salida', 'estado'];
        $exportRows = array_map(static fn (array $row): array => [$row['id'], $row['full_name'], $row['document_type'], $row['document_number'], $row['destination'], $row['host'], $row['reason'], $row['vehicle_plate'], $row['entered_at'], $row['exited_at'], humanize_code_label((string) $row['status'])], $rows);
        xlsx_response('asiscontrol-visitas.xlsx', $headers, $exportRows);
    }

    if ($method === 'GET' && $path === '/reports/visits.html') {
        require_permission('reports.view');
        html_visits_report(tenant_id(), [
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'status' => $_GET['status'] ?? null,
        ]);
    }

    if ($method === 'POST' && $path === '/imports') {
        require_permission('imports.manage');
        $user = require_auth();
        $jobType = $_POST['job_type'] ?? '';

        if (!$jobType || empty($_FILES['file'])) {
            json_response(['error' => 'job_type y archivo CSV/XLSX son requeridos'], 422);
        }

        $extension = strtolower(pathinfo((string) ($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
        $result = $extension === 'xlsx'
            ? import_xlsx((int) $user['tenant_id'], (int) $user['id'], (string) $jobType, $_FILES['file'])
            : import_csv((int) $user['tenant_id'], (int) $user['id'], (string) $jobType, $_FILES['file']);
        audit($extension === 'xlsx' ? 'importacion.xlsx' : 'importacion.csv', 'Importación de ' . humanize_code_label((string) $jobType) . ': ' . $result['processed_rows'] . ' filas');
        json_response(['data' => $result], 201);
    }

    if ($method === 'GET' && preg_match('#^/imports/templates/(restrictions|people|users)\.csv$#', $path, $matches)) {
        require_permission('imports.manage');
        import_template_csv($matches[1]);
    }

    if ($method === 'GET' && preg_match('#^/imports/templates/(restrictions|people|users)\.xlsx$#', $path, $matches)) {
        require_permission('imports.manage');
        import_template_xlsx($matches[1]);
    }

    if ($method === 'GET' && $path === '/files') {
        require_permission('files.manage');
        $ownerType = $_GET['owner_type'] ?? '';
        $ownerId = $_GET['owner_id'] ?? '';
        require_query_fields(['owner_type' => $ownerType, 'owner_id' => $ownerId]);
        json_response(['data' => files_for_owner(tenant_id(), (string) $ownerType, (int) $ownerId)]);
    }

    if ($method === 'GET' && preg_match('#^/files/(\d+)/download$#', $path, $matches)) {
        require_permission('files.manage');
        download_attachment(tenant_id(), (int) $matches[1]);
    }

    if ($method === 'POST' && $path === '/files') {
        $user = require_permission('files.manage');
        $ownerType = $_POST['owner_type'] ?? '';
        $ownerId = (int) ($_POST['owner_id'] ?? 0);

        if (!$ownerType || !$ownerId || empty($_FILES['file'])) {
            json_response(['error' => 'Archivo, owner_type y owner_id son requeridos'], 422);
        }

        $fileId = store_attachment((int) $user['tenant_id'], (int) $user['id'], (string) $ownerType, $ownerId, $_FILES['file']);
        audit('archivo.subido', 'Archivo adjunto #' . $fileId . ' para ' . $ownerType . ':' . $ownerId);
        json_response(['id' => $fileId], 201);
    }

    if ($method === 'GET' && $path === '/access-events') {
        require_permission('audit.view');
        $where = ['ae.tenant_id = :tenant_id'];
        $params = ['tenant_id' => tenant_id()];
        if (!empty($_GET['from'])) {
            $where[] = 'ae.occurred_at >= :from_date';
            $params['from_date'] = $_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $where[] = 'ae.occurred_at <= :to_date';
            $params['to_date'] = $_GET['to'] . ' 23:59:59';
        }
        json_response(['data' => scope_filter_rows('access_events',list_rows(
            'SELECT ae.*, p.full_name, s.name AS site_name, ap.name AS access_point_name, d.name AS device_name
             FROM access_events ae
             LEFT JOIN people p ON p.id = ae.person_id
             LEFT JOIN sites s ON s.id = ae.site_id
             LEFT JOIN access_points ap ON ap.id = ae.access_point_id
             LEFT JOIN devices d ON d.id = ae.device_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ae.occurred_at DESC LIMIT 500',
            $params
        ),require_auth())]);
    }

    if ($method === 'POST' && $path === '/access-events') {
        $user = require_permission('access_events.register');
        $data = request_json();
        require_fields($data, ['event_uuid', 'idempotency_key', 'event_type', 'occurred_at']);
        $eventType = (string) $data['event_type'];
        if (!in_array($eventType, ['entrada', 'salida', 'salida_administrativa'], true)) {
            json_response(['error' => 'Tipo de evento invalido'], 422);
        }
        $eventUuid = strtolower(trim((string) $data['event_uuid']));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $eventUuid)) {
            json_response(['error' => 'event_uuid debe ser un UUID valido'], 422);
        }
        $identificationMethod = (string) ($data['identification_method'] ?? 'manual');
        if (!in_array($identificationMethod, ['huella', 'cedula', 'manual', 'qr', 'otro'], true)) {
            $identificationMethod = 'otro';
        }
        $existing = one_row(
            'SELECT id, status, event_uuid FROM access_events WHERE tenant_id = :tenant_id AND idempotency_key = :idempotency_key LIMIT 1',
            ['tenant_id' => $user['tenant_id'], 'idempotency_key' => substr((string) $data['idempotency_key'], 0, 64)]
        );
        if ($existing) {
            json_response(['id' => $existing['id'], 'status' => $existing['status'], 'duplicate' => true]);
        }
        $personId = !empty($data['person_id']) ? (int) $data['person_id'] : null;
        if ($personId && !one_row('SELECT id FROM people WHERE id = :id AND tenant_id = :tenant_id LIMIT 1', ['id' => $personId, 'tenant_id' => $user['tenant_id']])) {
            json_response(['error' => 'Persona no encontrada'], 404);
        }
        $siteId = !empty($data['site_id']) ? (int) $data['site_id'] : null;
        if ($siteId && !one_row('SELECT id FROM sites WHERE id = :id AND tenant_id = :tenant_id LIMIT 1', ['id' => $siteId, 'tenant_id' => $user['tenant_id']])) {
            json_response(['error' => 'Sede no encontrada'], 404);
        }
        $accessPointId = !empty($data['access_point_id']) ? (int) $data['access_point_id'] : null;
        if ($accessPointId && !one_row('SELECT id FROM access_points WHERE id = :id AND tenant_id = :tenant_id LIMIT 1', ['id' => $accessPointId, 'tenant_id' => $user['tenant_id']])) {
            json_response(['error' => 'Punto de acceso no encontrado'], 404);
        }
        if ($siteId) require_tenant_scope('site',$siteId);
        elseif (actor_visible_site_ids($user)!==null) json_response(['error'=>'Sede requerida'],422);
        if ($accessPointId) {
            $point=one_row('SELECT site_id FROM access_points WHERE id=:id AND tenant_id=:tenant',
                ['id'=>$accessPointId,'tenant'=>$user['tenant_id']]);
            if (!$point || (int)$point['site_id']!==(int)$siteId) json_response(['error'=>'Punto de acceso fuera de la sede'],422);
        }
        $contextCheck = evaluate_access_context($accessPointId, $data);
        $eventMetadata = array_merge(is_array($data['metadata'] ?? null) ? $data['metadata'] : [], $contextCheck['metadata']);
        $deviceId = !empty($data['device_id']) && is_numeric($data['device_id']) ? (int) $data['device_id'] : null;
        if ($deviceId && !one_row('SELECT id FROM devices WHERE id = :id AND tenant_id = :tenant_id AND status = "active" LIMIT 1', ['id' => $deviceId, 'tenant_id' => $user['tenant_id']])) {
            json_response(['error' => 'Dispositivo no autorizado'], 422);
        }
        $stmt = db()->prepare(
            'INSERT INTO access_events (event_uuid, tenant_id, person_id, person_quality, site_id, access_point_id, device_id, operator_user_id, event_type, identification_method, occurred_at, server_received_at, source, idempotency_key, status, metadata_json)
             VALUES (:event_uuid, :tenant_id, :person_id, :person_quality, :site_id, :access_point_id, :device_id, :operator_user_id, :event_type, :identification_method, :occurred_at, NOW(), "online", :idempotency_key, :status, :metadata_json)'
        );
        $stmt->execute([
            'event_uuid' => $eventUuid,
            'tenant_id' => $user['tenant_id'],
            'person_id' => $personId,
            'person_quality' => $data['person_quality'] ?? null,
            'site_id' => $siteId,
            'access_point_id' => $accessPointId,
            'device_id' => $deviceId,
            'operator_user_id' => $user['id'],
            'event_type' => $eventType,
            'identification_method' => $identificationMethod,
            'occurred_at' => $data['occurred_at'],
            'idempotency_key' => substr((string) $data['idempotency_key'], 0, 64),
            'status' => $contextCheck['status'],
            'metadata_json' => json_encode($eventMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $eventId = db()->lastInsertId();
        audit('acceso.evento', 'Evento de acceso #' . $eventId . ' registrado (' . humanize_code_label((string) $contextCheck['status']) . ')');
        json_response(['id' => $eventId, 'status' => $contextCheck['status']], 201);
    }

    if ($method === 'POST' && $path === '/offline/events') {
        $user = require_permission('access_events.register');
        $data = request_json();
        require_fields($data, ['event_uuid', 'idempotency_key', 'action']);
        $offlineEventUuid = strtolower(trim((string) $data['event_uuid']));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $offlineEventUuid)) {
            json_response(['error' => 'event_uuid debe ser un UUID valido'], 422);
        }
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $payload = normalize_offline_scope($user,$data,$payload);
        $existing = one_row(
            'SELECT id, status, event_uuid FROM offline_queue WHERE tenant_id = :tenant_id AND idempotency_key = :idempotency_key LIMIT 1',
            ['tenant_id' => $user['tenant_id'], 'idempotency_key' => $data['idempotency_key']]
        );
        if ($existing) {
            json_response(['id' => $existing['id'], 'status' => $existing['status'], 'duplicate' => true]);
        }
        $stmt = db()->prepare(
            'INSERT INTO offline_queue (tenant_id, device_id, event_uuid, action, payload_json, client_occurred_at, server_received_at, idempotency_key, status)
             VALUES (:tenant_id, :device_id, :event_uuid, :action, :payload_json, :client_occurred_at, NOW(), :idempotency_key, "pending")'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'device_id' => substr((string) ($data['device_id'] ?? 'unknown'), 0, 120),
            'event_uuid' => $offlineEventUuid,
            'action' => substr((string) $data['action'], 0, 100),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_occurred_at' => $data['client_occurred_at'] ?? null,
            'idempotency_key' => substr((string) $data['idempotency_key'], 0, 64),
        ]);
        $offlineQueueId = (int) db()->lastInsertId();
        $accessDecision = apply_offline_access_event($user, $data, $payload);
        if ($accessDecision) {
            db()->prepare('UPDATE offline_queue SET status = "synced", synced_at = NOW() WHERE id = :id')->execute(['id' => $offlineQueueId]);
            if ($accessDecision['status'] === 'superseded') {
                audit('offline.conflicto_auto', 'Evento offline ' . $offlineEventUuid . ' reemplazado por el evento más reciente');
                json_response(['id' => $offlineQueueId, 'status' => 'superseded', 'conflict_id' => $accessDecision['conflict_id'] ?? null], 202);
            }
            audit('offline.evento_aplicado', 'Evento offline ' . $offlineEventUuid . ' aplicado');
            json_response(['id' => $offlineQueueId, 'status' => $accessDecision['status'], 'access_event_id' => $accessDecision['event_id'] ?? null], 202);
        }
        audit('offline.evento_recibido', 'Evento offline ' . $offlineEventUuid);
        json_response(['id' => $offlineQueueId, 'status' => 'pending'], 202);
    }

    if ($method === 'GET' && $path === '/offline/conflicts') {
        require_permission('offline.resolve');
        json_response(['data' => list_rows(
            'SELECT * FROM offline_conflicts WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT 200',
            ['tenant_id' => tenant_id()]
        )]);
    }

    if ($method === 'POST' && preg_match('#^/offline/conflicts/(\d+)/resolve$#', $path, $matches)) {
        $user = require_permission('offline.resolve');
        $data = request_json();
        $status = (string) ($data['status'] ?? 'resolved');
        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            json_response(['error' => 'Estado de conflicto invalido'], 422);
        }
        require_fields($data, ['resolution_notes']);
        $stmt = db()->prepare(
            'UPDATE offline_conflicts
             SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW(), resolution_notes = :resolution_notes
             WHERE id = :id AND tenant_id = :tenant_id AND status = "pending"'
        );
        $stmt->execute([
            'id' => $matches[1],
            'tenant_id' => $user['tenant_id'],
            'status' => $status,
            'reviewed_by' => $user['id'],
            'resolution_notes' => trim((string) $data['resolution_notes']),
        ]);
        audit('offline.conflicto_resuelto', 'Conflicto #' . $matches[1] . ' marcado como ' . humanize_code_label($status));
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/backups') {
        require_permission('backups.manage');
        json_response(['data' => list_rows(
            'SELECT id, tenant_id, requested_by, backup_type, scope_json, file_path, checksum, status, error_message, started_at, finished_at, created_at
             FROM backup_jobs
             WHERE tenant_id = :tenant_id OR tenant_id IS NULL
             ORDER BY created_at DESC LIMIT 100',
            ['tenant_id' => tenant_id()]
        )]);
    }

    if ($method === 'POST' && $path === '/backups') {
        $user = require_permission('backups.manage');
        $data = request_json();
        $backupType = (string) ($data['backup_type'] ?? 'technical');
        if (!in_array($backupType, ['technical', 'operational', 'consolidated'], true)) {
            json_response(['error' => 'Tipo de backup invalido'], 422);
        }
        $scope = is_array($data['scope'] ?? null) ? $data['scope'] : [];
        $stmt = db()->prepare(
            'INSERT INTO backup_jobs (tenant_id, requested_by, backup_type, scope_json, status)
             VALUES (:tenant_id, :requested_by, :backup_type, :scope_json, "queued")'
        );
        $stmt->execute([
            'tenant_id' => $user['tenant_id'],
            'requested_by' => $user['id'],
            'backup_type' => $backupType,
            'scope_json' => json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $backupId = db()->lastInsertId();
        audit('backup.solicitado', 'Backup #' . $backupId . ' solicitado');
        json_response(['id' => $backupId, 'status' => 'queued'], 202);
    }

    if ($method === 'POST' && preg_match('#^/backups/(\d+)/restore/request$#', $path, $matches)) {
        $user = require_permission('backups.restore');
        $data = request_json();
        require_fields($data, ['confirmation', 'reason']);
        if ((string) $data['confirmation'] !== 'RESTAURAR BACKUP') json_response(['error' => 'Escribe exactamente RESTAURAR BACKUP para solicitar la restauración'], 422);
        $backup = one_row('SELECT id, tenant_id, status FROM backup_jobs WHERE id = :id AND (tenant_id = :tenant_id OR tenant_id IS NULL) LIMIT 1', ['id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        if (!$backup || $backup['status'] !== 'completed') json_response(['error' => 'El backup no está disponible para restaurar'], 422);
        $token = bin2hex(random_bytes(24));
        $stmt = db()->prepare('INSERT INTO restore_jobs (tenant_id, requested_by, backup_job_id, confirmation_hash, status, reason) VALUES (:tenant_id, :requested_by, :backup_id, :confirmation_hash, "pending", :reason)');
        $stmt->execute(['tenant_id' => $user['tenant_id'], 'requested_by' => $user['id'], 'backup_id' => $matches[1], 'confirmation_hash' => hash('sha256', $token), 'reason' => trim((string) $data['reason'])]);
        $restoreId = db()->lastInsertId();
        audit('backup.restauracion_solicitada', 'Restauración #' . $restoreId . ' solicitada; requiere segunda aprobación');
        json_response(['id' => $restoreId, 'status' => 'pending'], 202);
    }

    if ($method === 'POST' && preg_match('#^/backups/(\d+)/restore/approve$#', $path, $matches)) {
        $user = require_role(['superadmin']);
        $data = request_json();
        require_fields($data, ['confirmation']);
        if ((string) $data['confirmation'] !== 'APROBAR RESTAURACION') json_response(['error' => 'Escribe exactamente APROBAR RESTAURACION para confirmar'], 422);
        $stmt = db()->prepare('UPDATE restore_jobs SET status = "approved", approved_by = :approved_by, approved_at = NOW() WHERE id = :id AND tenant_id = :tenant_id AND status = "pending"');
        $stmt->execute(['approved_by' => $user['id'], 'id' => $matches[1], 'tenant_id' => $user['tenant_id']]);
        audit('backup.restauracion_aprobada', 'Restauración #' . $matches[1] . ' aprobada; queda en cola técnica');
        json_response(['ok' => true, 'status' => 'approved']);
    }

    if ($method === 'GET' && $path === '/backups/restores') {
        require_permission('backups.restore');
        json_response(['data' => list_rows('SELECT rj.*, u.name AS requested_by_name, a.name AS approved_by_name FROM restore_jobs rj LEFT JOIN users u ON u.id = rj.requested_by LEFT JOIN users a ON a.id = rj.approved_by WHERE rj.tenant_id = :tenant_id ORDER BY rj.created_at DESC LIMIT 100', ['tenant_id' => tenant_id()])]);
    }

    if ($method === 'GET' && $path === '/backups/policy') {
        require_permission('backups.manage');
        $policy = one_row('SELECT id, tenant_id, schedule, retention_days, destination_path, encryption_required, enabled, last_run_at, next_run_at FROM backup_policies WHERE tenant_id = :tenant_id LIMIT 1', ['tenant_id' => tenant_id()]);
        json_response(['data' => $policy ?: ['schedule' => 'monthly', 'retention_days' => 365, 'encryption_required' => 0, 'enabled' => 1]]);
    }

    if ($method === 'PUT' && $path === '/backups/policy') {
        $user = require_permission('backups.manage');
        $data = request_json();
        $schedule = (string) ($data['schedule'] ?? 'monthly');
        if (!in_array($schedule, ['manual', 'daily', 'weekly', 'monthly'], true)) json_response(['error' => 'Frecuencia de backup inválida'], 422);
        $retentionDays = (int) ($data['retention_days'] ?? 365);
        if ($retentionDays < 1 || $retentionDays > 3650) json_response(['error' => 'La retención debe estar entre 1 y 3650 días'], 422);
        $encryptionRequested = array_key_exists('encryption_required', $data) && (bool) $data['encryption_required'];
        if ($encryptionRequested && getenv('BACKUP_ENCRYPTION_REQUIRED') !== '1') {
            json_response(['error' => 'Activa BACKUP_ENCRYPTION_REQUIRED=1 en .env antes de exigir cifrado'], 422);
        }
        if ($encryptionRequested && trim((string) (getenv('BACKUP_ENCRYPTION_KEY') ?: '')) === '') {
            json_response(['error' => 'Configura BACKUP_ENCRYPTION_KEY en .env antes de exigir cifrado'], 422);
        }
        $tenantId = (int) $user['tenant_id'];
        $existing = one_row('SELECT id FROM backup_policies WHERE tenant_id = :tenant_id LIMIT 1', ['tenant_id' => $tenantId]);
        $fields = [
            'tenant_id' => $tenantId,
            'schedule' => $schedule,
            'retention_days' => $retentionDays,
            'destination_path' => trim((string) ($data['destination_path'] ?? '')) ?: null,
            'encryption_required' => array_key_exists('encryption_required', $data) ? (int) (bool) $data['encryption_required'] : 0,
            'enabled' => array_key_exists('enabled', $data) ? (int) (bool) $data['enabled'] : 1,
            'next_run_at' => $schedule === 'monthly' ? date('Y-m-d H:i:s', strtotime('+1 month')) : ($data['next_run_at'] ?? null),
            'created_by' => $user['id'],
        ];
        if ($existing) {
            db()->prepare('UPDATE backup_policies SET schedule = :schedule, retention_days = :retention_days, destination_path = :destination_path, encryption_required = :encryption_required, enabled = :enabled, next_run_at = :next_run_at, created_by = :created_by WHERE id = :id AND tenant_id = :tenant_id')->execute($fields + ['id' => $existing['id']]);
        } else {
            db()->prepare('INSERT INTO backup_policies (tenant_id, schedule, retention_days, destination_path, encryption_required, enabled, next_run_at, created_by) VALUES (:tenant_id, :schedule, :retention_days, :destination_path, :encryption_required, :enabled, :next_run_at, :created_by)')->execute($fields);
        }
        audit('backup.politica_actualizada', 'Política de backup: ' . $schedule . ', retención ' . $retentionDays . ' días');
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/offline/status') {
        require_auth();
        $tenantId = tenant_id();
        $counts = list_rows('SELECT status, COUNT(*) AS total FROM offline_queue WHERE tenant_id = :tenant_id GROUP BY status', ['tenant_id' => $tenantId]);
        $conflicts = one_row('SELECT COUNT(*) AS total FROM offline_conflicts WHERE tenant_id = :tenant_id AND status = "pending"', ['tenant_id' => $tenantId]);
        json_response(['data' => ['queue' => $counts, 'pending_conflicts' => (int) ($conflicts['total'] ?? 0), 'server_time' => date('c')]]);
    }

    if ($method === 'GET' && $path === '/reports/attendance.csv') {
        require_permission('attendance.view');
        $where = ['ar.tenant_id = :tenant_id'];
        $params = ['tenant_id' => tenant_id()];
        if (!empty($_GET['from'])) { $where[] = 'ar.occurred_at >= :attendance_from'; $params['attendance_from'] = $_GET['from'] . ' 00:00:00'; }
        if (!empty($_GET['to'])) { $where[] = 'ar.occurred_at <= :attendance_to'; $params['attendance_to'] = $_GET['to'] . ' 23:59:59'; }
        $rows = list_rows('SELECT ar.id, p.full_name, p.document_number, ep.employee_code, ar.event_type, ar.occurred_at, ar.status, ar.identification_method, ar.source, ar.notes FROM attendance_records ar INNER JOIN people p ON p.id = ar.person_id INNER JOIN employee_profiles ep ON ep.id = ar.employee_profile_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ar.occurred_at DESC LIMIT 5000', $params);
        csv_response('asiscontrol-asistencia.csv', ['id', 'empleado', 'documento', 'codigo', 'evento', 'fecha', 'estado', 'metodo', 'origen', 'notas'], array_map(static fn (array $row): array => [$row['id'], $row['full_name'], $row['document_number'], $row['employee_code'], $row['event_type'], $row['occurred_at'], humanize_code_label((string) $row['status']), humanize_code_label((string) $row['identification_method']), humanize_code_label((string) $row['source']), $row['notes']], $rows));
    }

    if ($method === 'GET' && $path === '/reports/attendance.xlsx') {
        require_permission('attendance.view');
        $where = ['ar.tenant_id = :tenant_id'];
        $params = ['tenant_id' => tenant_id()];
        if (!empty($_GET['from'])) { $where[] = 'ar.occurred_at >= :attendance_from_xlsx'; $params['attendance_from_xlsx'] = $_GET['from'] . ' 00:00:00'; }
        if (!empty($_GET['to'])) { $where[] = 'ar.occurred_at <= :attendance_to_xlsx'; $params['attendance_to_xlsx'] = $_GET['to'] . ' 23:59:59'; }
        $rows = list_rows('SELECT ar.id, p.full_name, p.document_number, ep.employee_code, ar.event_type, ar.occurred_at, ar.status, ar.identification_method, ar.source, ar.notes FROM attendance_records ar INNER JOIN people p ON p.id = ar.person_id INNER JOIN employee_profiles ep ON ep.id = ar.employee_profile_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ar.occurred_at DESC LIMIT 5000', $params);
        $headers = ['id', 'empleado', 'documento', 'codigo', 'evento', 'fecha', 'estado', 'metodo', 'origen', 'notas'];
        $exportRows = array_map(static fn (array $row): array => [$row['id'], $row['full_name'], $row['document_number'], $row['employee_code'], $row['event_type'], $row['occurred_at'], humanize_code_label((string) $row['status']), humanize_code_label((string) $row['identification_method']), humanize_code_label((string) $row['source']), $row['notes']], $rows);
        xlsx_response('asiscontrol-asistencia.xlsx', $headers, $exportRows);
    }

    if ($method === 'GET' && $path === '/platform/me') {
        $user = require_platform_admin();
        json_response(['data' => ['user' => $user, 'platform_admin' => true]]);
    }

    if ($method === 'GET' && $path === '/platform/plans') {
        require_platform_admin();
        json_response(['data' => list_rows('SELECT id, code, name, billing_period, price, limits_json, features_json, status FROM subscription_plans WHERE status = "active" ORDER BY price')]);
    }

    if ($method === 'GET' && $path === '/platform/metrics') {
        require_platform_admin();
        $metrics = one_row(
            'SELECT
                (SELECT COUNT(*) FROM tenants) AS tenants_total,
                (SELECT COUNT(*) FROM tenants WHERE status = "active") AS tenants_active,
                (SELECT COUNT(*) FROM users WHERE is_active = 1) AS users_active,
                (SELECT COUNT(*) FROM visits WHERE entered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS visits_30d,
                (SELECT COUNT(*) FROM access_events WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS access_events_30d,
                (SELECT COUNT(*) FROM bookings WHERE starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS bookings_30d,
                (SELECT COUNT(*) FROM platform_account_invites WHERE used_at IS NULL AND expires_at > NOW()) AS pending_invites'
        ) ?: [];
        json_response(['data' => array_map('intval', $metrics)]);
    }

    if ($method === 'GET' && $path === '/platform/notifications') {
        require_platform_admin();
        json_response(['data' => list_rows(
            'SELECT n.id, n.tenant_id, t.name AS tenant_name, n.channel, n.recipient, n.subject, n.status, n.error_message, n.sent_at, n.created_at
             FROM notifications n LEFT JOIN tenants t ON t.id = n.tenant_id
             ORDER BY n.created_at DESC LIMIT 200'
        )]);
    }

    if ($method === 'GET' && $path === '/platform/audit') {
        require_platform_admin();
        json_response(['data' => list_rows(
            'SELECT pal.id, pal.tenant_id, t.name AS tenant_name, pal.actor_user_id, u.name AS actor_name, pal.action, pal.detail, pal.metadata_json, pal.created_at
             FROM platform_audit_logs pal LEFT JOIN tenants t ON t.id = pal.tenant_id LEFT JOIN users u ON u.id = pal.actor_user_id
             ORDER BY pal.created_at DESC LIMIT 250'
        )]);
    }

    if ($method === 'GET' && $path === '/platform/tenants') {
        require_platform_admin();
        json_response(['data' => list_rows(
            'SELECT t.id, t.name, t.legal_name, t.document_number, t.status, t.created_at,
                     t.parent_tenant_id, ct.code AS client_type,
                    s.name AS site_name, u.name AS admin_name, u.email AS admin_email,
                    ts.id AS subscription_id, ts.status AS subscription_status, ts.starts_on, ts.ends_on,
                    ts.auto_renew, sp.code AS plan_code, sp.name AS plan_name,
                    (SELECT COUNT(*) FROM users tu WHERE tu.tenant_id = t.id AND tu.is_active = 1) AS active_users,
                    (SELECT COUNT(*) FROM units tuu WHERE tuu.tenant_id = t.id AND tuu.status = "active") AS active_units
             FROM tenants t
             LEFT JOIN client_types ct ON ct.id=t.client_type_id
             LEFT JOIN sites s ON s.tenant_id = t.id AND s.id = (SELECT MIN(s2.id) FROM sites s2 WHERE s2.tenant_id = t.id)
             LEFT JOIN users u ON u.tenant_id = t.id AND u.role IN ("admin","tech_operator") AND u.id = (SELECT MIN(u2.id) FROM users u2 WHERE u2.tenant_id = t.id AND u2.role IN ("admin","tech_operator"))
             LEFT JOIN tenant_subscriptions ts ON ts.id = (SELECT MAX(ts2.id) FROM tenant_subscriptions ts2 WHERE ts2.tenant_id = t.id)
             LEFT JOIN subscription_plans sp ON sp.id = ts.plan_id
             ORDER BY t.created_at DESC'
        )]);
    }

    if ($method === 'GET' && preg_match('#^/platform/tenants/(\d+)/metrics$#', $path, $matches)) {
        require_platform_admin();
        $tenantId = (int) $matches[1];
        $metrics = one_row(
            'SELECT t.id, t.name, t.status,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.is_active = 1) AS active_users,
                    (SELECT COUNT(*) FROM people p WHERE p.tenant_id = t.id AND p.record_status = "active") AS active_people,
                    (SELECT COUNT(*) FROM visits v WHERE v.tenant_id = t.id) AS visits_total,
                    (SELECT COUNT(*) FROM access_events ae WHERE ae.tenant_id = t.id) AS access_events_total,
                    (SELECT COUNT(*) FROM bookings b WHERE b.tenant_id = t.id AND b.status <> "cancelled") AS bookings_total,
                    (SELECT COUNT(*) FROM packages pk WHERE pk.tenant_id = t.id) AS packages_total,
                    (SELECT COUNT(*) FROM incidents i WHERE i.tenant_id = t.id AND i.status = "open") AS incidents_open
             FROM tenants t WHERE t.id = :tenant_id LIMIT 1',
            ['tenant_id' => $tenantId]
        );
        if (!$metrics) json_response(['error' => 'Cliente no encontrado'], 404);
        json_response(['data' => array_map(static fn($value) => is_numeric($value) ? (int) $value : $value, $metrics)]);
    }

    if ($method === 'POST' && $path === '/platform/tenants') {
        $actor = require_platform_admin();
        $data = request_json();
        require_fields($data, ['name', 'site_name', 'admin_name', 'admin_email']);
        $email = strtolower(trim((string) $data['admin_email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error' => 'Correo de administrador inválido'], 422);
        $planCode = trim((string) ($data['plan_code'] ?? 'starter')) ?: 'starter';
        $clientTypeCode=(string)($data['client_type']??'custom');
        $clientType=one_row('SELECT id FROM client_types WHERE code=:code',['code'=>$clientTypeCode]);
        if (!$clientType) json_response(['error'=>'Tipo de cliente no reconocido'],422);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO tenants (name, legal_name, document_number, status,client_type_id) VALUES (:name, :legal_name, :document_number, "active",:client_type_id)');
            $stmt->execute([
                'name' => trim((string) $data['name']),
                'legal_name' => trim((string) ($data['legal_name'] ?? '')) ?: null,
                'document_number' => trim((string) ($data['document_number'] ?? '')) ?: null,
                'client_type_id'=>$clientType['id'],
            ]);
            $tenantId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE tenants SET root_tenant_id=:id WHERE id=:id')->execute(['id'=>$tenantId]);
            $stmt = $pdo->prepare('INSERT INTO sites (tenant_id, name, address, timezone) VALUES (:tenant_id, :name, :address, :timezone)');
            $stmt->execute([
                'tenant_id' => $tenantId,
                'name' => trim((string) $data['site_name']),
                'address' => trim((string) ($data['site_address'] ?? '')) ?: null,
                'timezone' => trim((string) ($data['timezone'] ?? 'America/Bogota')) ?: 'America/Bogota',
            ]);
            $siteId = (int) $pdo->lastInsertId();
            $temporaryHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $primaryRole = $clientTypeCode === 'security_company' ? 'tech_operator' : 'admin';
            $stmt = $pdo->prepare('INSERT INTO users (tenant_id, site_id, name, email, password_hash, role, is_active) VALUES (:tenant_id, :site_id, :name, :email, :password_hash, :role, 1)');
            $stmt->execute(['tenant_id' => $tenantId, 'site_id' => $siteId, 'name' => trim((string) $data['admin_name']), 'email' => $email, 'password_hash' => $temporaryHash,'role'=>$primaryRole]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_scopes(tenant_id,user_id,scope_type,scope_id,granted_by) VALUES(:tenant,:user,"tenant",:scope,:actor)')
                ->execute(['tenant'=>$tenantId,'user'=>$userId,'scope'=>$tenantId,'actor'=>$actor['id']]);
            // Copy the approved tenant-1 permission baseline to every new client.
            $pdo->prepare('INSERT INTO role_permissions (tenant_id, role, permission_code, granted) SELECT :new_tenant_id, role, permission_code, granted FROM role_permissions WHERE tenant_id = 1 ON DUPLICATE KEY UPDATE granted = VALUES(granted)')->execute(['new_tenant_id' => $tenantId]);
            $plan = one_row('SELECT id, billing_period FROM subscription_plans WHERE code = :code AND status = "active" ORDER BY id LIMIT 1', ['code' => $planCode]);
            if (!$plan) throw new RuntimeException('Plan no encontrado: ' . $planCode);
            $stmt = $pdo->prepare('INSERT INTO tenant_subscriptions (tenant_id, plan_id, status, starts_on, auto_renew, notes, created_by) VALUES (:tenant_id, :plan_id, "trial", CURRENT_DATE, 0, :notes, :created_by)');
            $stmt->execute(['tenant_id' => $tenantId, 'plan_id' => $plan['id'], 'notes' => 'Cuenta creada desde el panel de proveedor; activar cobro cuando se defina pasarela.', 'created_by' => $actor['id']]);
            $stmt = $pdo->prepare('INSERT INTO backup_policies (tenant_id, schedule, retention_days, encryption_required, enabled, next_run_at, created_by) VALUES (:tenant_id, "monthly", 365, 0, 1, DATE_ADD(CURRENT_DATE, INTERVAL 1 MONTH), :created_by)');
            $stmt->execute(['tenant_id' => $tenantId, 'created_by' => $actor['id']]);
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare('INSERT INTO platform_account_invites (tenant_id, user_id, token_hash, expires_at, created_by) VALUES (:tenant_id, :user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 48 HOUR), :created_by)');
            $stmt->execute(['tenant_id' => $tenantId, 'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'created_by' => $actor['id']]);
            $pdo->commit();
            platform_audit($actor, $tenantId, 'cliente.creado', 'Cliente y administrador creados');
            json_response(['id' => $tenantId, 'admin_user_id' => $userId, 'setup_token' => $token, 'setup_expires_at' => date('c', time() + 172800)], 201);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof PDOException && str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                json_response(['error' => 'El correo del administrador ya está registrado'], 409);
            }
            throw $exception;
        }
    }

    if ($method === 'POST' && preg_match('#^/platform/tenants/(\d+)/status$#', $path, $matches)) {
        $actor = require_platform_admin();
        $data = request_json();
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true)) json_response(['error' => 'Estado de cliente inválido'], 422);
        $tenantId = (int) $matches[1];
        $stmt = db()->prepare('UPDATE tenants SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $tenantId]);
        $subscriptionStatus = $status === 'inactive' ? 'suspended' : 'active';
        db()->prepare('UPDATE tenant_subscriptions SET status = :status WHERE tenant_id = :tenant_id AND status IN ("trial", "active", "suspended")')->execute(['status' => $subscriptionStatus, 'tenant_id' => $tenantId]);
        platform_audit($actor, $tenantId, 'cliente.estado', 'Cliente marcado como ' . humanize_code_label($status));
        json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('#^/platform/tenants/(\d+)/subscription$#', $path, $matches)) {
        $actor = require_platform_admin();
        $data = request_json();
        require_fields($data, ['plan_code', 'status', 'starts_on']);
        $validStatuses = ['trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired'];
        if (!in_array((string) $data['status'], $validStatuses, true)) json_response(['error' => 'Estado de suscripción inválido'], 422);
        $plan = one_row('SELECT id FROM subscription_plans WHERE code = :code AND status = "active" LIMIT 1', ['code' => $data['plan_code']]);
        if (!$plan) json_response(['error' => 'Plan no encontrado'], 404);
        $tenantId = (int) $matches[1];
        $existing = one_row('SELECT id FROM tenant_subscriptions WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT 1', ['tenant_id' => $tenantId]);
        $fields = ['tenant_id' => $tenantId, 'plan_id' => $plan['id'], 'status' => $data['status'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'auto_renew' => !empty($data['auto_renew']) ? 1 : 0, 'external_reference' => trim((string) ($data['external_reference'] ?? '')) ?: null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'created_by' => $actor['id']];
        if ($existing) {
            db()->prepare('UPDATE tenant_subscriptions SET plan_id = :plan_id, status = :status, starts_on = :starts_on, ends_on = :ends_on, auto_renew = :auto_renew, external_reference = :external_reference, notes = :notes, created_by = :created_by WHERE id = :id AND tenant_id = :tenant_id')->execute($fields + ['id' => $existing['id']]);
        } else {
            db()->prepare('INSERT INTO tenant_subscriptions (tenant_id, plan_id, status, starts_on, ends_on, auto_renew, external_reference, notes, created_by) VALUES (:tenant_id, :plan_id, :status, :starts_on, :ends_on, :auto_renew, :external_reference, :notes, :created_by)')->execute($fields);
        }
        platform_audit($actor, $tenantId, 'suscripcion.actualizada', 'Plan ' . $data['plan_code'] . ' / ' . humanize_code_label((string) $data['status']));
        json_response(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/dashboard') {
        require_tenant_wide_scope();
        $tenantId = tenant_id();
        $pdo = db();
        $counts = [];
        foreach ([
            'people_inside' => 'SELECT COUNT(*) FROM visits WHERE tenant_id = :tenant_id AND status = "inside"',
            'vehicles_inside' => 'SELECT COUNT(*) FROM vehicles WHERE tenant_id = :tenant_id AND status = "inside"',
            'packages_pending' => 'SELECT COUNT(*) FROM packages WHERE tenant_id = :tenant_id AND status = "pending"',
            'incidents_open' => 'SELECT COUNT(*) FROM incidents WHERE tenant_id = :tenant_id AND status = "open"',
        ] as $key => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['tenant_id' => $tenantId]);
            $counts[$key] = (int) $stmt->fetchColumn();
        }
        json_response(['data' => $counts]);
    }

    json_response(['error' => 'Ruta no encontrada', 'path' => $path], 404);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(sprintf(
        '[Asiscontrol] API error [%s] %s %s: %s',
        $apiStage,
        $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        $_SERVER['REQUEST_URI'] ?? $path,
        $exception->getMessage()
    ));
    $config = require dirname(__DIR__) . '/config/config.php';
    $payload = [
        'error' => 'Error interno',
        'request_id' => $_SERVER['ASISCONTROL_REQUEST_ID'] ?? null,
    ];
    if (str_starts_with($apiStage, 'login.')) {
        // A coarse phase is safe to expose and lets hosting support identify
        // whether the failure is configuration, the user query, auditing, or
        // the PHP session without leaking SQL or credentials.
        $payload['error_code'] = 'LOGIN_' . strtoupper(str_replace('.', '_', substr($apiStage, 6)));
    }
    if (($config['app_env'] ?? 'local') === 'local' || !empty($config['app_debug'])) {
        $payload['detail'] = $exception->getMessage();
    }
    json_response($payload, 500);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64);
}

function evaluate_access_context(?int $accessPointId, array $data): array
{
    if (!$accessPointId) return ['status' => 'accepted', 'metadata' => []];
    $point = one_row('SELECT latitude, longitude, geofence_radius_m, allowed_networks_json FROM access_points WHERE id = :id LIMIT 1', ['id' => $accessPointId]);
    if (!$point) return ['status' => 'accepted', 'metadata' => []];
    $metadata = [];
    $violations = [];
    $networks = json_decode((string) ($point['allowed_networks_json'] ?? '[]'), true);
    $networks = is_array($networks) ? array_values(array_filter(array_map('strval', $networks))) : [];
    if ($networks && !in_array(client_ip(), $networks, true) && !in_array('*', $networks, true)) {
        $ipAllowed = false;
        foreach ($networks as $network) {
            if (ip_in_cidr(client_ip(), $network)) { $ipAllowed = true; break; }
        }
        if (!$ipAllowed) {
            $violations[] = 'ip_no_autorizada';
            $metadata['network_validation'] = 'fuera_de_lista';
        }
    }
    if ($point['latitude'] !== null && $point['longitude'] !== null && isset($data['latitude'], $data['longitude']) && is_numeric($data['latitude']) && is_numeric($data['longitude'])) {
        $distance = haversine_meters((float) $point['latitude'], (float) $point['longitude'], (float) $data['latitude'], (float) $data['longitude']);
        $radius = max(25, (int) ($point['geofence_radius_m'] ?: 150));
        $metadata['geofence_distance_m'] = round($distance, 1);
        $metadata['geofence_radius_m'] = $radius;
        if ($distance > $radius) $violations[] = 'fuera_de_geocerca';
    }
    if (!$violations) return ['status' => 'accepted', 'metadata' => $metadata];
    $config = require dirname(__DIR__) . '/config/config.php';
    $enforcement = (string) ($config['geofence']['enforcement'] ?? 'review');
    $metadata['context_violations'] = $violations;
    if ($enforcement === 'block') json_response(['error' => 'El dispositivo está fuera del contexto autorizado', 'violations' => $violations], 403);
    $metadata['system_note'] = 'Contexto fuera de regla; evento enviado a revisión.';
    return ['status' => 'review', 'metadata' => $metadata];
}

function haversine_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $earthRadius * asin(min(1, sqrt($a)));
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipBinary = @inet_pton($ip);
    $subnetBinary = @inet_pton($subnet);
    $bits = (int) $bits;
    if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) return false;
    $maxBits = strlen($ipBinary) * 8;
    if ($bits < 0 || $bits > $maxBits) return false;
    $bytes = intdiv($bits, 8);
    $remaining = $bits % 8;
    if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) return false;
    if ($remaining === 0) return true;
    $mask = chr((0xFF << (8 - $remaining)) & 0xFF);
    return (ord($ipBinary[$bytes]) & ord($mask)) === (ord($subnetBinary[$bytes]) & ord($mask));
}

function assert_login_allowed(string $email): void
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE email = :email
               AND ip_address = :ip_address
               AND success = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->execute(['email' => strtolower($email), 'ip_address' => client_ip()]);
        if ((int) $stmt->fetchColumn() >= 5) {
            json_response(['error' => 'Demasiados intentos. Intenta nuevamente en 15 minutos.'], 429);
        }
    } catch (Throwable $exception) {
        if (!is_missing_table_error($exception, 'login_attempts')) throw $exception;
        error_log('[Asiscontrol] login_attempts aún no existe; continúa el login hasta completar la migración.');
    }
}

function record_login_attempt(string $email, bool $success, ?int $userId = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO login_attempts (email, user_id, ip_address, user_agent, location, success)
             VALUES (:email, :user_id, :ip_address, :user_agent, :location, :success)'
        );
        $stmt->execute([
            'email' => strtolower($email),
            'user_id' => $userId ?: null,
            'ip_address' => client_ip(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 4000) ?: null,
            'location' => substr((string) ($_SERVER['HTTP_X_ASISCONTROL_LOCATION'] ?? ''), 0, 180) ?: null,
            'success' => $success ? 1 : 0,
        ]);
    } catch (Throwable $exception) {
        if (is_missing_table_error($exception, 'login_attempts')) {
            error_log('[Asiscontrol] no se pudo registrar login_attempts porque la tabla aún no existe.');
            return;
        }
        // Compatibility with installations that have the table but have not
        // yet applied the QA metadata columns.
        try {
            db()->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip_address, :success)')->execute([
                'email' => strtolower($email), 'ip_address' => client_ip(), 'success' => $success ? 1 : 0,
            ]);
        } catch (Throwable) {
            throw $exception;
        }
    }
}

function is_missing_table_error(Throwable $exception, string $table): bool
{
    $message = strtolower($exception->getMessage());
    return str_contains($message, '1146')
        || (str_contains($message, 'base table or view not found') && str_contains($message, strtolower($table)));
}

function create_portal_login_request(string $email): ?string
{
    $resident = portal_resident_by_email($email);
    if (!$resident) {
        return null;
    }

    $token = bin2hex(random_bytes(24));
    $stmt = db()->prepare(
        'INSERT INTO portal_login_tokens (tenant_id, resident_id, token_hash, expires_at)
         VALUES (:tenant_id, :resident_id, :token_hash, DATE_ADD(NOW(), INTERVAL 20 MINUTE))'
    );
    $stmt->execute([
        'tenant_id' => $resident['tenant_id'],
        'resident_id' => $resident['resident_id'],
        'token_hash' => hash('sha256', $token),
    ]);

    $notify = db()->prepare(
        'INSERT INTO notifications (tenant_id, channel, recipient, subject, body)
         VALUES (:tenant_id, "email", :recipient, :subject, :body)'
    );
    $notify->execute([
        'tenant_id' => $resident['tenant_id'],
        'recipient' => $resident['email'],
        'subject' => 'Acceso al portal Asiscontrol',
        'body' => "Tu token de acceso al portal es: {$token}\nVence en 20 minutos.",
    ]);

    return $token;
}

function verify_portal_login(string $token): array
{
    $tokenHash = hash('sha256', trim($token));
    $login = one_row(
        'SELECT *
         FROM portal_login_tokens
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1',
        ['token_hash' => $tokenHash]
    );

    if (!$login) {
        json_response(['error' => 'Token invalido o vencido'], 422);
    }

    $resident = portal_resident_by_id((int) $login['resident_id'], (int) $login['tenant_id']);
    if (!$resident) {
        json_response(['error' => 'Residente inactivo o no encontrado'], 422);
    }

    $stmt = db()->prepare('UPDATE portal_login_tokens SET used_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $login['id']]);

    return $resident;
}

function portal_resident_by_email(string $email): ?array
{
    return one_row(
        'SELECT r.id, r.id AS resident_id, r.tenant_id, r.unit_id, r.relationship, r.is_primary,
                p.id AS person_id, p.full_name, p.email, p.phone,
                u.site_id, u.tower, u.unit_code, u.floor, s.name AS site_name, t.name AS tenant_name
         FROM residents r
         INNER JOIN people p ON p.id = r.person_id
         INNER JOIN units u ON u.id = r.unit_id
         INNER JOIN sites s ON s.id = u.site_id
         INNER JOIN tenants t ON t.id = r.tenant_id
         WHERE LOWER(p.email) = LOWER(:email) AND r.status = "active" AND u.status = "active"
         LIMIT 1',
        ['email' => trim($email)]
        );
    }

    if ($method === 'GET' && $path === '/reports/attendance.html') {
        require_permission('attendance.view');
        html_attendance_report(tenant_id(), [
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'employee_id' => $_GET['employee_id'] ?? null,
        ]);
    }

function portal_resident_by_id(int $residentId, int $tenantId): ?array
{
    return one_row(
        'SELECT r.id AS resident_id, r.tenant_id, r.unit_id, r.relationship, r.is_primary,
                p.id AS person_id, p.full_name, p.email, p.phone,
                u.site_id, u.tower, u.unit_code, u.floor, s.name AS site_name, t.name AS tenant_name
         FROM residents r
         INNER JOIN people p ON p.id = r.person_id
         INNER JOIN units u ON u.id = r.unit_id
         INNER JOIN sites s ON s.id = u.site_id
         INNER JOIN tenants t ON t.id = r.tenant_id
         WHERE r.id = :resident_id AND r.tenant_id = :tenant_id AND r.status = "active" AND u.status = "active"
         LIMIT 1',
        ['resident_id' => $residentId, 'tenant_id' => $tenantId]
    );
}

function portal_resident_destination(array $resident): string
{
    return trim(trim((string) ($resident['tower'] ?? '')) . ' - ' . (string) $resident['unit_code'], ' -');
}

function portal_packages_for_resident(array $resident): array
{
    $unitLike = '%' . $resident['unit_code'] . '%';
    $destination = portal_resident_destination($resident);
    $destinationLike = '%' . $destination . '%';
    $params = [
        'tenant_id' => $resident['tenant_id'],
        'unit_like' => $unitLike,
        'destination_like' => $destinationLike,
    ];
    $where = '(recipient LIKE :destination_like OR recipient LIKE :unit_like OR destination LIKE :destination_like)';

    if (trim((string) ($resident['tower'] ?? '')) !== '') {
        $where = '((recipient LIKE :unit_like AND destination LIKE :tower_like) OR recipient LIKE :destination_like OR destination LIKE :destination_like)';
        $params['tower_like'] = '%' . $resident['tower'] . '%';
    }

    return list_rows(
        'SELECT tracking, courier, recipient, destination, received_at, delivered_at, status
         FROM packages
         WHERE tenant_id = :tenant_id AND ' . $where . '
         ORDER BY received_at DESC
         LIMIT 80',
        $params
    );
}

function portal_system_user_id(int $tenantId): int
{
    $user = one_row(
        'SELECT id
         FROM users
         WHERE tenant_id = :tenant_id AND is_active = 1 AND role IN ("superadmin", "admin")
         ORDER BY FIELD(role, "superadmin", "admin"), id
         LIMIT 1',
        ['tenant_id' => $tenantId]
    );

    if (!$user) {
        $user = one_row('SELECT id FROM users WHERE tenant_id = :tenant_id AND is_active = 1 ORDER BY id LIMIT 1', ['tenant_id' => $tenantId]);
    }

    if (!$user) {
        json_response(['error' => 'No existe usuario del sistema para crear invitaciones'], 422);
    }

    return (int) $user['id'];
}

function upsert_person(PDO $pdo, int $tenantId, array $data): int
{
    $stmt = $pdo->prepare('SELECT id FROM people WHERE tenant_id = :tenant_id AND document_type = :document_type AND document_number = :document_number AND record_status = "active" ORDER BY version_no DESC LIMIT 1');
    $stmt->execute([
        'tenant_id' => $tenantId,
        'document_type' => $data['document_type'],
        'document_number' => $data['document_number'],
    ]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE people SET person_type = :person_type, full_name = :full_name, phone = :phone, email = :email, company = :company, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $existing,
            'tenant_id' => $tenantId,
            'person_type' => $data['person_type'] ?? 'visitante',
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'company' => $data['company'] ?? null,
        ]);
        return (int) $existing;
    }

    $stmt = $pdo->prepare('SELECT id, version_no FROM people WHERE tenant_id = :tenant_id AND document_type = :document_type AND document_number = :document_number ORDER BY version_no DESC LIMIT 1');
    $stmt->execute([
        'tenant_id' => $tenantId,
        'document_type' => $data['document_type'],
        'document_number' => $data['document_number'],
    ]);
    $previous = $stmt->fetch();
    $nextVersion = $previous ? ((int) $previous['version_no'] + 1) : 1;
    $stmt = $pdo->prepare(
        'INSERT INTO people (tenant_id, person_type, document_type, document_number, full_name, phone, email, company, record_status, version_no, supersedes_person_id)
         VALUES (:tenant_id, :person_type, :document_type, :document_number, :full_name, :phone, :email, :company, "active", :version_no, :supersedes_person_id)'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'person_type' => $data['person_type'] ?? 'visitante',
        'document_type' => $data['document_type'],
        'document_number' => $data['document_number'],
        'full_name' => $data['full_name'],
        'phone' => $data['phone'] ?? null,
        'email' => $data['email'] ?? null,
        'company' => $data['company'] ?? null,
        'version_no' => $nextVersion,
        'supersedes_person_id' => $previous ? (int) $previous['id'] : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function list_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function pagination_from_request(): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['per_page'] ?? 50)));
    return [$limit, ($page - 1) * $limit];
}

function one_row(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function visits_for_tenant(int $tenantId, array $filters = []): array
{
    $where = ['v.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    if (!empty($filters['from'])) {
        $where[] = 'v.entered_at >= :from_date';
        $params['from_date'] = $filters['from'] . ' 00:00:00';
    }

    if (!empty($filters['to'])) {
        $where[] = 'v.entered_at <= :to_date';
        $params['to_date'] = $filters['to'] . ' 23:59:59';
    }

    if (!empty($filters['status']) && in_array($filters['status'], ['inside', 'exited'], true)) {
        $where[] = 'v.status = :status';
        $params['status'] = $filters['status'];
    }

    [$limit, $offset] = pagination_from_request();
    return list_rows(
        'SELECT v.*, p.person_type, p.full_name, p.document_type, p.document_number, p.phone, p.company
         FROM visits v
         INNER JOIN people p ON p.id = v.person_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY v.entered_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset,
        $params
    );
}

function packages_for_tenant(int $tenantId): array
{
    [$limit, $offset] = pagination_from_request();
    return list_rows(
        'SELECT p.*, ps.receiver_name AS signature_name, ps.signature_path
         FROM packages p
         LEFT JOIN package_signatures ps ON ps.package_id = p.id
         WHERE p.tenant_id = :tenant_id
         ORDER BY p.received_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset,
        ['tenant_id' => $tenantId]
    );
}

function access_points_for_tenant(int $tenantId): array
{
    return list_rows(
        'SELECT ap.*, s.name AS site_name
         FROM access_points ap
         INNER JOIN sites s ON s.id = ap.site_id
         WHERE ap.tenant_id = :tenant_id
         ORDER BY s.name, ap.code',
        ['tenant_id' => $tenantId]
    );
}

function devices_for_tenant(int $tenantId): array
{
    return list_rows(
        'SELECT d.id, d.tenant_id, d.site_id, d.access_point_id, d.name, d.token_last4,
                d.registered_ip, d.status, d.authorized_at, d.last_seen_at, d.revoked_at,
                s.name AS site_name, ap.name AS access_point_name
         FROM devices d
         INNER JOIN sites s ON s.id = d.site_id
         LEFT JOIN access_points ap ON ap.id = d.access_point_id
         WHERE d.tenant_id = :tenant_id
         ORDER BY d.created_at DESC',
        ['tenant_id' => $tenantId]
    );
}

function booking_resources_for_tenant(int $tenantId): array
{
    return list_rows(
        'SELECT *
         FROM booking_resources
         WHERE tenant_id = :tenant_id AND is_active = 1
         ORDER BY name',
        ['tenant_id' => $tenantId]
    );
}

function bookings_for_tenant(int $tenantId, array $filters = []): array
{
    $where = ['b.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    if (!empty($filters['from'])) {
        $where[] = 'b.ends_at >= :from_date';
        $params['from_date'] = $filters['from'] . ' 00:00:00';
    }

    if (!empty($filters['to'])) {
        $where[] = 'b.starts_at <= :to_date';
        $params['to_date'] = $filters['to'] . ' 23:59:59';
    }

    if (!empty($filters['resource_id'])) {
        $where[] = 'b.resource_id = :resource_id';
        $params['resource_id'] = (int) $filters['resource_id'];
    }

    [$limit, $offset] = pagination_from_request();
    return list_rows(
        'SELECT b.*, r.name AS resource_name, r.color AS resource_color, r.resource_type
         FROM bookings b
         INNER JOIN booking_resources r ON r.id = b.resource_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY b.starts_at ASC
         LIMIT 300',
        $params
    );
}

function ensure_booking_window_available(int $tenantId, int $resourceId, string $startsAt, string $endsAt): void
{
    if (strtotime($startsAt) === false || strtotime($endsAt) === false || strtotime($endsAt) <= strtotime($startsAt)) {
        json_response(['error' => 'Rango de fechas invalido'], 422);
    }

    $resource = one_row(
        'SELECT id FROM booking_resources WHERE id = :id AND tenant_id = :tenant_id AND is_active = 1 LIMIT 1',
        ['id' => $resourceId, 'tenant_id' => $tenantId]
    );

    if (!$resource) {
        json_response(['error' => 'Recurso no encontrado'], 404);
    }

    $conflict = one_row(
        'SELECT id
         FROM bookings
         WHERE tenant_id = :tenant_id
           AND resource_id = :resource_id
           AND status NOT IN ("cancelled", "expired")
           AND starts_at < :ends_at
           AND ends_at > :starts_at
         LIMIT 1',
        [
            'tenant_id' => $tenantId,
            'resource_id' => $resourceId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]
    );

    if ($conflict) {
        json_response(['error' => 'El horario seleccionado ya esta reservado o bloqueado'], 409);
    }
}

function permissions_for_role(int $tenantId, string $role): array
{
    if ($role === 'superadmin') {
        $actor=require_auth();
        if (!is_platform_admin($actor)) json_response(['error'=>'Rol global no autorizado'],403);
        return array_column(list_rows('SELECT code FROM permissions ORDER BY code'), 'code');
    }
    return effective_role_permissions($tenantId,$role);
}

function permissions_matrix(int $tenantId): array
{
    $permissions = list_rows('SELECT code, name, description FROM permissions ORDER BY code');
    $grants = list_rows(
        'SELECT role, permission_code, granted
         FROM role_permissions
         WHERE tenant_id = :tenant_id
         ORDER BY role, permission_code',
        ['tenant_id' => $tenantId]
    );
    $byRole = [];
    foreach ($grants as $grant) {
        if ((int) $grant['granted'] === 1) {
            $byRole[$grant['role']][] = $grant['permission_code'];
        }
    }

    return ['permissions' => $permissions, 'roles' => $byRole];
}

function replace_role_permissions(int $tenantId, string $role, array $permissions): void
{
    if ($role === 'superadmin') json_response(['error'=>'Permisos globales no modificables por tenant'],403);
    $validPermissions = array_column(list_rows('SELECT permission_code FROM master_role_permissions WHERE role_code=:role', ['role'=>$role]), 'permission_code');
    $selected = array_values(array_intersect($validPermissions, array_map('strval', $permissions)));
    $pdo = db();
    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE FROM role_permissions WHERE tenant_id = :tenant_id AND role = :role');
    $delete->execute(['tenant_id' => $tenantId, 'role' => $role]);

    if ($selected !== []) {
        $insert = $pdo->prepare(
            'INSERT INTO role_permissions (tenant_id, role, permission_code, granted)
             VALUES (:tenant_id, :role, :permission_code, 1)'
        );
        foreach ($selected as $permission) {
            $insert->execute(['tenant_id' => $tenantId, 'role' => $role, 'permission_code' => $permission]);
        }
    }

    $pdo->commit();
}

function units_for_tenant(int $tenantId): array
{
    return list_rows(
        'SELECT u.*, s.name AS site_name
         FROM units u
         INNER JOIN sites s ON s.id = u.site_id
         WHERE u.tenant_id = :tenant_id
         ORDER BY s.name, u.tower, u.unit_code',
        ['tenant_id' => $tenantId]
    );
}

function residents_for_tenant(int $tenantId): array
{
    return list_rows(
        'SELECT r.*, p.full_name, p.document_type, p.document_number, p.phone, p.email,
                u.tower, u.unit_code, u.floor, s.name AS site_name
         FROM residents r
         INNER JOIN people p ON p.id = r.person_id
         INNER JOIN units u ON u.id = r.unit_id
         INNER JOIN sites s ON s.id = u.site_id
         WHERE r.tenant_id = :tenant_id
         ORDER BY u.tower, u.unit_code, p.full_name',
        ['tenant_id' => $tenantId]
    );
}

function audit_for_tenant(int $tenantId, array $filters = []): array
{
    $where = ['tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    if (!empty($filters['from'])) {
        $where[] = 'created_at >= :from_date';
        $params['from_date'] = $filters['from'] . ' 00:00:00';
    }

    if (!empty($filters['to'])) {
        $where[] = 'created_at <= :to_date';
        $params['to_date'] = $filters['to'] . ' 23:59:59';
    }

    if (!empty($filters['action'])) {
        $where[] = 'action LIKE :action';
        $params['action'] = '%' . $filters['action'] . '%';
    }

    return list_rows(
        'SELECT *
         FROM audit_logs
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY created_at DESC
         LIMIT 500',
        $params
    );
}

/**
 * Convierte códigos internos de auditoría y estados en etiquetas legibles.
 * Los códigos originales continúan almacenándose y filtrándose en la API;
 * esta función solo afecta las salidas destinadas a personas.
 */
function humanize_code_label(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }

    $labels = [
        'turno.inicio' => 'Inicio de turno',
        'turno.cierre' => 'Cierre de turno',
        'turno.verificacion' => 'Verificación del puesto',
        'persona.archivada' => 'Persona archivada',
        'visita.ingreso' => 'Ingreso de visitante',
        'visita.salida' => 'Salida de visitante',
        'sede.creada' => 'Sede creada',
        'sede.actualizada' => 'Sede actualizada',
        'punto_acceso.creado' => 'Punto de acceso creado',
        'punto_acceso.actualizado' => 'Punto de acceso actualizado',
        'dispositivo.autorizado' => 'Dispositivo autorizado',
        'dispositivo.revocado' => 'Dispositivo revocado',
        'usuario.creado' => 'Usuario creado',
        'usuario.actualizado' => 'Usuario actualizado',
        'restriccion.creada' => 'Restricción creada',
        'parqueadero.creado' => 'Cupo de parqueadero creado',
        'parqueadero.actualizado' => 'Cupo de parqueadero actualizado',
        'reserva.recurso_creado' => 'Espacio reservable creado',
        'reserva.creada' => 'Reserva creada',
        'reserva.estado' => 'Estado de reserva actualizado',
        'reserva.eliminada' => 'Reserva eliminada',
        'vehiculo.ingreso' => 'Ingreso de vehículo',
        'vehiculo.salida' => 'Salida de vehículo',
        'permisos.actualizados' => 'Permisos actualizados',
        'unidad.creada' => 'Unidad creada',
        'residente.creado' => 'Residente creado',
        'paquete.recibido' => 'Paquete recibido',
        'paquete.entregado' => 'Paquete entregado',
        'paquete.firma' => 'Firma de entrega de paquete',
        'aprobacion.solicitada' => 'Solicitud de aprobación',
        'aprobacion.approved' => 'Solicitud aprobada',
        'aprobacion.rejected' => 'Solicitud rechazada',
        'invitacion.creada' => 'Invitación creada',
        'invitacion.cancelada' => 'Invitación cancelada',
        'invitacion.usada' => 'Invitación utilizada',
        'incidente.creado' => 'Incidente registrado',
        'incidente.cerrado' => 'Incidente cerrado',
        'importacion.csv' => 'Importación de archivo CSV',
        'archivo.subido' => 'Archivo adjunto cargado',
        'acceso.evento' => 'Evento de acceso registrado',
        'offline.conflicto_auto' => 'Conflicto offline resuelto automáticamente',
        'offline.evento_aplicado' => 'Evento offline sincronizado',
        'offline.evento_recibido' => 'Evento offline recibido',
        'offline.conflicto_resuelto' => 'Conflicto offline revisado',
        'backup.solicitado' => 'Backup solicitado',
        'backup.politica_actualizada' => 'Política de backups actualizada',
        'cliente.creado' => 'Cliente creado',
        'cliente.estado' => 'Estado del cliente actualizado',
        'suscripcion.actualizada' => 'Suscripción actualizada',
        'cuenta.activada' => 'Cuenta activada',
        'visit_exception' => 'Excepción de visita',
        'package_release' => 'Liberación de paquete',
        'parking_exception' => 'Excepción de parqueadero',
        'incident_review' => 'Revisión de incidente',
        'active' => 'Activo',
        'inactive' => 'Inactivo',
        'accepted' => 'Aceptado',
        'resolved' => 'Resuelto',
        'used' => 'Utilizada',
        'archived' => 'Archivado',
        'deleted' => 'Eliminado',
        'inside' => 'Dentro',
        'exited' => 'Salió',
        'pending' => 'Pendiente',
        'confirmed' => 'Confirmada',
        'paid' => 'Pagada',
        'cancelled' => 'Cancelada',
        'delivered' => 'Entregado',
        'returned' => 'Devuelto',
        'open' => 'Abierto',
        'in_review' => 'En revisión',
        'closed' => 'Cerrado',
        'approved' => 'Aprobada',
        'rejected' => 'Rechazada',
        'failed' => 'Fallido',
        'sent' => 'Enviado',
        'completed' => 'Completado',
        'queued' => 'En cola',
        'processing' => 'Procesando',
        'synced' => 'Sincronizado',
        'superseded' => 'Reemplazado por una versión más reciente',
        'dismissed' => 'Descartado',
        'trial' => 'Prueba',
        'past_due' => 'Pago vencido',
        'suspended' => 'Suspendido',
        'expired' => 'Vencido',
        'revoked' => 'Revocado',
        'review' => 'En revisión',
        'informative' => 'Informativa',
        'pre_reservation' => 'Pre-reserva',
        'pending_payment' => 'Pendiente de pago',
        'manual_review' => 'Revisión manual',
        'latest_wins' => 'Prevalece el más reciente',
        'block' => 'Bloqueo',
        'warning' => 'Revisión',
        'guard' => 'Guardia de seguridad',
        'supervisor' => 'Supervisor',
        'coordinator' => 'Coordinador',
        'admin' => 'Administrador',
        'superadmin' => 'Administrador principal',
        'auditor' => 'Auditor',
        'monthly' => 'Mensual',
        'annual' => 'Anual',
        'lifetime' => 'Permanente',
        'manual' => 'Manual',
        'daily' => 'Diaria',
        'weekly' => 'Semanal',
        'visit' => 'Visita',
        'vehicle' => 'Vehículo',
        'package' => 'Paquete',
        'incident' => 'Incidente',
        'restrictions' => 'Restricciones',
        'people' => 'Personas',
        'users' => 'Usuarios',
        'technical' => 'Técnico',
        'operational' => 'Operativo',
        'consolidated' => 'Consolidado',
        'con_vigilancia' => 'Con vigilancia',
        'sin_vigilancia' => 'Sin vigilancia',
    ];

    if (isset($labels[$raw])) {
        return $labels[$raw];
    }

    $natural = preg_replace('/[._-]+/', ' ', $raw) ?? $raw;
    $natural = preg_replace('/\s+/', ' ', trim($natural)) ?? trim($natural);
    return $natural === '' ? '—' : ucfirst($natural);
}

function humanize_code_text(?string $value): string
{
    $text = (string) $value;
    // Reuse the canonical labels without exposing internal values in reports.
    $knownCodes = [
        'turno.inicio', 'turno.cierre', 'turno.verificacion', 'persona.archivada',
        'visita.ingreso', 'visita.salida', 'paquete.recibido', 'paquete.entregado',
        'paquete.firma', 'reserva.estado', 'offline.conflicto_auto',
        'offline.evento_aplicado', 'offline.evento_recibido', 'offline.conflicto_resuelto',
        'aprobacion.solicitada', 'aprobacion.approved', 'aprobacion.rejected',
        'importacion.csv', 'archivo.subido', 'acceso.evento', 'backup.solicitado',
        'backup.politica_actualizada', 'cliente.creado', 'cliente.estado',
        'suscripcion.actualizada', 'cuenta.activada', 'people', 'users', 'restrictions',
        'technical', 'operational', 'consolidated', 'con_vigilancia', 'sin_vigilancia',
        'visit', 'vehicle', 'package', 'incident',
        'active', 'inactive', 'inside', 'exited', 'pending', 'confirmed', 'paid',
        'accepted', 'resolved', 'used', 'archived', 'deleted',
        'cancelled', 'delivered', 'open', 'closed', 'approved', 'rejected', 'failed',
        'sent', 'completed', 'queued', 'processing', 'synced', 'superseded', 'dismissed',
        'trial', 'past_due', 'suspended', 'expired', 'revoked', 'review', 'manual_review',
        'latest_wins', 'block', 'warning', 'guard', 'supervisor', 'admin', 'superadmin',
        'auditor', 'monthly', 'annual', 'lifetime', 'manual', 'daily', 'weekly', 'visit_exception', 'package_release',
        'parking_exception', 'incident_review',
    ];
    foreach ($knownCodes as $code) {
        // Evita convertir partes de correos (admin@..., people@...) en etiquetas.
        $pattern = '/(?<![A-Za-z0-9_.@])' . preg_quote($code, '/') . '(?![A-Za-z0-9_.@])/';
        $text = preg_replace_callback($pattern, fn (array $match): string => humanize_code_label($code), $text) ?? $text;
    }
    return $text;
}

function approvals_for_tenant(int $tenantId): array
{
    [$limit, $offset] = pagination_from_request();
    return list_rows(
        'SELECT a.*, requester.name AS requested_by_name, decider.name AS decided_by_name
         FROM approvals a
         LEFT JOIN users requester ON requester.id = a.requested_by
         LEFT JOIN users decider ON decider.id = a.decided_by
         WHERE a.tenant_id = :tenant_id
         ORDER BY a.requested_at DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset,
        ['tenant_id' => $tenantId]
    );
}

function report_summary(int $tenantId): array
{
    $pdo = db();
    $summary = [];
    $queries = [
        'visits_total' => 'SELECT COUNT(*) FROM visits WHERE tenant_id = :tenant_id',
        'visits_inside' => 'SELECT COUNT(*) FROM visits WHERE tenant_id = :tenant_id AND status = "inside"',
        'visits_exited' => 'SELECT COUNT(*) FROM visits WHERE tenant_id = :tenant_id AND status = "exited"',
        'vehicles_total' => 'SELECT COUNT(*) FROM vehicles WHERE tenant_id = :tenant_id',
        'packages_pending' => 'SELECT COUNT(*) FROM packages WHERE tenant_id = :tenant_id AND status = "pending"',
        'incidents_open' => 'SELECT COUNT(*) FROM incidents WHERE tenant_id = :tenant_id AND status = "open"',
    ];

    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $summary[$key] = (int) $stmt->fetchColumn();
    }

    return $summary;
}

function require_query_fields(array $fields): void
{
    foreach ($fields as $field => $value) {
        if (trim((string) $value) === '') {
            json_response(['error' => "Parametro requerido: {$field}"], 422);
        }
    }
}

function files_for_owner(int $tenantId, string $ownerType, int $ownerId): array
{
    return list_rows(
        'SELECT id, owner_type, owner_id, original_name, stored_path, mime_type, byte_size, sha256, captured_at, immutable_at, created_at
         FROM file_attachments
         WHERE tenant_id = :tenant_id AND owner_type = :owner_type AND owner_id = :owner_id
         ORDER BY created_at DESC',
        ['tenant_id' => $tenantId, 'owner_type' => $ownerType, 'owner_id' => $ownerId]
    );
}

function store_attachment(int $tenantId, int $userId, string $ownerType, int $ownerId, array $file): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['error' => 'No fue posible recibir el archivo'], 422);
    }

    $maxBytes = 8 * 1024 * 1024;
    if ((int) $file['size'] > $maxBytes) {
        json_response(['error' => 'El archivo supera 8 MB'], 422);
    }

    $mime = mime_content_type((string) $file['tmp_name']) ?: 'application/octet-stream';
    $allowedImages = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowedImages, true) && $mime !== 'application/pdf') {
        json_response(['error' => 'Tipo de archivo no permitido'], 422);
    }

    $datePath = date('Y/m');
    $storageRoot = dirname(__DIR__) . '/storage/uploads/' . $datePath;
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0755, true) && !is_dir($storageRoot)) {
        json_response(['error' => 'No fue posible crear almacenamiento'], 500);
    }

    $isImage = in_array($mime, $allowedImages, true);
    $safeName = bin2hex(random_bytes(16)) . ($isImage ? '.webp' : '.pdf');
    $target = $storageRoot . '/' . $safeName;

    if ($isImage) {
        convert_image_upload_to_webp((string) $file['tmp_name'], $mime, $target);
        $storedMime = 'image/webp';
    } else {
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            json_response(['error' => 'No fue posible guardar el archivo'], 500);
        }
        $storedMime = 'application/pdf';
    }

    $relativePath = 'storage/uploads/' . $datePath . '/' . $safeName;
    $sha256 = hash_file('sha256', $target) ?: null;
    $stmt = db()->prepare(
        'INSERT INTO file_attachments (tenant_id, owner_type, owner_id, original_name, stored_path, mime_type, byte_size, sha256, uploaded_by, captured_at, immutable_at)
         VALUES (:tenant_id, :owner_type, :owner_id, :original_name, :stored_path, :mime_type, :byte_size, :sha256, :uploaded_by, NOW(), NOW())'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'original_name' => basename((string) $file['name']),
        'stored_path' => $relativePath,
        'mime_type' => $storedMime,
        'byte_size' => filesize($target) ?: (int) $file['size'],
        'sha256' => $sha256,
        'uploaded_by' => $userId,
    ]);

    return (int) db()->lastInsertId();
}

function convert_image_upload_to_webp(string $sourcePath, string $mime, string $targetPath): void
{
    if (!function_exists('imagewebp')) {
        json_response(['error' => 'El servidor no tiene soporte GD WebP habilitado'], 500);
    }

    $image = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($sourcePath),
        'image/png' => imagecreatefrompng($sourcePath),
        'image/webp' => imagecreatefromwebp($sourcePath),
        'image/gif' => imagecreatefromgif($sourcePath),
        default => false,
    };

    if (!$image) {
        json_response(['error' => 'No fue posible procesar la imagen'], 422);
    }

    imagepalettetotruecolor($image);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    if (!imagewebp($image, $targetPath, 82)) {
        imagedestroy($image);
        json_response(['error' => 'No fue posible convertir imagen a WebP'], 500);
    }

    imagedestroy($image);
}

function convert_png_binary_to_webp(string $binary, string $targetPath): void
{
    if (!function_exists('imagewebp')) {
        json_response(['error' => 'El servidor no tiene soporte GD WebP habilitado'], 500);
    }

    $image = imagecreatefromstring($binary);
    if (!$image) {
        json_response(['error' => 'No fue posible procesar la firma'], 422);
    }

    imagepalettetotruecolor($image);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    if (!imagewebp($image, $targetPath, 86)) {
        imagedestroy($image);
        json_response(['error' => 'No fue posible convertir firma a WebP'], 500);
    }

    imagedestroy($image);
}

function store_package_signature(int $tenantId, int $packageId, string $receiverName, string $signatureDataUrl, ?string $observations = null): int
{
    $package = one_row(
        'SELECT id FROM packages WHERE id = :id AND tenant_id = :tenant_id LIMIT 1',
        ['id' => $packageId, 'tenant_id' => $tenantId]
    );
    if (!$package) {
        json_response(['error' => 'Paquete no encontrado'], 404);
    }

    if (!preg_match('#^data:image/png;base64,#', $signatureDataUrl)) {
        json_response(['error' => 'Firma invalida'], 422);
    }

    $binary = base64_decode(substr($signatureDataUrl, strlen('data:image/png;base64,')), true);
    if ($binary === false || strlen($binary) > 1024 * 1024) {
        json_response(['error' => 'Firma demasiado grande o invalida'], 422);
    }

    $datePath = date('Y/m');
    $storageRoot = dirname(__DIR__) . '/storage/signatures/' . $datePath;
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0755, true) && !is_dir($storageRoot)) {
        json_response(['error' => 'No fue posible crear almacenamiento de firmas'], 500);
    }

    $safeName = bin2hex(random_bytes(16)) . '.webp';
    convert_png_binary_to_webp($binary, $storageRoot . '/' . $safeName);
    $relativePath = 'storage/signatures/' . $datePath . '/' . $safeName;

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO package_signatures (tenant_id, package_id, receiver_name, signature_path, signed_at)
         VALUES (:tenant_id, :package_id, :receiver_name, :signature_path, NOW())'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'package_id' => $packageId,
        'receiver_name' => $receiverName,
        'signature_path' => $relativePath,
    ]);
    $signatureId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'UPDATE packages
         SET status = "delivered",
             delivered_at = NOW(),
             delivered_to = :delivered_to,
             delivery_observations = :delivery_observations
         WHERE id = :id AND tenant_id = :tenant_id'
    );
    $stmt->execute([
        'id' => $packageId,
        'tenant_id' => $tenantId,
        'delivered_to' => $receiverName,
        'delivery_observations' => $observations,
    ]);
    $pdo->commit();

    return $signatureId;
}

function download_attachment(int $tenantId, int $fileId): void
{
    $file = one_row(
        'SELECT * FROM file_attachments WHERE id = :id AND tenant_id = :tenant_id LIMIT 1',
        ['id' => $fileId, 'tenant_id' => $tenantId]
    );

    if (!$file) {
        json_response(['error' => 'Archivo no encontrado'], 404);
    }

    $path = dirname(__DIR__) . '/' . $file['stored_path'];
    if (!is_file($path)) {
        json_response(['error' => 'Archivo no disponible'], 404);
    }

    header_remove('Content-Type');
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename((string) $file['original_name']) . '"');
    readfile($path);
    exit;
}

function html_visits_report(int $tenantId, array $filters = []): void
{
    $rows = visits_for_tenant($tenantId, $filters);
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    echo '<title>Reporte de visitas Asiscontrol</title>';
    echo '<style>body{font-family:Arial,sans-serif;color:#172033;margin:32px}h1{font-size:22px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #cbd5e1;padding:7px;text-align:left}th{background:#eef4ff}.meta{color:#667085;margin-bottom:18px}@media print{button{display:none}}</style>';
    echo '</head><body><button onclick="window.print()">Imprimir / guardar PDF</button>';
    echo '<h1>Reporte de visitas</h1><p class="meta">Generado: ' . htmlspecialchars(date('Y-m-d H:i')) . '</p>';
    echo '<table><thead><tr><th>ID</th><th>Persona</th><th>Documento</th><th>Destino</th><th>Autoriza</th><th>Ingreso</th><th>Salida</th><th>Estado</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string) $row['id']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['full_name']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['document_type'] . ' ' . (string) $row['document_number']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['destination']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['host']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['entered_at']) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['exited_at'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(humanize_code_label((string) $row['status'])) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

function html_attendance_report(int $tenantId, array $filters = []): void
{
    $where = ['ar.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    if (!empty($filters['from'])) {
        $where[] = 'ar.occurred_at >= :from_date';
        $params['from_date'] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $where[] = 'ar.occurred_at <= :to_date';
        $params['to_date'] = $filters['to'] . ' 23:59:59';
    }
    if (!empty($filters['employee_id']) && is_numeric($filters['employee_id'])) {
        $where[] = 'ar.person_id = :employee_id';
        $params['employee_id'] = (int) $filters['employee_id'];
    }
    $rows = list_rows(
        'SELECT ar.*, p.full_name, p.document_number, ep.employee_code, s.name AS site_name
         FROM attendance_records ar
         INNER JOIN people p ON p.id = ar.person_id
         INNER JOIN employee_profiles ep ON ep.id = ar.employee_profile_id
         LEFT JOIN sites s ON s.id = ar.site_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ar.occurred_at DESC LIMIT 2000',
        $params
    );
    $summary = ['puntual' => 0, 'tarde' => 0, 'ausencia' => 0, 'incompleto' => 0, 'corregido' => 0];
    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        if (array_key_exists($status, $summary)) $summary[$status]++;
    }
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    echo '<title>Reporte de asistencia Asiscontrol</title>';
    echo '<style>body{font-family:Arial,sans-serif;color:#172033;margin:32px}h1{font-size:22px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #cbd5e1;padding:7px;text-align:left;vertical-align:top}th{background:#eef4ff}.meta{color:#667085;margin-bottom:18px}.summary{display:flex;gap:18px;flex-wrap:wrap;margin:18px 0}.summary span{padding:8px 12px;border:1px solid #d6e4f0;border-radius:8px;background:#f8fbff}@media print{button{display:none}}</style>';
    echo '</head><body><button onclick="window.print()">Imprimir / guardar PDF</button>';
    echo '<h1>Reporte de asistencia</h1><p class="meta">Generado: ' . htmlspecialchars(date('Y-m-d H:i')) . '</p>';
    echo '<div class="summary">';
    foreach ($summary as $status => $count) echo '<span><strong>' . htmlspecialchars(humanize_code_label($status)) . ':</strong> ' . $count . '</span>';
    echo '</div><table><thead><tr><th>Empleado</th><th>Código</th><th>Evento</th><th>Fecha</th><th>Sede</th><th>Estado</th><th>Origen</th><th>Notas</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . htmlspecialchars((string) $row['full_name']) . '<br><small>' . htmlspecialchars((string) $row['document_number']) . '</small></td>';
        echo '<td>' . htmlspecialchars((string) ($row['employee_code'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(humanize_code_label((string) $row['event_type'])) . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['occurred_at']) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['site_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(humanize_code_label((string) $row['status'])) . '</td>';
        echo '<td>' . htmlspecialchars(humanize_code_label((string) $row['source'])) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['notes'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

function html_audit_report(int $tenantId, array $filters = []): void
{
    $rows = audit_for_tenant($tenantId, $filters);
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    echo '<title>Auditoria Asiscontrol</title>';
    echo '<style>body{font-family:Arial,sans-serif;color:#172033;margin:32px}h1{font-size:22px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #cbd5e1;padding:7px;text-align:left;vertical-align:top}th{background:#eef4ff}.meta{color:#667085;margin-bottom:18px}@media print{button{display:none}}</style>';
    echo '</head><body><button onclick="window.print()">Imprimir / guardar PDF</button>';
    echo '<h1>Reporte de auditoria</h1><p class="meta">Generado: ' . htmlspecialchars(date('Y-m-d H:i')) . '</p>';
    echo '<table><thead><tr><th>ID</th><th>Fecha</th><th>Usuario</th><th>Accion</th><th>Detalle</th><th>IP</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string) $row['id']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $row['created_at']) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['user_id'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(humanize_code_label((string) $row['action'])) . '</td>';
        echo '<td>' . htmlspecialchars(humanize_code_text((string) $row['detail'])) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['ip_address'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

function html_invitation_print(int $tenantId, int $invitationId): void
{
    $invitation = one_row(
        'SELECT i.*, s.name AS site_name
         FROM invitations i
         INNER JOIN sites s ON s.id = i.site_id
         WHERE i.id = :id AND i.tenant_id = :tenant_id
         LIMIT 1',
        ['id' => $invitationId, 'tenant_id' => $tenantId]
    );

    if (!$invitation) {
        json_response(['error' => 'Invitacion no encontrada'], 404);
    }

    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    echo '<title>Invitacion Asiscontrol</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f7f9fc;color:#172033;margin:0;padding:32px}.card{max-width:720px;margin:auto;background:#fff;border:1px solid #cbd5e1;border-radius:12px;padding:28px}h1{margin:0 0 8px}.token{word-break:break-all;border:1px dashed #64748b;padding:16px;border-radius:8px;font-family:monospace;font-size:14px;background:#f8fafc}.grid{display:grid;grid-template-columns:160px 1fr;gap:8px;margin:20px 0}.label{font-weight:bold;color:#475569}button{margin-bottom:16px}@media print{button{display:none}body{background:#fff}}</style>';
    echo '</head><body><button onclick="window.print()">Imprimir / guardar PDF</button><section class="card">';
    echo '<h1>Invitacion de ingreso</h1><p>Presentar este token en porteria.</p>';
    echo '<div class="grid">';
    foreach ([
        'Sede' => $invitation['site_name'],
        'Invitado' => $invitation['guest_name'],
        'Documento' => $invitation['guest_document'] ?: 'No registrado',
        'Destino' => $invitation['destination'],
        'Anfitrion' => $invitation['host'],
        'Motivo' => $invitation['reason'],
        'Valida desde' => $invitation['valid_from'],
        'Valida hasta' => $invitation['valid_until'],
        'Estado' => humanize_code_label((string) $invitation['status']),
    ] as $label => $value) {
        echo '<div class="label">' . htmlspecialchars($label) . '</div><div>' . htmlspecialchars((string) $value) . '</div>';
    }
    echo '</div><div class="token">' . htmlspecialchars((string) $invitation['qr_token']) . '</div>';
    echo '</section></body></html>';
    exit;
}

function import_template_csv(string $jobType): void
{
    $templates = [
        'restrictions' => [
            ['document_type', 'document_number', 'level', 'reason'],
            ['CC', '123456789', 'warning', 'Solicitar autorizacion previa'],
        ],
        'people' => [
            ['person_type', 'document_type', 'document_number', 'full_name', 'phone', 'company'],
            ['visitante', 'CC', '123456789', 'Maria Perez', '3000000000', 'Empresa Demo'],
        ],
        'users' => [
            ['name', 'email', 'role', 'password', 'site_id'],
            ['Nuevo Guardia', 'guardia2@asiscontrol.local', 'guard', 'demo1234', '1'],
        ],
    ];

    csv_response('plantilla-' . $jobType . '.csv', $templates[$jobType][0], [$templates[$jobType][1]]);
}

function import_template_xlsx(string $jobType): void
{
    $templates = [
        'restrictions' => [
            ['document_type', 'document_number', 'level', 'reason'],
            ['CC', '123456789', 'warning', 'Solicitar autorizacion previa'],
        ],
        'people' => [
            ['person_type', 'document_type', 'document_number', 'full_name', 'phone', 'company'],
            ['visitante', 'CC', '123456789', 'Maria Perez', '3000000000', 'Empresa Demo'],
        ],
        'users' => [
            ['name', 'email', 'role', 'password', 'site_id'],
            ['Nuevo Guardia', 'guardia2@asiscontrol.local', 'guard', 'demo1234', '1'],
        ],
    ];
    xlsx_response('plantilla-' . $jobType . '.xlsx', $templates[$jobType][0], [$templates[$jobType][1]]);
}

function import_csv(int $tenantId, int $userId, string $jobType, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['error' => 'No fue posible recibir el CSV'], 422);
    }

    $handle = fopen((string) $file['tmp_name'], 'r');
    if (!$handle) {
        json_response(['error' => 'No fue posible leer el CSV'], 422);
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        json_response(['error' => 'CSV vacio'], 422);
    }

    $headers = array_map(static fn ($header) => strtolower(trim((string) $header)), $headers);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return import_rows($tenantId, $userId, $jobType, (string) $file['name'], $headers, $rows);
}

function import_xlsx(int $tenantId, int $userId, string $jobType, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['error' => 'No fue posible recibir el XLSX'], 422);
    }
    [$headers, $rows] = read_xlsx_rows((string) $file['tmp_name']);
    return import_rows($tenantId, $userId, $jobType, (string) $file['name'], $headers, $rows);
}

function import_rows(int $tenantId, int $userId, string $jobType, string $fileName, array $headers, array $rows): array
{
    $headers = array_map(static fn ($header) => strtolower(trim((string) $header)), $headers);
    $processed = 0;
    $errors = [];
    $pdo = db();
    $pdo->beginTransaction();
    if ($jobType==='users') lock_plan_tenant($tenantId);
    $jobId = create_import_job($tenantId, $userId, $jobType, $fileName);

    foreach ($rows as $row) {
        $processed++;
        $data = array_combine($headers, array_pad($row, count($headers), ''));
        if (!is_array($data)) {
            $errors[] = 'Fila ' . $processed . ': columnas invalidas';
            continue;
        }
        try {
            import_csv_row($pdo, $tenantId, $jobType, $data);
        } catch (Throwable $exception) {
            $errors[] = 'Fila ' . $processed . ': ' . $exception->getMessage();
        }
    }

    $status = count($errors) > 0 ? 'failed' : 'completed';
    $stmt = $pdo->prepare(
        'UPDATE import_jobs
         SET status = :status, total_rows = :total_rows, processed_rows = :processed_rows, error_message = :error_message, finished_at = NOW()
         WHERE id = :id AND tenant_id = :tenant_id'
    );
    $stmt->execute([
        'status' => $status,
        'total_rows' => $processed,
        'processed_rows' => $processed - count($errors),
        'error_message' => $errors ? implode("\n", array_slice($errors, 0, 20)) : null,
        'id' => $jobId,
        'tenant_id' => $tenantId,
    ]);
    $pdo->commit();

    return [
        'job_id' => $jobId,
        'status' => $status,
        'total_rows' => $processed,
        'processed_rows' => $processed - count($errors),
        'errors' => $errors,
    ];
}

function create_import_job(int $tenantId, int $userId, string $jobType, string $fileName): int
{
    $stmt = db()->prepare(
        'INSERT INTO import_jobs (tenant_id, user_id, job_type, file_name, status)
         VALUES (:tenant_id, :user_id, :job_type, :file_name, "processing")'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'job_type' => $jobType,
        'file_name' => $fileName,
    ]);

    return (int) db()->lastInsertId();
}

function import_csv_row(PDO $pdo, int $tenantId, string $jobType, array $data): void
{
    if ($jobType === 'restrictions') {
        foreach (['document_number', 'reason', 'level'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new RuntimeException('Falta ' . $field);
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO restrictions (tenant_id, document_type, document_number, level, reason)
             VALUES (:tenant_id, :document_type, :document_number, :level, :reason)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'],
            'level' => $data['level'],
            'reason' => $data['reason'],
        ]);
        return;
    }

    if ($jobType === 'people') {
        foreach (['document_type', 'document_number', 'full_name'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new RuntimeException('Falta ' . $field);
            }
        }
        upsert_person($pdo, $tenantId, [
            'person_type' => $data['person_type'] ?? 'visitante',
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'],
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
        ]);
        return;
    }

    if ($jobType === 'users') {
        foreach (['name', 'email', 'role'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new RuntimeException('Falta ' . $field);
            }
        }
        validate_role_assignment(require_auth(), $tenantId, (string)$data['role']);
        assert_plan_user_transition($tenantId,(string)$data['role'],true);
        if (in_array((string)$data['role'],['guard','supervisor','coordinator','tech_operator'],true) && empty($data['site_id'])) {
            throw new RuntimeException('Se requiere sede para un usuario operativo');
        }
        if (!empty($data['site_id'])) assert_owned_site((int)$data['site_id'],$tenantId);
        $password = (string) ($data['password'] ?? bin2hex(random_bytes(6)));
        $stmt = $pdo->prepare(
            'INSERT INTO users (tenant_id, site_id, name, email, password_hash, role, is_active)
             VALUES (:tenant_id, :site_id, :name, :email, :password_hash, :role, 1)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'site_id' => $data['site_id'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $data['role'],
        ]);
        $newId=(int)$pdo->lastInsertId();
        $scopeType=empty($data['site_id'])?'tenant':'site';
        $scopeId=$scopeType==='tenant'?$tenantId:(int)$data['site_id'];
        $pdo->prepare('INSERT INTO user_scopes (tenant_id,user_id,scope_type,scope_id,granted_by) VALUES(:tenant,:user,:type,:scope_id,:actor)')
            ->execute(['tenant'=>$tenantId,'user'=>$newId,'type'=>$scopeType,'scope_id'=>$scopeId,'actor'=>require_auth()['id']]);
        return;
    }

    throw new RuntimeException('Tipo de importacion no soportado');
}

function require_role(array $roles): array
{
    $user = require_auth();
    if (!in_array((string) $user['role'], $roles, true)
        || (($user['role'] ?? '') === 'superadmin' && !is_platform_admin($user))) {
        json_response(['error' => 'No autorizado'], 403);
    }

    return $user;
}

function require_permission(string $permission): array
{
    $user = require_auth();
    if ((string) $user['role'] === 'superadmin' && is_platform_admin($user)) {
        return $user;
    }

    if (!in_array($permission, permissions_for_role((int)$user['tenant_id'],(string)$user['role']),true)) {
        json_response(['error' => 'Permiso requerido: ' . $permission], 403);
    }

    return $user;
}

/** Garantiza que los perfiles operativos registren con un turno abierto. */
function active_shift_id_for(array $user, mixed $requested = null): ?int
{
    $tenantId=(int)$user['tenant_id'];
    $shiftId=($requested!==null && is_numeric($requested))?(int)$requested:0;
    $nonOperational=in_array((string)($user['role']??''),['admin','superadmin','auditor','resident'],true);
    if ($nonOperational && $shiftId===0) return null;

    $params=['tenant'=>$tenantId];
    $sql='SELECT id,site_id FROM guard_shifts WHERE tenant_id=:tenant AND ended_at IS NULL';
    if (!$nonOperational) {
        $sql.=' AND user_id=:user';
        $params['user']=(int)$user['id'];
    }
    if ($shiftId>0) { $sql.=' AND id=:shift'; $params['shift']=$shiftId; }
    $sql.=' ORDER BY started_at DESC LIMIT 1';
    $row=one_row($sql,$params);
    if (!$row) json_response(['error'=>'Debes usar un turno activo y autorizado',
        'error_code'=>'ACTIVE_SHIFT_REQUIRED'],422);
    require_tenant_scope('site',(int)$row['site_id']);
    return (int)$row['id'];
}

function is_platform_admin(array $user): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM platform_admins
             WHERE user_id = :user_id AND status = "active"'
        );
        $stmt->execute(['user_id' => (int) ($user['id'] ?? 0)]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        // Allows the API to remain usable while an existing installation is migrating.
        return false;
    }
}

function require_platform_admin(): array
{
    $user = require_auth();
    if (!is_platform_admin($user)) {
        json_response(['error' => 'Acceso exclusivo al panel del proveedor'], 403);
    }

    return $user;
}

function platform_audit(?array $actor, ?int $tenantId, string $action, string $detail, array $metadata = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO platform_audit_logs (actor_user_id, tenant_id, action, detail, metadata_json)
             VALUES (:actor_user_id, :tenant_id, :action, :detail, :metadata_json)'
        );
        $stmt->execute([
            'actor_user_id' => $actor['id'] ?? null,
            'tenant_id' => $tenantId,
            'action' => $action,
            'detail' => substr($detail, 0, 255),
            'metadata_json' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable) {
        // Platform audit must not make an already completed customer operation fail.
    }
}

/**
 * Applies an offline access event when the client sends a complete event envelope.
 * The newest occurred_at wins; older payloads are retained as resolved conflicts.
 */
function apply_offline_access_event(array $user, array $envelope, array $payload): ?array
{
    $eventType = (string) ($payload['event_type'] ?? '');
    if (!in_array($eventType, ['entrada', 'salida', 'salida_administrativa'], true)) {
        return null;
    }

    $occurredAt = (string) ($payload['occurred_at'] ?? $envelope['client_occurred_at'] ?? '');
    if ($occurredAt === '' || strtotime($occurredAt) === false) {
        return null;
    }

    $tenantId = (int) $user['tenant_id'];
    $pdo = db();
    $personId = !empty($payload['person_id']) ? (int) $payload['person_id'] : null;
    if (!$personId && !empty($payload['document_type']) && !empty($payload['document_number'])) {
        $personId = (int) (one_row(
            'SELECT id FROM people WHERE tenant_id = :tenant_id AND document_type = :document_type AND document_number = :document_number AND record_status = "active" ORDER BY version_no DESC LIMIT 1',
            ['tenant_id' => $tenantId, 'document_type' => $payload['document_type'], 'document_number' => $payload['document_number']]
        )['id'] ?? 0);
        $personId = $personId ?: null;
    }

    // Visit events need a stable person. Vehicle exits are reconciled by plate
    // and may legitimately arrive without a person_id in the offline envelope.
    $isVehicleExit = (string) ($envelope['action'] ?? '') === 'vehicle_exit'
        && $eventType === 'salida'
        && trim((string) ($payload['plate'] ?? '')) !== '';
    if (!$personId && !$isVehicleExit) {
        return null;
    }

    $latest = $personId ? one_row(
        'SELECT id, occurred_at FROM access_events
         WHERE tenant_id = :tenant_id AND person_id = :person_id AND event_type = :event_type
         ORDER BY occurred_at DESC, id DESC LIMIT 1',
        ['tenant_id' => $tenantId, 'person_id' => $personId, 'event_type' => $eventType]
    ) : null;
    $localTimestamp = strtotime($occurredAt);
    if ($latest && strtotime((string) $latest['occurred_at']) >= $localTimestamp) {
        $note = 'El evento remoto #' . $latest['id'] . ' ocurrido en ' . $latest['occurred_at'] . ' es más reciente; se conservó como vigente y el evento offline quedó anotado.';
        $stmt = $pdo->prepare(
            'INSERT INTO offline_conflicts (tenant_id, local_event_uuid, local_payload_json, remote_event_id, reason, decision, system_note, status, reviewed_at, resolution_notes)
             VALUES (:tenant_id, :local_event_uuid, :local_payload_json, :remote_event_id, :reason, "latest_wins", :system_note, "resolved", NOW(), :resolution_notes)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'local_event_uuid' => strtolower((string) $envelope['event_uuid']),
            'local_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'remote_event_id' => $latest['id'],
            'reason' => 'El servidor tenía un evento más reciente para la misma persona y acción.',
            'system_note' => $note,
            'resolution_notes' => 'Resolución automática: prevalece el evento con mayor occurred_at; revisión manual opcional.',
        ]);
        return ['status' => 'superseded', 'conflict_id' => (int) $pdo->lastInsertId()];
    }

    $siteId = !empty($payload['site_id']) ? (int) $payload['site_id'] : null;
    $accessPointId = !empty($payload['access_point_id']) ? (int) $payload['access_point_id'] : null;
    $deviceId = !empty($payload['device_id']) && is_numeric($payload['device_id']) ? (int) $payload['device_id'] : null;
    if ($siteId && !one_row('SELECT id FROM sites WHERE id = :id AND tenant_id = :tenant_id LIMIT 1', ['id' => $siteId, 'tenant_id' => $tenantId])) json_response(['error' => 'Sede offline no encontrada'], 404);
    if ($accessPointId && !one_row('SELECT id FROM access_points WHERE id = :id AND tenant_id = :tenant_id LIMIT 1', ['id' => $accessPointId, 'tenant_id' => $tenantId])) json_response(['error' => 'Punto de acceso offline no encontrado'], 404);
    if ($deviceId && !one_row('SELECT id FROM devices WHERE id = :id AND tenant_id = :tenant_id AND status = "active" LIMIT 1', ['id' => $deviceId, 'tenant_id' => $tenantId])) json_response(['error' => 'Dispositivo offline no autorizado'], 422);
    $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
    if (!empty($payload['reason'])) $metadata['reason'] = (string) $payload['reason'];
    if (!empty($payload['exit_type'])) $metadata['exit_type'] = (string) $payload['exit_type'];
    $contextCheck = evaluate_access_context($accessPointId, $payload);
    $metadata = array_merge($metadata, $contextCheck['metadata']);
    if ($latest) {
        $metadata['latest_wins_over_event_id'] = (int) $latest['id'];
        $metadata['system_note'] = 'Evento offline más reciente que el último evento remoto; se registra como vigente.';
    }
    $stmt = $pdo->prepare(
        'INSERT INTO access_events (event_uuid, tenant_id, person_id, person_quality, site_id, access_point_id, device_id, operator_user_id, event_type, identification_method, occurred_at, server_received_at, source, idempotency_key, status, metadata_json)
         VALUES (:event_uuid, :tenant_id, :person_id, :person_quality, :site_id, :access_point_id, :device_id, :operator_user_id, :event_type, :identification_method, :occurred_at, NOW(), "offline", :idempotency_key, :status, :metadata_json)'
    );
    $stmt->execute([
        'event_uuid' => strtolower((string) $envelope['event_uuid']),
        'tenant_id' => $tenantId,
        'person_id' => $personId,
        'person_quality' => $payload['person_quality'] ?? null,
        'site_id' => $siteId,
        'access_point_id' => $accessPointId,
        'device_id' => $deviceId,
        'operator_user_id' => $user['id'],
        'event_type' => $eventType,
        'identification_method' => in_array(($payload['identification_method'] ?? 'manual'), ['huella', 'cedula', 'manual', 'qr', 'otro'], true) ? $payload['identification_method'] : 'manual',
        'occurred_at' => $occurredAt,
        'idempotency_key' => substr((string) $envelope['idempotency_key'], 0, 64),
        'status' => $contextCheck['status'],
        'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $accessEventId = (int) $pdo->lastInsertId();

    // The web client queues visitor registrations as access events while
    // offline. Mirror an entry into the operational visits table when the
    // payload has enough information, so it appears in the normal dashboard
    // after synchronization without issuing a second create request.
    if ((string) ($envelope['action'] ?? '') === 'visit' && $eventType === 'entrada') {
        $shiftId = !empty($payload['shift_id']) && is_numeric($payload['shift_id']) ? (int) $payload['shift_id'] : 0;
        if (!$shiftId) {
            $shift = one_row('SELECT id FROM guard_shifts WHERE tenant_id = :tenant_id AND user_id = :user_id AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1', ['tenant_id' => $tenantId, 'user_id' => $user['id']]);
            $shiftId = (int) ($shift['id'] ?? 0);
        }
        if ($shiftId > 0 && !empty($payload['destination']) && !empty($payload['host']) && !empty($payload['reason'])) {
            $pdo->prepare('INSERT INTO visits (tenant_id, person_id, shift_id, site_id, access_point_id, device_id, operator_user_id, identification_method, destination, host, reason, vehicle_plate, entered_at, status) VALUES (:tenant_id, :person_id, :shift_id, :site_id, :access_point_id, :device_id, :operator_user_id, :identification_method, :destination, :host, :reason, :vehicle_plate, :entered_at, "inside")')->execute([
                'tenant_id' => $tenantId, 'person_id' => $personId, 'shift_id' => $shiftId, 'site_id' => $siteId, 'access_point_id' => $accessPointId, 'device_id' => $deviceId, 'operator_user_id' => $user['id'], 'identification_method' => in_array(($payload['identification_method'] ?? 'manual'), ['huella', 'cedula', 'manual', 'qr', 'otro'], true) ? $payload['identification_method'] : 'manual', 'destination' => (string) $payload['destination'], 'host' => (string) $payload['host'], 'reason' => (string) $payload['reason'], 'vehicle_plate' => trim((string) ($payload['vehicle_plate'] ?? '')) ?: null, 'entered_at' => $occurredAt,
            ]);
        }
    }
    if ((string) ($envelope['action'] ?? '') === 'visit_exit' && $eventType === 'salida' && $personId) {
        $visit = !empty($payload['visit_id'])
            ? one_row('SELECT id FROM visits WHERE id=:id AND tenant_id=:tenant_id AND person_id=:person_id AND status="inside" LIMIT 1', ['id'=>(int)$payload['visit_id'],'tenant_id'=>$tenantId,'person_id'=>$personId])
            : one_row('SELECT id FROM visits WHERE tenant_id = :tenant_id AND person_id = :person_id AND status = "inside" ORDER BY entered_at DESC, id DESC LIMIT 1', ['tenant_id' => $tenantId, 'person_id' => $personId]);
        if ($visit) require_scoped_record('visits',(int)$visit['id']);
        if ($visit) {
            $exitType = in_array(($payload['exit_type'] ?? ''), ['administrative', 'administrativa'], true) ? 'administrativa' : 'normal';
            $pdo->prepare('UPDATE visits SET status = "exited", exited_at = :exited_at, exit_observations = :observations, exit_type = :exit_type, exit_reason = :reason, exit_by = :exit_by WHERE id = :id AND tenant_id = :tenant_id')->execute(['exited_at' => $occurredAt, 'observations' => trim((string) ($payload['observations'] ?? '')) ?: null, 'exit_type' => $exitType, 'reason' => trim((string) ($payload['reason'] ?? '')) ?: null, 'exit_by' => $user['id'], 'id' => $visit['id'], 'tenant_id' => $tenantId]);
        }
    }
    if ((string) ($envelope['action'] ?? '') === 'vehicle_exit' && $eventType === 'salida' && trim((string) ($payload['plate'] ?? '')) !== '') {
        $vehicle = !empty($payload['vehicle_id'])
            ? one_row('SELECT id FROM vehicles WHERE id=:id AND tenant_id=:tenant_id AND plate=:plate AND status="inside" LIMIT 1',
                ['id'=>(int)$payload['vehicle_id'],'tenant_id'=>$tenantId,'plate'=>strtoupper(trim((string)$payload['plate']))])
            : one_row('SELECT id FROM vehicles WHERE tenant_id = :tenant_id AND plate = :plate AND status = "inside" ORDER BY entered_at DESC, id DESC LIMIT 1', ['tenant_id' => $tenantId, 'plate' => strtoupper(trim((string) $payload['plate']))]);
        if ($vehicle) require_scoped_record('vehicles',(int)$vehicle['id']);
        if ($vehicle) {
            $exitType = in_array(($payload['exit_type'] ?? ''), ['administrative', 'administrativa'], true) ? 'administrativa' : 'normal';
            $pdo->prepare('UPDATE vehicles SET status = "exited", exited_at = :exited_at, exit_observations = :observations, exit_type = :exit_type, exit_reason = :reason, exit_by = :exit_by WHERE id = :id AND tenant_id = :tenant_id')->execute(['exited_at' => $occurredAt, 'observations' => trim((string) ($payload['observations'] ?? '')) ?: null, 'exit_type' => $exitType, 'reason' => trim((string) ($payload['reason'] ?? '')) ?: null, 'exit_by' => $user['id'], 'id' => $vehicle['id'], 'tenant_id' => $tenantId]);
        }
    }
    return ['status' => $contextCheck['status'], 'event_id' => $accessEventId];
}

function create_password_reset_request(string $email): void
{
    $stmt = db()->prepare(
        'SELECT id, tenant_id, email
         FROM users
         WHERE email = :email AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO password_reset_tokens (tenant_id, user_id, token_hash, expires_at)
         VALUES (:tenant_id, :user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    );
    $stmt->execute([
        'tenant_id' => $user['tenant_id'],
        'user_id' => $user['id'],
        'token_hash' => $tokenHash,
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO notifications (tenant_id, channel, recipient, subject, body)
         VALUES (:tenant_id, "email", :recipient, :subject, :body)'
    );
    $stmt->execute([
        'tenant_id' => $user['tenant_id'],
        'recipient' => $user['email'],
        'subject' => 'Recuperacion de contrasena Asiscontrol',
        'body' => 'Token de recuperacion: ' . $token,
    ]);
    $pdo->commit();
}

function queue_role_notifications(int $tenantId, array $roles, string $subject, string $body): void
{
    if ($roles === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = db()->prepare(
        "SELECT email
         FROM users
         WHERE tenant_id = ? AND is_active = 1 AND role IN ({$placeholders})
         ORDER BY FIELD(role, 'admin', 'superadmin', 'supervisor'), id
         LIMIT 25"
    );
    $stmt->execute(array_merge([$tenantId], $roles));
    $recipients = $stmt->fetchAll();

    if (!$recipients) {
        return;
    }

    $insert = db()->prepare(
        'INSERT INTO notifications (tenant_id, channel, recipient, subject, body)
         VALUES (:tenant_id, "email", :recipient, :subject, :body)'
    );

    foreach ($recipients as $recipient) {
        $insert->execute([
            'tenant_id' => $tenantId,
            'recipient' => $recipient['email'],
            'subject' => $subject,
            'body' => $body,
        ]);
    }
}

function reset_password(string $token, string $password): void
{
    if (strlen($password) < 8) {
        json_response(['error' => 'La contrasena debe tener minimo 8 caracteres'], 422);
    }

    $tokenHash = hash('sha256', $token);
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT *
         FROM password_reset_tokens
         WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute(['token_hash' => $tokenHash]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $pdo->rollBack();
        json_response(['error' => 'Token invalido o vencido'], 422);
    }

    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND tenant_id = :tenant_id');
    $stmt->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id' => $reset['user_id'],
        'tenant_id' => $reset['tenant_id'],
    ]);

    $stmt = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $reset['id']]);
    $pdo->commit();
}

function audit(string $action, string $detail): void
{
    if (empty($_SESSION['user'])) {
        return;
    }

    $user = $_SESSION['user'];
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (tenant_id, user_id, action, detail, ip_address, created_at)
         VALUES (:tenant_id, :user_id, :action, :detail, :ip_address, NOW())'
    );
    $stmt->execute([
        'tenant_id' => $user['tenant_id'],
        'user_id' => $user['id'],
        'action' => $action,
        'detail' => $detail,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function insert_correction(PDO $pdo, int $tenantId, string $entityType, int $entityId, string $field, mixed $previous, mixed $newValue, string $reason, int $userId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO record_corrections (tenant_id, entity_type, entity_id, field_name, previous_value, new_value, reason, corrected_by)
         VALUES (:tenant_id, :entity_type, :entity_id, :field_name, :previous_value, :new_value, :reason, :corrected_by)'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'field_name' => $field,
        'previous_value' => is_scalar($previous) || $previous === null ? ($previous === null ? null : (string) $previous) : json_encode($previous, JSON_UNESCAPED_UNICODE),
        'new_value' => is_scalar($newValue) || $newValue === null ? ($newValue === null ? null : (string) $newValue) : json_encode($newValue, JSON_UNESCAPED_UNICODE),
        'reason' => substr(trim($reason) ?: 'Corrección administrativa', 0, 255),
        'corrected_by' => $userId,
    ]);
}

/** Do not trust site IDs supplied by tenant administrators. */
function assert_owned_site(int $siteId, int $tenantId): void
{
    if (!one_row('SELECT id FROM sites WHERE id = :id AND tenant_id = :tenant_id', ['id' => $siteId, 'tenant_id' => $tenantId])) {
        json_response(['error' => 'Sede fuera del cliente'], 403);
    }
}
function is_platform_admin_target(int $userId): bool
{
    return (bool) one_row('SELECT user_id FROM platform_admins WHERE user_id = :id', ['id' => $userId]);
}
