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

class UsuariosController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function crearUsuario(
        string $usuario,
        string $contrasena,
        string $nombreCompleto,
        string $rol = 'OPERARIO'
    ): int {
        $this->validarDatos($usuario, $nombreCompleto, $rol);

        if (strlen($contrasena) < 6) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 6 caracteres.');
        }

        $consulta = $this->pdo->prepare(
            'INSERT INTO usuarios (usuario, contrasena_hash, nombre_completo, rol)
             VALUES (:usuario, :contrasena_hash, :nombre_completo, :rol)'
        );
        $consulta->execute([
            ':usuario' => $usuario,
            ':contrasena_hash' => password_hash($contrasena, PASSWORD_DEFAULT),
            ':nombre_completo' => $nombreCompleto,
            ':rol' => $rol
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function listarUsuarios(): array
    {
        $consulta = $this->pdo->prepare(
            'SELECT id, usuario, nombre_completo, rol, activo, creado_en
             FROM usuarios'
        );
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function buscarPorId(int $usuarioId): ?array
    {
        $this->validarId($usuarioId);

        $consulta = $this->pdo->prepare(
            'SELECT id, usuario, nombre_completo, rol, activo, creado_en
             FROM usuarios
             WHERE id = :id'
        );
        $consulta->execute([':id' => $usuarioId]);

        $resultado = $consulta->fetch();
        return $resultado === false ? null : $resultado;
    }

    public function editarUsuario(int $usuarioId, array $datos): bool
    {
        $this->validarId($usuarioId);
        $campos = [];
        $valores = [':id' => $usuarioId];

        if (array_key_exists('usuario', $datos)) {
            $usuario = trim((string) $datos['usuario']);
            if ($usuario === '' || strlen($usuario) > 50) {
                throw new InvalidArgumentException('El usuario debe tener entre 1 y 50 caracteres.');
            }
            $campos[] = 'usuario = :usuario';
            $valores[':usuario'] = $usuario;
        }

        if (array_key_exists('nombre_completo', $datos)) {
            $nombreCompleto = trim((string) $datos['nombre_completo']);
            if ($nombreCompleto === '' || strlen($nombreCompleto) > 150) {
                throw new InvalidArgumentException('El nombre completo no es válido.');
            }
            $campos[] = 'nombre_completo = :nombre_completo';
            $valores[':nombre_completo'] = $nombreCompleto;
        }

        if (array_key_exists('rol', $datos)) {
            $rol = strtoupper(trim((string) $datos['rol']));
            if (!in_array($rol, ['ADMIN', 'OPERARIO'], true)) {
                throw new InvalidArgumentException('El rol debe ser ADMIN u OPERARIO.');
            }
            $campos[] = 'rol = :rol';
            $valores[':rol'] = $rol;
        }

        if (array_key_exists('activo', $datos)) {
            $campos[] = 'activo = :activo';
            $valores[':activo'] = (int) (bool) $datos['activo'];
        }

        if (array_key_exists('contrasena', $datos)) {
            $contrasena = (string) $datos['contrasena'];
            if (strlen($contrasena) < 6) {
                throw new InvalidArgumentException('La contraseña debe tener al menos 6 caracteres.');
            }
            $campos[] = 'contrasena_hash = :contrasena_hash';
            $valores[':contrasena_hash'] = password_hash($contrasena, PASSWORD_DEFAULT);
        }

        if ($campos === []) {
            throw new InvalidArgumentException('Debes enviar al menos un campo para actualizar.');
        }

        $consulta = $this->pdo->prepare(
            'UPDATE usuarios SET ' . implode(', ', $campos) . ' WHERE id = :id'
        );
        $consulta->execute($valores);

        return $consulta->rowCount() > 0;
    }

    public function eliminarUsuario(int $usuarioId): bool
    {
        $this->validarId($usuarioId);

        $consulta = $this->pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $consulta->execute([':id' => $usuarioId]);

        return $consulta->rowCount() > 0;
    }

    private function validarId(int $usuarioId): void
    {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('El ID del usuario no es válido.');
        }
    }

    private function validarDatos(string $usuario, string $nombreCompleto, string $rol): void
    {
        if ($usuario === '' || strlen($usuario) > 50) {
            throw new InvalidArgumentException('El usuario debe tener entre 1 y 50 caracteres.');
        }

        if ($nombreCompleto === '' || strlen($nombreCompleto) > 150) {
            throw new InvalidArgumentException('El nombre completo no es válido.');
        }

        if (!in_array($rol, ['ADMIN', 'OPERARIO'], true)) {
            throw new InvalidArgumentException('El rol debe ser ADMIN u OPERARIO.');
        }
    }
}

if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET', 'PUT', 'DELETE'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $autenticacion = new Autenticacion($pdo);
        $autenticacion->exigirAdministrador();
        $controlador = new UsuariosController($pdo);
        $metodo = $_SERVER['REQUEST_METHOD'];
        $usuarioId = (int) ($_GET['id'] ?? 0);

        if ($metodo === 'POST') {
            $usuarioId = $controlador->crearUsuario(
                trim((string) ($_POST['usuario'] ?? '')),
                (string) ($_POST['contrasena'] ?? ''),
                trim((string) ($_POST['nombre_completo'] ?? '')),
                strtoupper(trim((string) ($_POST['rol'] ?? 'OPERARIO')))
            );
            http_response_code(201);
            $respuesta = [
                'exito' => true,
                'mensaje' => 'Usuario creado correctamente.',
                'usuario_id' => $usuarioId
            ];
        } elseif ($metodo === 'GET') {
            if ($usuarioId <= 0) {
                $respuesta = [
                    'exito' => true,
                    'usuarios' => $controlador->listarUsuarios()
                ];
                echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
                exit;
            }

            $resultado = $controlador->buscarPorId($usuarioId);
            if ($resultado === null) {
                http_response_code(404);
                throw new InvalidArgumentException('Usuario no encontrado.');
            }
            $respuesta = ['exito' => true, 'usuario' => $resultado];
        } elseif ($metodo === 'PUT') {
            $datos = json_decode(file_get_contents('php://input'), true);
            if (!is_array($datos)) {
                throw new InvalidArgumentException('El cuerpo debe ser un JSON válido.');
            }
            if (!$controlador->editarUsuario($usuarioId, $datos)) {
                http_response_code(404);
                throw new InvalidArgumentException('Usuario no encontrado o sin cambios.');
            }
            $respuesta = ['exito' => true, 'mensaje' => 'Usuario actualizado correctamente.'];
        } else {
            if (!$controlador->eliminarUsuario($usuarioId)) {
                http_response_code(404);
                throw new InvalidArgumentException('Usuario no encontrado.');
            }
            $respuesta = ['exito' => true, 'mensaje' => 'Usuario eliminado correctamente.'];
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
            'mensaje' => 'No se pudo procesar el usuario. Verifica que el usuario no esté duplicado.'
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
