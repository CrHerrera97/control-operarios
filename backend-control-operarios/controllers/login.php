<?php

declare(strict_types=1);

$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
$origenesPermitidos = [
    'https://asistencia.rhelectronics.com.co',
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:5500',
    'http://127.0.0.1:5500',
    'http://127.0.0.1:5501',
    'null'
];

if (in_array($origen, $origenesPermitidos, true)) {
    header("Access-Control-Allow-Origin: {$origen}");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new InvalidArgumentException('El login solo admite solicitudes POST.');
    }

    $datos = $_POST;
    if ($datos === []) {
        $cuerpo = json_decode(file_get_contents('php://input'), true);
        $datos = is_array($cuerpo) ? $cuerpo : [];
    }

    $autenticacion = new Autenticacion($pdo);
    $usuario = $autenticacion->iniciarSesion(
        trim((string) ($datos['usuario'] ?? '')),
        (string) ($datos['contrasena'] ?? '')
    );

    echo json_encode([
        'exito' => true,
        'mensaje' => 'Inicio de sesión correcto.',
        'usuario' => $usuario
    ], JSON_UNESCAPED_UNICODE);
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
        'mensaje' => 'No se pudo iniciar sesión.'
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
