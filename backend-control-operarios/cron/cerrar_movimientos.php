<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('America/Bogota');

if (date('H:i') < '23:30') {
    fwrite(STDERR, "La tarea solo puede ejecutarse desde las 23:30, hora de Bogota.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

$pdo->exec("SET time_zone = '-05:00'");

$bloqueo = (int) $pdo->query("SELECT GET_LOCK('cerrar_diario_movimientos', 0)")->fetchColumn();
if ($bloqueo !== 1) {
    fwrite(STDERR, "Ya hay otra ejecucion del cierre en curso.\n");
    exit(1);
}

try {
    $sql = <<<'SQL'
INSERT INTO movimientos
    (usuario_id, movimiento, obra, latitud, longitud, fotografia,
     ip_address, dispositivo_id, fecha_hora, notas)
SELECT entrada.usuario_id, 'SALIDA', entrada.obra, entrada.latitud,
       entrada.longitud, entrada.fotografia, entrada.ip_address,
    entrada.dispositivo_id, CURRENT_TIMESTAMP, 'NO REALIZÓ SALIDA'
FROM movimientos AS entrada
WHERE entrada.movimiento = 'ENTRADA'
  AND entrada.fecha_hora >= CURDATE()
  AND entrada.fecha_hora < CURDATE() + INTERVAL 1 DAY
  AND NOT EXISTS (
      SELECT 1
      FROM movimientos AS posterior
      WHERE posterior.usuario_id = entrada.usuario_id
        AND (
            posterior.fecha_hora > entrada.fecha_hora
            OR (
                posterior.fecha_hora = entrada.fecha_hora
                AND posterior.id > entrada.id
            )
        )
  )
SQL;

    $insertados = $pdo->exec($sql);
    fwrite(STDOUT, date('Y-m-d H:i:s') . " - Salidas registradas: {$insertados}\n");
} finally {
    $pdo->query("SELECT RELEASE_LOCK('cerrar_diario_movimientos')");
}