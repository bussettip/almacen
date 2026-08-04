<?php
require 'includes/auth.php';
verificarPermiso('ventas');
$titulo = 'Descargar CFDI';

if (!isset($_GET['id'])) { redirect('dashboard.php'); }
$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM facturas_emitidas WHERE id=?");
$stmt->execute([$id]);
$f = $stmt->fetch();
if (!$f || empty($f['xml_path'])) {
    alert('danger', 'Factura no encontrada');
    redirect('dashboard.php');
}

$archivo = __DIR__ . '/uploads/cfdi/' . $f['xml_path'];
if (!file_exists($archivo)) {
    alert('danger', 'Archivo XML no existe');
    redirect('dashboard.php');
}

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . ($f['uuid'] ?? 'precfdi') . '.xml"');
header('Content-Length: ' . filesize($archivo));
readfile($archivo);
exit;
