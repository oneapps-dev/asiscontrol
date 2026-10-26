<?php

declare(strict_types=1);

/** Carga secretos locales desde un archivo no público cuando está disponible. */
function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

$envCandidates = array_values(array_filter([
    // Explicit path is useful when the document root is frontend/dist and the
    // backend/.env file is kept one level above the published artifact.
    getenv('ASISCONTROL_ENV_FILE') ?: null,
    dirname(__DIR__) . '/.env',
    dirname(dirname(dirname(__DIR__))) . '/backend/.env',
    isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . '/../backend/.env' : null,
]));
foreach ($envCandidates as $envPath) {
    if (is_file($envPath) && is_readable($envPath)) {
        load_env_file($envPath);
        break;
    }
}
$config = require dirname(__DIR__) . '/config/config.php';

// Correlate a browser error with the PHP error log without exposing stack
// traces or database credentials in production responses.
$requestId = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
$requestId = substr($requestId ?: bin2hex(random_bytes(8)), 0, 64);
$_SERVER['ASISCONTROL_REQUEST_ID'] = $requestId;

if (
    PHP_SAPI !== 'cli'
    && !empty($config['force_https'])
    && empty($_SERVER['HTTPS'])
    && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https')
) {
    json_response(['error' => 'HTTPS requerido'], 426);
}

session_name($config['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($config['secure_cookies']) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => $config['cookie_samesite'] ?? 'Lax',
]);
session_start();

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: ' . ($config['security_headers']['permissions_policy'] ?? 'camera=(self), microphone=(), geolocation=()'));
    header('Content-Security-Policy: ' . ($config['security_headers']['content_security_policy'] ?? "default-src 'self'"));
    header('X-Request-Id: ' . $requestId);
    if (!empty($config['force_https'])) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require dirname(__DIR__) . '/config/config.php';
    $db = $config['db'];
    if (trim((string) ($db['database'] ?? '')) === '' || trim((string) ($db['username'] ?? '')) === '') {
        throw new RuntimeException('Faltan DB_NAME/DB_USER en el entorno del backend');
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset']);

    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Some shared MySQL hosts enable ANSI_QUOTES globally. The API's legacy
    // SQL uses double quotes for enum literals, so remove only that mode for
    // this connection to keep login and the remaining endpoints compatible.
    try {
        $pdo->exec("SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'ANSI_QUOTES', '')");
    } catch (Throwable $exception) {
        error_log('[Asiscontrol] no se pudo ajustar sql_mode: ' . $exception->getMessage());
    }

    return $pdo;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csv_response(string $filename, array $headers, array $rows): void
{
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

/**
 * Generates a small, dependency-free XLSX workbook. Cells are emitted as
 * inline strings so exports remain readable in Excel, LibreOffice and Sheets
 * without a shared-strings table.
 */
function xlsx_response(string $filename, array $headers, array $rows): void
{
    if (!class_exists('ZipArchive')) {
        json_response(['error' => 'El servidor no tiene habilitado ZipArchive para exportar XLSX'], 501);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'asiscontrol-xlsx-');
    if ($tmp === false) json_response(['error' => 'No fue posible preparar el archivo XLSX'], 500);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        json_response(['error' => 'No fue posible generar el archivo XLSX'], 500);
    }
    $escape = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $columnName = static function (int $index): string {
        $name = '';
        do {
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);
        return $name;
    };
    $allRows = array_merge([$headers], $rows);
    $sheetRows = [];
    foreach ($allRows as $rowIndex => $row) {
        $cells = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $ref = $columnName($columnIndex) . ($rowIndex + 1);
            $cells[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . $escape($value) . '</t></is></c>';
        }
        $sheetRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Asiscontrol" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>');
    $zip->close();

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** Read the first worksheet of an XLSX upload into [headers, rows]. */
function read_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no tiene habilitado ZipArchive para leer XLSX');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Archivo XLSX invalido');
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    if ($xml === false) throw new RuntimeException('El XLSX no contiene la primera hoja');
    $document = simplexml_load_string($xml);
    if ($document === false) throw new RuntimeException('No fue posible leer la hoja XLSX');
    $document->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $sharedStrings = [];
    if ($sharedStringsXml !== false) {
        $sharedDocument = simplexml_load_string($sharedStringsXml);
        if ($sharedDocument !== false) {
            $sharedDocument->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($sharedDocument->xpath('//x:sst/x:si') ?: [] as $sharedItem) {
                // Excel may split a shared value across several rich-text runs.
                $sharedItem->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $parts = [];
                foreach ($sharedItem->xpath('.//x:t') ?: [] as $text) $parts[] = (string) $text;
                $sharedStrings[] = implode('', $parts);
            }
        }
    }
    $rows = [];
    foreach ($document->xpath('//x:sheetData/x:row') ?: [] as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $type = (string) ($cell['t'] ?? '');
            if ($type === 'inlineStr') {
                $cell->is->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $parts = [];
                foreach ($cell->is->xpath('.//x:t') ?: [] as $text) $parts[] = (string) $text;
                $cells[] = implode('', $parts);
            } elseif ($type === 's') {
                $index = (int) ($cell->v ?? -1);
                $cells[] = $sharedStrings[$index] ?? '';
            } else {
                $cells[] = (string) ($cell->v ?? '');
            }
        }
        $rows[] = $cells;
    }
    if (!$rows) throw new RuntimeException('XLSX vacio');
    $headers = array_map(static fn ($header) => strtolower(trim((string) $header)), array_shift($rows));
    return [$headers, $rows];
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_response(['error' => 'JSON invalido'], 422);
    }

    return $data;
}

