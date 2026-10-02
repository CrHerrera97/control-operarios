<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
$origenesPermitidos = [
    'https://asistencia.rhelectronics.com.co',
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:5500',
    'http://127.0.0.1:5500',
    'null'
];

if (in_array($origen, $origenesPermitidos, true)) {
    header("Access-Control-Allow-Origin: {$origen}");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

class MovimientosController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function registrarMovimiento(
        int $usuarioId,
        string $movimiento,
        string $obra,
        string $latitud,
        string $longitud,
        string $fotografia,
        ?string $ipAddress,
        ?string $dispositivoId
    ): int {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('El ID de usuario no es válido.');
        }

        if (!in_array($movimiento, ['ENTRADA', 'SALIDA'], true)) {
            throw new InvalidArgumentException('El movimiento debe ser ENTRADA o SALIDA.');
        }

        if ($obra === '' || strlen($obra) > 150) {
            throw new InvalidArgumentException('La obra debe tener entre 1 y 150 caracteres.');
        }

        if (!is_numeric($latitud) || (float) $latitud < -90 || (float) $latitud > 90) {
            throw new InvalidArgumentException('La latitud no es válida.');
        }

        if (!is_numeric($longitud) || (float) $longitud < -180 || (float) $longitud > 180) {
            throw new InvalidArgumentException('La longitud no es válida.');
        }

        if ($fotografia === '') {
            throw new InvalidArgumentException('La fotografía es obligatoria.');
        }

        $this->pdo->beginTransaction();

        try {
            $consultaUltimo = $this->pdo->prepare(
                'SELECT movimiento, obra
                 FROM movimientos
                 WHERE usuario_id = :usuario_id
                 ORDER BY fecha_hora DESC, id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $consultaUltimo->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
            $consultaUltimo->execute();
            $ultimoMovimiento = $consultaUltimo->fetch();

            if ($ultimoMovimiento !== false) {
                if ($movimiento === 'ENTRADA' && $ultimoMovimiento['movimiento'] === 'ENTRADA') {
                    throw new InvalidArgumentException(
                        'Ya existe una entrada abierta en la obra "' . $ultimoMovimiento['obra'] . '". Debes registrar primero la salida.'
                    );
                }

                if ($movimiento === 'SALIDA' && $ultimoMovimiento['movimiento'] !== 'ENTRADA') {
                    throw new InvalidArgumentException('No hay una entrada abierta para registrar la salida.');
                }

                if ($movimiento === 'SALIDA' && $ultimoMovimiento['obra'] !== $obra) {
                    throw new InvalidArgumentException(
                        'La salida debe registrarse para la obra de la entrada abierta: "' . $ultimoMovimiento['obra'] . '".'
                    );
                }

                if ($movimiento === 'SALIDA' && $dispositivoId !== null) {
                    $consultaEntrada = $this->pdo->prepare(
                        'SELECT dispositivo_id
                         FROM movimientos
                         WHERE usuario_id = :usuario_id AND movimiento = \'ENTRADA\'
                         ORDER BY fecha_hora DESC, id DESC
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $consultaEntrada->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
                    $consultaEntrada->execute();
                    $entradaAbierta = $consultaEntrada->fetch();

                    if (
                        $entradaAbierta !== false
                        && $entradaAbierta['dispositivo_id'] !== null
                        && !hash_equals((string) $entradaAbierta['dispositivo_id'], $dispositivoId)
                    ) {
                        throw new InvalidArgumentException(
                            'La salida debe registrarse desde el mismo dispositivo donde se registró la entrada.'
                        );
                    }
                }
            } elseif ($movimiento === 'SALIDA') {
                throw new InvalidArgumentException('No hay una entrada previa para registrar la salida.');
            }

            if ($movimiento === 'ENTRADA' && $dispositivoId !== null) {
                $consultaDispositivo = $this->pdo->prepare(
                    'SELECT usuario_id, movimiento
                     FROM movimientos
                     WHERE dispositivo_id = :dispositivo_id
                     ORDER BY fecha_hora DESC, id DESC
                     LIMIT 1
                     FOR UPDATE'
                );
                $consultaDispositivo->bindValue(':dispositivo_id', $dispositivoId, PDO::PARAM_STR);
                $consultaDispositivo->execute();
                $ultimoMovimientoDispositivo = $consultaDispositivo->fetch();

                if (
                    $ultimoMovimientoDispositivo !== false
                    && $ultimoMovimientoDispositivo['movimiento'] === 'ENTRADA'
                    && (int) $ultimoMovimientoDispositivo['usuario_id'] !== $usuarioId
                ) {
                    throw new InvalidArgumentException(
                        'Este dispositivo ya tiene una entrada abierta con otro usuario.'
                    );
                }
            }

            $consulta = $this->pdo->prepare(
                'INSERT INTO movimientos
                         (usuario_id, movimiento, obra, latitud, longitud, fotografia, ip_address, dispositivo_id)
                 VALUES
                         (:usuario_id, :movimiento, :obra, :latitud, :longitud, :fotografia, :ip_address, :dispositivo_id)'
            );

            $consulta->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
            $consulta->bindValue(':movimiento', $movimiento, PDO::PARAM_STR);
            $consulta->bindValue(':obra', $obra, PDO::PARAM_STR);
            $consulta->bindValue(':latitud', $latitud, PDO::PARAM_STR);
            $consulta->bindValue(':longitud', $longitud, PDO::PARAM_STR);
            $consulta->bindValue(':fotografia', $fotografia, PDO::PARAM_LOB);
            $consulta->bindValue(':ip_address', $ipAddress, $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $consulta->bindValue(':dispositivo_id', $dispositivoId, $dispositivoId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $consulta->execute();

            $movimientoId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $movimientoId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listarMovimientos(
        array $filtros,
        int $pagina,
        int $limite,
        bool $todos = false,
        bool $incluirFotografia = true
    ): array {
        $condiciones = [];
        $parametros = [];

        $persona = trim((string) ($filtros['persona'] ?? ''));
        if ($persona !== '') {
            $condiciones[] = 'u.nombre_completo LIKE :persona';
            $parametros[':persona'] = '%' . $persona . '%';
        }

        $tipo = strtoupper(trim((string) ($filtros['tipo'] ?? '')));
        if ($tipo !== '') {
            if (!in_array($tipo, ['ENTRADA', 'SALIDA'], true)) {
                throw new InvalidArgumentException('El tipo de movimiento no es válido.');
            }
            $condiciones[] = 'm.movimiento = :tipo';
            $parametros[':tipo'] = $tipo;
        }

        $obra = trim((string) ($filtros['obra'] ?? ''));
        if ($obra !== '') {
            $condiciones[] = 'm.obra LIKE :obra';
            $parametros[':obra'] = '%' . $obra . '%';
        }

        foreach (['desde', 'hasta'] as $campoFecha) {
            $fecha = trim((string) ($filtros[$campoFecha] ?? ''));
            if ($fecha === '') {
                continue;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $partes) !== 1
                || !checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
                throw new InvalidArgumentException('El rango de fechas no es válido.');
            }

            if ($campoFecha === 'desde') {
                $condiciones[] = 'm.fecha_hora >= :desde';
                $parametros[':desde'] = $fecha . ' 00:00:00';
            } else {
                $condiciones[] = 'm.fecha_hora < :hasta';
                $parametros[':hasta'] = (new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            }
        }

        $where = $condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '';
        $consultaTotal = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM movimientos m
             INNER JOIN usuarios u ON u.id = m.usuario_id' . $where
        );
        $consultaTotal->execute($parametros);
        $total = (int) $consultaTotal->fetchColumn();
        $totalPaginas = (int) ceil($total / $limite);
        $pagina = $todos ? 1 : min(max(1, $pagina), max(1, $totalPaginas));
        $fotografia = $incluirFotografia ? 'TO_BASE64(m.fotografia)' : "''";
        $sql = 'SELECT m.id, m.usuario_id,
                    u.nombre_completo AS persona,
                    m.movimiento AS tipo, m.obra, m.latitud, m.longitud,
                    m.ip_address, m.notas,
                    ' . $fotografia . ' AS fotografia, m.fecha_hora
                FROM movimientos m
                INNER JOIN usuarios u ON u.id = m.usuario_id' . $where . '
                ORDER BY m.fecha_hora DESC, m.id DESC';
        if (!$todos) {
            $offset = ($pagina - 1) * $limite;
            $sql .= ' LIMIT ' . $limite . ' OFFSET ' . $offset;
        }

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($parametros);

        return [
            'movimientos' => $consulta->fetchAll(),
            'pagina' => $pagina,
            'limite' => $limite,
            'total' => $total,
            'totalPaginas' => $totalPaginas
        ];
    }

    public function listarOperariosActivos(): array
    {
        $consulta = $this->pdo->prepare(
            'SELECT m.id, u.nombre_completo AS persona,
                    m.movimiento AS tipo, m.obra, m.latitud, m.longitud, m.fecha_hora
             FROM movimientos m
             INNER JOIN usuarios u ON u.id = m.usuario_id
             WHERE NOT EXISTS (
                 SELECT 1
                 FROM movimientos posterior
                 WHERE posterior.usuario_id = m.usuario_id
                   AND (posterior.fecha_hora > m.fecha_hora
                     OR (posterior.fecha_hora = m.fecha_hora AND posterior.id > m.id))
             )
               AND m.movimiento = \'ENTRADA\'
             ORDER BY m.fecha_hora DESC, m.id DESC'
        );
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function obtenerUltimoMovimientoUsuario(int $usuarioId): ?array
    {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('El ID de usuario no es válido.');
        }

        $consulta = $this->pdo->prepare(
            'SELECT id, usuario_id, movimiento, obra, notas, latitud, longitud,
                    TO_BASE64(fotografia) AS fotografia, fecha_hora
             FROM movimientos
             WHERE usuario_id = :usuario_id
             ORDER BY fecha_hora DESC, id DESC
             LIMIT 1'
        );
        $consulta->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
        $consulta->execute();

        $resultado = $consulta->fetch();
        return $resultado === false ? null : $resultado;
    }

    public function buscarPorId(int $movimientoId): ?array
    {
        if ($movimientoId <= 0) {
            throw new InvalidArgumentException('El ID del movimiento no es válido.');
        }

        $consulta = $this->pdo->prepare(
            'SELECT id, usuario_id, movimiento, obra, notas, latitud, longitud,
                    TO_BASE64(fotografia) AS fotografia, fecha_hora
             FROM movimientos
             WHERE id = :id'
        );
        $consulta->bindValue(':id', $movimientoId, PDO::PARAM_INT);
        $consulta->execute();

        $resultado = $consulta->fetch();
        return $resultado === false ? null : $resultado;
    }

    public function editarMovimiento(int $movimientoId, array $datos): bool
    {
        if ($movimientoId <= 0) {
            throw new InvalidArgumentException('El ID del movimiento no es válido.');
        }

        $campos = [];
        $valores = [':id' => $movimientoId];

        if (array_key_exists('usuario_id', $datos)) {
            $usuarioId = (int) $datos['usuario_id'];
            if ($usuarioId <= 0) {
                throw new InvalidArgumentException('El ID de usuario no es válido.');
            }
            $campos[] = 'usuario_id = :usuario_id';
            $valores[':usuario_id'] = $usuarioId;
        }

        if (array_key_exists('usuario_id', $datos)) {
            $usuarioId = (int) $datos['usuario_id'];
            if ($usuarioId <= 0) {
                throw new InvalidArgumentException('El ID de usuario no es válido.');
            }
            $campos[] = 'usuario_id = :usuario_id';
            $valores[':usuario_id'] = $usuarioId;
        }

        if (array_key_exists('movimiento', $datos)) {
            $movimiento = strtoupper(trim((string) $datos['movimiento']));
            if (!in_array($movimiento, ['ENTRADA', 'SALIDA'], true)) {
                throw new InvalidArgumentException('El movimiento debe ser ENTRADA o SALIDA.');
            }
            $campos[] = 'movimiento = :movimiento';
            $valores[':movimiento'] = $movimiento;
        }

        if (array_key_exists('obra', $datos)) {
            $obra = trim((string) $datos['obra']);
            if ($obra === '' || strlen($obra) > 150) {
                throw new InvalidArgumentException('La obra debe tener entre 1 y 150 caracteres.');
            }
            $campos[] = 'obra = :obra';
            $valores[':obra'] = $obra;
        }

        if (array_key_exists('notas', $datos)) {
            $notas = trim((string) $datos['notas']);
            if (strlen($notas) > 255) {
                throw new InvalidArgumentException('Las notas no pueden superar 255 caracteres.');
            }
            $campos[] = 'notas = :notas';
            $valores[':notas'] = $notas === '' ? null : $notas;
        }

        foreach (['latitud' => [-90, 90], 'longitud' => [-180, 180]] as $campo => $limites) {
            if (array_key_exists($campo, $datos)) {
                $valor = (string) $datos[$campo];
                if (!is_numeric($valor) || (float) $valor < $limites[0] || (float) $valor > $limites[1]) {
                    throw new InvalidArgumentException("La {$campo} no es válida.");
                }
                $campos[] = "{$campo} = :{$campo}";
                $valores[":{$campo}"] = $valor;
            }
        }

        if (array_key_exists('fotografia_base64', $datos)) {
            $fotografia = base64_decode((string) $datos['fotografia_base64'], true);
            if ($fotografia === false || $fotografia === '') {
                throw new InvalidArgumentException('La fotografía base64 no es válida.');
            }
            $campos[] = 'fotografia = :fotografia';
            $valores[':fotografia'] = $fotografia;
        }

        if ($campos === []) {
            throw new InvalidArgumentException('Debes enviar al menos un campo para actualizar.');
        }

        $consulta = $this->pdo->prepare(
            'UPDATE movimientos SET ' . implode(', ', $campos) . ' WHERE id = :id'
        );
        foreach ($valores as $parametro => $valor) {
            $tipo = $valor === null
                ? PDO::PARAM_NULL
                : (is_int($valor) ? PDO::PARAM_INT : ($parametro === ':fotografia' ? PDO::PARAM_LOB : PDO::PARAM_STR));
            $consulta->bindValue($parametro, $valor, $tipo);
        }
        $consulta->execute();

        return $consulta->rowCount() > 0;
    }

    public function eliminarMovimiento(int $movimientoId): bool
    {
        if ($movimientoId <= 0) {
            throw new InvalidArgumentException('El ID del movimiento no es válido.');
        }

        $consulta = $this->pdo->prepare('DELETE FROM movimientos WHERE id = :id');
        $consulta->bindValue(':id', $movimientoId, PDO::PARAM_INT);
        $consulta->execute();

        return $consulta->rowCount() > 0;
    }
}

if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET', 'PUT', 'DELETE'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $autenticacion = new Autenticacion($pdo);
        $controlador = new MovimientosController($pdo);
        $metodo = $_SERVER['REQUEST_METHOD'];
        $movimientoId = (int) ($_GET['id'] ?? 0);

        if ($metodo === 'POST') {
            $usuarioSesion = $autenticacion->exigirSesion();
            if (!isset($_FILES['fotografia']) || $_FILES['fotografia']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Debes enviar una fotografía válida.');
            }
            $fotografia = file_get_contents($_FILES['fotografia']['tmp_name']);
            if ($fotografia === false) {
                throw new InvalidArgumentException('No se pudo leer la fotografía.');
            }
            $movimientoId = $controlador->registrarMovimiento(
                (int) $usuarioSesion['id'],
                strtoupper(trim((string) ($_POST['movimiento'] ?? ''))),
                trim((string) ($_POST['obra'] ?? '')),
                trim((string) ($_POST['latitud'] ?? '')),
                trim((string) ($_POST['longitud'] ?? '')),
                $fotografia,
                $autenticacion->obtenerIpCliente(),
                $autenticacion->obtenerDispositivoId()
            );
            http_response_code(201);
            $respuesta = ['exito' => true, 'mensaje' => 'Movimiento registrado correctamente.', 'movimiento_id' => $movimientoId];
        } elseif ($metodo === 'GET') {
            $usuarioSesion = $autenticacion->exigirSesion();
            if ($movimientoId <= 0) {
                if (($usuarioSesion['rol'] ?? '') === 'ADMIN') {
                    if (($_GET['activos'] ?? '') === '1') {
                        $respuesta = [
                            'exito' => true,
                            'operarios' => $controlador->listarOperariosActivos()
                        ];
                    } else {
                        $limite = (int) ($_GET['limite'] ?? 10);
                        if (!in_array($limite, [10, 30, 50], true)) {
                            $limite = 10;
                        }
                        $respuesta = ['exito' => true] + $controlador->listarMovimientos(
                            $_GET,
                            (int) ($_GET['pagina'] ?? 1),
                            $limite,
                            ($_GET['todos'] ?? '') === '1',
                            ($_GET['fotografia'] ?? '1') !== '0'
                        );
                    }
                    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $respuesta = [
                    'exito' => true,
                    'movimientoActual' => $controlador->obtenerUltimoMovimientoUsuario((int) $usuarioSesion['id'])
                ];
                echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
                exit;
            }

            $resultado = $controlador->buscarPorId($movimientoId);
            if ($resultado === null) {
                http_response_code(404);
                throw new InvalidArgumentException('Movimiento no encontrado.');
            }
            $respuesta = ['exito' => true, 'movimiento' => $resultado];
        } elseif ($metodo === 'PUT') {
            $autenticacion->exigirAdministrador();
            $datos = json_decode(file_get_contents('php://input'), true);
            if (!is_array($datos)) {
                throw new InvalidArgumentException('El cuerpo debe ser un JSON válido.');
            }
            if (!$controlador->editarMovimiento($movimientoId, $datos)) {
                http_response_code(404);
                throw new InvalidArgumentException('Movimiento no encontrado o sin cambios.');
            }
            $respuesta = ['exito' => true, 'mensaje' => 'Movimiento actualizado correctamente.'];
        } else {
            $autenticacion->exigirAdministrador();
            if (!$controlador->eliminarMovimiento($movimientoId)) {
                http_response_code(404);
                throw new InvalidArgumentException('Movimiento no encontrado.');
            }
            $respuesta = ['exito' => true, 'mensaje' => 'Movimiento eliminado correctamente.'];
        }

        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        if (http_response_code() < 400) {
            http_response_code(422);
        }
        echo json_encode([
            'exito' => false,
            'mensaje' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'exito' => false,
            'mensaje' => 'No se pudo registrar el movimiento.'
        ], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        if (http_response_code() < 400) {
            http_response_code(401);
        }
        echo json_encode([
            'exito' => false,
            'mensaje' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
