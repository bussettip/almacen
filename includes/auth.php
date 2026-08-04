<?php
require_once __DIR__ . '/../config.php';
// Verificar autenticacion
if (!isset($_SESSION['usuario_id'])) {
    redirect('login.php');
}

// Obtener datos del usuario actual
$stmt = $pdo->prepare("SELECT u.*, r.nombre as rol_nombre FROM usuarios u JOIN roles r ON u.rol_id = r.id WHERE u.id = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$usuario = $stmt->fetch();

if (!$usuario || !$usuario['activo']) {
    session_destroy();
    redirect('login.php');
}

// Verificar acceso a almacen si aplica
function verificarAccesoAlmacen($pdo, $almacen_id) {
    global $usuario;
    if ($usuario['rol_nombre'] === 'admin') return true;
    if ($usuario['almacen_id'] === null) return true;
    if ($usuario['almacen_id'] == $almacen_id) return true;
    redirect('dashboard.php');
}

function verificarPermiso($pagina) {
    global $usuario;
    $mapa = [
        'almacenes'     => ['admin','almacen'],
        'productos'     => ['admin','almacen'],
        'inventario'    => ['admin','almacen'],
        'ubicaciones'   => ['admin','almacen'],
        'categorias'    => ['admin','almacen'],
        'movimientos'   => ['admin','almacen'],
        'entrada'       => ['admin','almacen'],
        'salida'        => ['admin','almacen'],
        'transferencia' => ['admin','almacen'],
        'scanner'       => ['admin','almacen'],
        'qr'            => ['admin','almacen'],
        'compras'       => ['admin','comercial'],
        'ventas'        => ['admin','comercial'],
        'impuestos'     => ['admin','comercial'],
        'facturas_sat'  => ['admin','comercial'],
        'proveedores'   => ['admin','comercial'],
        'clientes'      => ['admin','comercial'],
        'ordenes'       => ['admin','comercial'],
        'tienda'        => ['admin','comercial','cliente'],
        'importaciones' => ['admin','internacional'],
        'exportaciones' => ['admin','internacional'],
        'bancos'        => ['admin','comercial'],
        'empleados'     => ['admin'],
        'usuarios'      => ['admin'],
        'dashboard'     => ['admin','almacen','comercial','internacional'],
    ];
    $roles = $mapa[$pagina] ?? ['admin'];
    $rol = $usuario['rol_nombre'] ?? '';
    if (!in_array($rol, $roles)) {
        alert('danger', 'Acceso denegado');
        redirect('dashboard.php');
    }
}
