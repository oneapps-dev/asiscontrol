<?php

declare(strict_types=1);

require dirname(__DIR__) . '/api/bootstrap.php';

$config = require dirname(__DIR__) . '/config/config.php';
$limit = (int) ($argv[1] ?? 20);
$limit = max(1, min($limit, 100));

$stmt = db()->prepare(
    'SELECT *
     FROM notifications
     WHERE status = "queued"
       AND channel = "email"
       AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
     ORDER BY created_at
     LIMIT ' . $limit
);
$stmt->execute();
$notifications = $stmt->fetchAll();

foreach ($notifications as $notification) {
    try {
        mark_notification_attempt((int) $notification['id']);
        send_smtp_mail($config['smtp'], (string) $notification['recipient'], (string) $notification['subject'], (string) $notification['body']);
        $update = db()->prepare('UPDATE notifications SET status = "sent", sent_at = NOW(), next_attempt_at = NULL, error_message = NULL WHERE id = :id');
        $update->execute(['id' => $notification['id']]);
        echo 'sent notification #' . $notification['id'] . PHP_EOL;
    } catch (Throwable $exception) {
        $attemptCount = (int) $notification['attempt_count'] + 1;
        if ($attemptCount >= 5) {
            $update = db()->prepare('UPDATE notifications SET status = "failed", next_attempt_at = NULL, error_message = :error_message WHERE id = :id');
            $update->execute(['id' => $notification['id'], 'error_message' => substr($exception->getMessage(), 0, 255)]);
            echo 'failed notification #' . $notification['id'] . ': ' . $exception->getMessage() . PHP_EOL;
        } else {
            $delayMinutes = min(60, 2 ** $attemptCount);
            $nextAttemptAt = (new DateTimeImmutable('now'))->modify('+' . $delayMinutes . ' minutes')->format('Y-m-d H:i:s');
            $update = db()->prepare(
                'UPDATE notifications
                 SET next_attempt_at = :next_attempt_at,
                     error_message = :error_message
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $notification['id'],
                'next_attempt_at' => $nextAttemptAt,
                'error_message' => substr($exception->getMessage(), 0, 255),
            ]);
            echo 'retry notification #' . $notification['id'] . ' in ' . $delayMinutes . ' minutes: ' . $exception->getMessage() . PHP_EOL;
        }
    }
}

function mark_notification_attempt(int $id): void
{
    $stmt = db()->prepare(
        'UPDATE notifications
         SET attempt_count = attempt_count + 1,
             last_attempt_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute(['id' => $id]);
}

function send_smtp_mail(array $smtp, string $to, string $subject, string $body): void
{
    if (empty($smtp['host']) || empty($smtp['user']) || empty($smtp['pass']) || empty($smtp['from'])) {
        throw new RuntimeException('SMTP no configurado');
    }

    $socket = stream_socket_client((string) $smtp['host'] . ':' . (int) $smtp['port'], $errno, $errstr, 20);
    if (!$socket) {
        throw new RuntimeException('No conecta SMTP: ' . $errstr);
    }

    smtp_expect($socket, [220]);
    smtp_command($socket, 'EHLO asiscontrol.local', [250]);

    if (($smtp['secure'] ?? '') === 'tls') {
        smtp_command($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('No fue posible activar TLS');
        }
        smtp_command($socket, 'EHLO asiscontrol.local', [250]);
    }

    smtp_command($socket, 'AUTH LOGIN', [334]);
    smtp_command($socket, base64_encode((string) $smtp['user']), [334]);
    smtp_command($socket, base64_encode((string) $smtp['pass']), [235]);
    smtp_command($socket, 'MAIL FROM:<' . $smtp['from'] . '>', [250]);
    smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    smtp_command($socket, 'DATA', [354]);

    $headers = [
        'From: ' . encode_header((string) $smtp['from_name']) . ' <' . $smtp['from'] . '>',
        'To: <' . $to . '>',
        'Subject: ' . encode_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $body) . "\r\n.\r\n");
    smtp_expect($socket, [250]);
    smtp_command($socket, 'QUIT', [221]);
    fclose($socket);
}

function smtp_command($socket, string $command, array $expected): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expected);
}

function smtp_expect($socket, array $expected): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP respuesta inesperada: ' . trim($response));
    }

    return $response;
}

function encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}
