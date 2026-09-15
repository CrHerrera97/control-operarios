<?php

$host = 'localhost';
$dbname = 'rhelectr_control_operarios';
$username = 'rhelectr_sistema';
$password = 'Bruno5326*';
$charset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=$charset",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

}catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}