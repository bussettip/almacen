<?php
require 'includes/auth.php';
verificarPermiso('facturas_sat');
$titulo = 'Descargar todas las facturas (ZIP)';

// Empaqueta en un solo ZIP todos los XML CFDI para presentar ante el SAT:
//  - Emitidas:  XML de facturas_emitidas (uploads/cfdi/)
//  - Recibidas: XML de sat_cfdi (uploads/facturas_sat/) y de facturas (uploads/facturas/)
// El ZIP queda organizado en carpetas emitidas/ y recibidas/.

$archivos = ['emitidas' => [], 'recibidas' => []];

// ---- Emitidas ----
$emitidas = $pdo->query(
    "SELECT fe.uuid, fe.venta_id, fe.xml_path, fe.total, v.folio AS venta_folio, c.nombre AS cliente
     FROM facturas_emitidas fe
     LEFT JOIN ventas v ON v.id = fe.venta_id
     LEFT JOIN clientes c ON c.id = v.cliente_id
     ORDER BY fe.id"
)->fetchAll();
foreach ($emitidas as $e) {
    $ruta = __DIR__ . '/uploads/cfdi/' . $e['xml_path'];
    if ($e['xml_path'] !== '' && $e['xml_path'] !== null && file_exists($ruta)) {
        $nombre = ($e['uuid'] ?: 'precfdi') . '_' . ($e['venta_id'] ?: '') . '.xml';
        $archivos['emitidas'][$nombre] = $ruta;
    }
}

// ---- Recibidas (SAT) ----
$recibidas = $pdo->query("SELECT uuid, archivo FROM sat_cfdi WHERE archivo IS NOT NULL AND archivo != ''")->fetchAll();
foreach ($recibidas as $r) {
    $ruta = __DIR__ . '/' . $r['archivo'];
    if (file_exists($ruta)) {
        $archivos['recibidas'][$r['uuid'] . '_sat.xml'] = $ruta;
    }
}

// ---- Recibidas (importacion manual de compras) ----
$manuales = $pdo->query("SELECT uuid, archivo FROM facturas WHERE archivo IS NOT NULL AND archivo != '' AND uuid IS NOT NULL")->fetchAll();
foreach ($manuales as $m) {
    $ruta = __DIR__ . '/uploads/facturas/' . $m['archivo'];
    if (file_exists($ruta)) {
        $archivos['recibidas'][$m['uuid'] . '_manual.xml'] = $ruta;
    }
}

$totalArchivos = count($archivos['emitidas']) + count($archivos['recibidas']);
if ($totalArchivos === 0) {
    alert('danger', 'No hay XML disponibles para empaquetar');
    redirect('facturas_sat.php');
}

// Crear el ZIP
$zip_tmp = tempnam(sys_get_temp_dir(), 'cfdi_') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zip_tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    alert('danger', 'No se pudo crear el ZIP');
    redirect('facturas_sat.php');
}

foreach (['emitidas', 'recibidas'] as $carpeta) {
    foreach ($archivos[$carpeta] as $nombre => $ruta) {
        $zip->addFile($ruta, $carpeta . '/' . $nombre);
    }
}
$zip->close();

$nombre = 'facturas_sat_' . date('Y-m-d') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($zip_tmp));
readfile($zip_tmp);
@unlink($zip_tmp);
exit;