function require_auth(): array
{
    if (empty($_SESSION['user'])) {
        json_response(['error' => 'No autenticado'], 401);
    }

    $config = require dirname(__DIR__) . '/config/config.php';
    $lastActivity = (int) ($_SESSION['last_activity'] ?? time());
    if ($lastActivity > 0 && time() - $lastActivity > (int) ($config['session_idle_timeout'] ?? 1800)) {
        unset($_SESSION['user'], $_SESSION['last_activity']);
        json_response(['error' => 'La sesión expiró por inactividad', 'error_code' => 'SESSION_EXPIRED'], 401);
    }
    $_SESSION['last_activity'] = time();

    return $_SESSION['user'];
}

function require_portal_auth(): array
{
    if (empty($_SESSION['portal_resident']) && !empty($_SESSION['user']) && ($_SESSION['user']['role'] ?? '') === 'resident') {
        $user = $_SESSION['user'];
        try {
            $stmt = db()->prepare(
                'SELECT r.id, r.tenant_id, r.unit_id, r.person_id, p.full_name, p.email, p.phone,
                        u.tower, u.unit_code, u.floor, s.id AS site_id, s.name AS site_name
                 FROM residents r INNER JOIN people p ON p.id = r.person_id
                 INNER JOIN units u ON u.id = r.unit_id INNER JOIN sites s ON s.id = u.site_id
                 WHERE r.id = :resident_id AND r.tenant_id = :tenant_id AND r.status = "active" LIMIT 1'
            );
            $stmt->execute(['resident_id' => (int) ($user['resident_id'] ?? 0), 'tenant_id' => (int) $user['tenant_id']]);
            $resident = $stmt->fetch();
            if ($resident) $_SESSION['portal_resident'] = $resident;
        } catch (Throwable) {
            // The regular authentication check below returns the safe error.
        }
    }
    if (empty($_SESSION['portal_resident'])) json_response(['error' => 'Portal no autenticado'], 401);

    $config = require dirname(__DIR__) . '/config/config.php';
    $lastActivity = (int) ($_SESSION['portal_last_activity'] ?? time());
    if ($lastActivity > 0 && time() - $lastActivity > (int) ($config['session_idle_timeout'] ?? 1800)) {
        unset($_SESSION['portal_resident'], $_SESSION['portal_last_activity']);
        json_response(['error' => 'La sesión expiró por inactividad', 'error_code' => 'SESSION_EXPIRED'], 401);
    }
    $_SESSION['portal_last_activity'] = time();
    return $_SESSION['portal_resident'];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || !hash_equals(csrf_token(), $token)) {
        json_response(['error' => 'Token CSRF invalido'], 419);
    }
}

function tenant_id(): int
{
    $user = require_auth();
    return (int) $user['tenant_id'];
}

function require_fields(array $data, array $fields): void
{
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            json_response(['error' => "Campo requerido: {$field}"], 422);
        }
    }
}
