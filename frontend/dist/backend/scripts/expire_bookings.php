<?php

declare(strict_types=1);

/** Marca como vencidas las reservas no pagadas cuyo horario ya terminó. */
require dirname(__DIR__) . '/api/bootstrap.php';

$stmt = db()->prepare(
    'UPDATE bookings
     SET status = "expired"
     WHERE status IN ("pre_reservation", "pending", "pending_payment", "confirmed")
       AND ends_at < NOW()'
);
$stmt->execute();

echo 'Reservas vencidas actualizadas: ' . $stmt->rowCount() . PHP_EOL;
