<?php
require 'includes/auth.php';
verificarPermiso('facturas_sat');
$titulo = 'Descargar CFDI SAT';

if (!isset($_GET['id'])) { redirect('facturas_sat.php'); }
$id = (int)$_GET['id'];
$tipo = ($_GET['tipo'] ?? 'xml') === 'pdf' ? 'pdf' : 'xml';

$stmt = $pdo->prepare("SELECT * FROM sat_cfdi WHERE id=?");
$stmt->execute([$id]);
$f = $stmt->fetch();
if (!$f) {
    alert('danger', 'CFDI no encontrado');
    redirect('facturas_sat.php');
}

$col = $tipo === 'pdf' ? 'archivo_pdf' : 'archivo';
$ruta = $f[$col] ?? '';
if ($ruta === '' || !file_exists(__DIR__ . '/' . $ruta)) {
    alert('danger', 'Archivo ' . strtoupper($tipo) . ' no existe para este CFDI');
    redirect('facturas_sat.php');
}

$nombre = $f['uuid'] . '.' . $tipo;
header('Content-Type: ' . ($tipo === 'pdf' ? 'application/pdf' : 'application/xml; charset=utf-8'));
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . filesize(__DIR__ . '/' . $ruta));
readfile(__DIR__ . '/' . $ruta);
exit;
