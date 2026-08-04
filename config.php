<?php
// Conexion a la base de datos
$host = getenv('DB_HOST') ?: 'mysql_db1';
$db   = getenv('DB_NAME') ?: 'control_almacenes';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'Corporativo.2012';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Error de conexion: " . $e->getMessage());
}

session_start();

function redirect($url) {
    header("Location: $url");
    exit;
}

function h($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function moneda($valor) {
    return '$' . number_format($valor, 2);
}

function alert($tipo, $mensaje) {
    $_SESSION['alert'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function showAlert() {
    if (isset($_SESSION['alert'])) {
        $a = $_SESSION['alert'];
        unset($_SESSION['alert']);
        return "<div class='alert alert-{$a['tipo']}'>{$a['mensaje']}</div>";
    }
    return '';
}

function checked($v) {
    return $v ? 'checked' : '';
}
