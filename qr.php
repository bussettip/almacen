<?php
// Generador de codigos QR para productos
// Uso: qr.php?data=CODIGO_PRODUCTO
require_once __DIR__ . '/includes/phpqrcode.php';

$data = $_GET['data'] ?? '';
if (!$data) {
    header('Content-Type: image/png');
    QRcode::png('https://github.com/t0k4rt/phpqrcode', false, QR_ECLEVEL_L, 6);
    exit;
}

$size = isset($_GET['size']) ? min(10, max(2, (int)$_GET['size'])) : 6;
$margin = isset($_GET['margin']) ? (int)$_GET['margin'] : 2;

// El contenido del QR sera una URL para cargar el producto
$qr_content = $data;

header('Content-Type: image/png');
QRcode::png($qr_content, false, QR_ECLEVEL_L, $size, $margin);
