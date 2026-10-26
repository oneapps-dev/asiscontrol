<?php

declare(strict_types=1);

require dirname(__DIR__) . '/api/bootstrap.php';

$databaseDir = dirname(__DIR__) . '/database/migrations';

if (!is_dir($databaseDir)) {
    throw new RuntimeException('No existe la carpeta de migraciones: ' . $databaseDir);
}

$pdo = db();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
      migration VARCHAR(180) PRIMARY KEY,
      applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB'
);

$files = glob($databaseDir . '/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $migration = basename($file);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration');
    $stmt->execute(['migration' => $migration]);

    if ((int) $stmt->fetchColumn() > 0) {
        echo 'skip ' . $migration . PHP_EOL;
        continue;
    }

    $sql = trim((string) file_get_contents($file));
    if ($sql === '') {
        mark_migration($pdo, $migration);
        echo 'empty ' . $migration . PHP_EOL;
        continue;
    }

    try {
        foreach (split_sql_statements($sql) as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $exception) {
                if (!is_tolerable_migration_error($exception)) {
                    throw $exception;
                }
                echo 'notice ' . $migration . ': ' . compact_error($exception->getMessage()) . PHP_EOL;
            }
        }
        mark_migration($pdo, $migration);
        echo 'applied ' . $migration . PHP_EOL;
    } catch (Throwable $exception) {
        echo 'failed ' . $migration . ': ' . $exception->getMessage() . PHP_EOL;
        exit(1);
    }
}

function mark_migration(PDO $pdo, string $migration): void
{
    $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
    $stmt->execute(['migration' => $migration]);
}

function split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $buffer .= $char;

        if (($char === '"' || $char === "'") && ($i === 0 || $sql[$i - 1] !== '\\')) {
            $quote = $quote === $char ? null : ($quote ?? $char);
        }

        if ($char === ';' && $quote === null) {
            $statement = trim(substr($buffer, 0, -1));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function is_tolerable_migration_error(PDOException $exception): bool
{
    $message = strtolower($exception->getMessage());
    foreach ([
        'already exists',
        'duplicate column',
        'duplicate key name',
        'check that column/key exists',
    ] as $needle) {
        if (str_contains($message, $needle)) {
            return true;
        }
    }

    return false;
}

function compact_error(string $message): string
{
    return substr(preg_replace('/\s+/', ' ', $message) ?: $message, 0, 180);
}
