<?php

declare(strict_types=1);

class Autenticacion
{
    public function __construct(private PDO $pdo)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'httponly' => true,
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax'
            ]);
            session_start();
        }
    }

    public function iniciarSesion(string $usuario, string $contrasena): array
    {
        if ($usuario === '' || $contrasena === '') {
            throw new InvalidArgumentException('El usuario y la contraseña son obligatorios.');
        }

        $consulta = $this->pdo->prepare(
            'SELECT id, usuario, contrasena_hash, nombre_completo, rol
             FROM usuarios
             WHERE usuario = :usuario AND activo = 1'
        );
        $consulta->execute([':usuario' => $usuario]);
        $usuarioEncontrado = $consulta->fetch();

        if ($usuarioEncontrado === false || !password_verify($contrasena, $usuarioEncontrado['contrasena_hash'])) {
            throw new RuntimeException('Credenciales inválidas.');
        }

        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id' => (int) $usuarioEncontrado['id'],
            'usuario' => $usuarioEncontrado['usuario'],
            'nombre_completo' => $usuarioEncontrado['nombre_completo'],
            'rol' => $usuarioEncontrado['rol']
        ];

        return $_SESSION['usuario'];
    }

    public function exigirSesion(): array
    {
        if (!isset($_SESSION['usuario']) || !is_array($_SESSION['usuario'])) {
            http_response_code(401);
            throw new RuntimeException('Debes iniciar sesión para acceder a este endpoint.');
        }

        return $_SESSION['usuario'];
    }

    public function exigirAdministrador(): array
    {
        $usuario = $this->exigirSesion();

        if (($usuario['rol'] ?? '') !== 'ADMIN') {
            http_response_code(403);
            throw new RuntimeException('Solo un administrador puede acceder a este endpoint.');
        }

        return $usuario;
    }
}
