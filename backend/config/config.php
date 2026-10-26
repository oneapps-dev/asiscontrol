<?php

declare(strict_types=1);

// Keep this file usable from both Apache/FPM (SetEnv) and CLI scripts. Some
// hosting panels expose variables through $_SERVER/$_ENV instead of getenv().
$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false && isset($_SERVER[$key])) $value = $_SERVER[$key];
    if ($value === false && isset($_ENV[$key])) $value = $_ENV[$key];
    if ($value === false || $value === null) return $default;
    // Hosting instructions are sometimes pasted with typographic quotes
    // (e.g. SetEnv DB_PASS “”). Treat that pair as an empty value rather than
    // attempting authentication with the quote characters as the password.
    $value = trim((string) $value);
    if ($value === '“”' || $value === '‘’') return '';
    return $value;
};

return [
    'app_name' => $env('APP_NAME', 'Asiscontrol'),
    'legal_entity' => $env('LEGAL_ENTITY_NAME', 'Creamox SAS'),
    'app_env' => $env('APP_ENV', 'local'),
    'app_debug' => $env('APP_DEBUG') === '1',
    'app_url' => $env('APP_URL', 'http://localhost'),
    'session_name' => $env('SESSION_NAME', 'asiscontrol_session'),
    'force_https' => $env('FORCE_HTTPS') === '1',
    'secure_cookies' => $env('SECURE_COOKIES') === '1',
    'cookie_samesite' => $env('COOKIE_SAMESITE', 'Lax'),
    // Inactividad máxima de una sesión autenticada (30 min por defecto).
    'session_idle_timeout' => max(300, (int) $env('SESSION_IDLE_TIMEOUT', '1800')),
    'security_headers' => [
        'content_security_policy' => $env('CONTENT_SECURITY_POLICY', "default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"),
        'permissions_policy' => $env('PERMISSIONS_POLICY', 'camera=(self), microphone=(), geolocation=()'),
    ],
    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '3306'),
        'database' => $env('DB_NAME', $env('DB_DATABASE')),
        'username' => $env('DB_USER', $env('DB_USERNAME')),
        'password' => $env('DB_PASS', $env('DB_PASSWORD')),
        'charset' => 'utf8mb4',
    ],
    'smtp' => [
        'host' => $env('SMTP_HOST'),
        'port' => (int) $env('SMTP_PORT', '587'),
        'secure' => $env('SMTP_SECURE', 'tls'),
        'user' => $env('SMTP_USER'),
        'pass' => $env('SMTP_PASS'),
        'from' => $env('SMTP_FROM'),
        'from_name' => $env('SMTP_FROM_NAME', 'Asiscontrol'),
        'debug' => $env('SMTP_DEBUG') === '1',
    ],
    'backup' => [
        'schedule' => $env('BACKUP_SCHEDULE', 'monthly'),
        'retention_days' => max(1, (int) $env('BACKUP_RETENTION_DAYS', '365')),
        'directory' => $env('BACKUP_DIR', dirname(__DIR__) . '/storage/backups'),
        'encryption_required' => $env('BACKUP_ENCRYPTION_REQUIRED') === '1',
    ],
    'geofence' => [
        'default_radius_m' => max(25, (int) $env('DEFAULT_GEOFENCE_RADIUS_M', '150')),
        'enforcement' => $env('GEOFENCE_ENFORCEMENT', 'review'),
    ],
];
