<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';
$db = $config['db'];
$backupDir = (string) ($config['backup']['directory'] ?? dirname(__DIR__) . '/storage/backups');
$retentionDays = (int) ($config['backup']['retention_days'] ?? 365);
$encryptionRequired = !empty($config['backup']['encryption_required']);
$encryptionKey = trim((string) (getenv('BACKUP_ENCRYPTION_KEY') ?: ''));

if ($encryptionRequired && $encryptionKey === '') {
    fwrite(STDERR, 'BACKUP_ENCRYPTION_KEY es obligatorio cuando el cifrado está activado' . PHP_EOL);
    exit(1);
}

if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
    fwrite(STDERR, 'No fue posible crear carpeta de backups' . PHP_EOL);
    exit(1);
}

$file = $backupDir . '/asiscontrol-' . date('Ymd-His') . '.sql.gz';
$command = [
    'mysqldump',
    '--single-transaction',
    '--quick',
    '--skip-lock-tables',
    '--host=' . $db['host'],
    '--port=' . $db['port'],
    '--user=' . $db['username'],
    $db['database'],
];

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$env = $_ENV;
$env['MYSQL_PWD'] = (string) $db['password'];
$process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__), $env);

if (!is_resource($process)) {
    fwrite(STDERR, 'No fue posible ejecutar mysqldump' . PHP_EOL);
    exit(1);
}

fclose($pipes[0]);
$gzip = gzopen($file, 'wb9');
if (!$gzip) {
    fwrite(STDERR, 'No fue posible abrir archivo de backup' . PHP_EOL);
    exit(1);
}

while (!feof($pipes[1])) {
    gzwrite($gzip, fread($pipes[1], 8192));
}

gzclose($gzip);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);

if ($code !== 0) {
    @unlink($file);
    fwrite(STDERR, trim($error) . PHP_EOL);
    exit($code);
}

$storedFile = $file;
if ($encryptionRequired) {
    $encryptedFile = $file . '.enc';
    $encryptCommand = [
        'openssl',
        'enc',
        '-aes-256-cbc',
        '-salt',
        '-pbkdf2',
        '-iter',
        '100000',
        '-pass',
        'env:BACKUP_ENCRYPTION_KEY',
        '-in',
        $file,
        '-out',
        $encryptedFile,
    ];
    $encryptEnv = $_ENV;
    if (($path = getenv('PATH')) !== false) $encryptEnv['PATH'] = $path;
    $encryptEnv['BACKUP_ENCRYPTION_KEY'] = $encryptionKey;
    $encryptProcess = proc_open($encryptCommand, $descriptorSpec, $encryptPipes, dirname(__DIR__), $encryptEnv);
    if (!is_resource($encryptProcess)) {
        @unlink($file);
        fwrite(STDERR, 'No fue posible ejecutar openssl para cifrar el backup' . PHP_EOL);
        exit(1);
    }
    fclose($encryptPipes[0]);
    fclose($encryptPipes[1]);
    $encryptError = stream_get_contents($encryptPipes[2]);
    fclose($encryptPipes[2]);
    $encryptCode = proc_close($encryptProcess);
    if ($encryptCode !== 0) {
        @unlink($file);
        @unlink($encryptedFile);
        fwrite(STDERR, trim($encryptError) . PHP_EOL);
        exit($encryptCode);
    }
    @unlink($file);
    $storedFile = $encryptedFile;
}

echo 'Backup created: ' . $storedFile . PHP_EOL;

// Keep the approved rolling retention window (12 months by default).
$cutoff = time() - ($retentionDays * 86400);
$backupFiles = array_merge(
    glob($backupDir . '/asiscontrol-*.sql.gz') ?: [],
    glob($backupDir . '/asiscontrol-*.sql.gz.enc') ?: []
);
foreach ($backupFiles as $oldFile) {
    if (is_file($oldFile) && filemtime($oldFile) !== false && filemtime($oldFile) < $cutoff) {
        @unlink($oldFile);
        echo 'Backup expired and removed: ' . $oldFile . PHP_EOL;
    }
}
