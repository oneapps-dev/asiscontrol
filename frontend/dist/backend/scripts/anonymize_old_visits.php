<?php

declare(strict_types=1);

require dirname(__DIR__) . '/api/bootstrap.php';

$days = (int) ($argv[1] ?? 365);
$apply = in_array('--apply', $argv, true);
$days = max(30, $days);

$stmt = db()->prepare(
    'SELECT p.id, p.tenant_id, p.full_name, p.document_number, MAX(v.exited_at) AS last_exit
     FROM people p
     INNER JOIN visits v ON v.person_id = p.id
     WHERE p.person_type IN ("visitante", "contratista")
       AND p.full_name NOT LIKE "Anonimizado #%"
       AND NOT EXISTS (
         SELECT 1 FROM visits active_v
         WHERE active_v.person_id = p.id AND active_v.status = "inside"
       )
     GROUP BY p.id, p.tenant_id, p.full_name, p.document_number
     HAVING last_exit IS NOT NULL AND last_exit < DATE_SUB(NOW(), INTERVAL :days DAY)'
);
$stmt->bindValue('days', $days, PDO::PARAM_INT);
$stmt->execute();
$people = $stmt->fetchAll();

echo ($apply ? 'Applying' : 'Dry run') . ' anonymization for visits older than ' . $days . ' days.' . PHP_EOL;
echo 'Candidates: ' . count($people) . PHP_EOL;

if (!$apply) {
    foreach (array_slice($people, 0, 20) as $person) {
        echo '#' . $person['id'] . ' ' . $person['full_name'] . ' last_exit=' . $person['last_exit'] . PHP_EOL;
    }
    exit;
}

$pdo = db();
$pdo->beginTransaction();
$update = $pdo->prepare(
    'UPDATE people
     SET full_name = :full_name,
         document_number = :document_number,
         phone = NULL,
         email = NULL,
         company = NULL,
         photo_path = NULL,
         updated_at = NOW()
     WHERE id = :id AND tenant_id = :tenant_id'
);

foreach ($people as $person) {
    $update->execute([
        'full_name' => 'Anonimizado #' . $person['id'],
        'document_number' => 'ANON-' . $person['id'],
        'id' => $person['id'],
        'tenant_id' => $person['tenant_id'],
    ]);
}

$pdo->commit();
echo 'Anonymized: ' . count($people) . PHP_EOL;
