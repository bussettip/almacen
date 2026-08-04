<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (!$q) { echo json_encode(['ok'=>false,'error'=>'Vacio']); exit; }

$stmt = $pdo->prepare("SELECT id, codigo, nombre, precio_venta, codigo_qr FROM productos WHERE (codigo_qr = ? OR codigo = ?) AND activo = 1 LIMIT 1");
$stmt->execute([$q, $q]);
$p = $stmt->fetch();

if ($p) {
    echo json_encode(['ok'=>true, 'id'=>(int)$p['id'], 'codigo'=>$p['codigo'], 'nombre'=>$p['nombre'], 'precio_venta'=>(float)$p['precio_venta']]);
} else {
    echo json_encode(['ok'=>false, 'error'=>'Producto no encontrado']);
}
