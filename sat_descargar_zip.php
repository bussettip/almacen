<?php
require 'includes/auth.php';
verificarPermiso('facturas_sat');
$titulo = 'Descargar CFDI SAT (ZIP)';

// Descarga todos los XML y PDF de sat_cfdi en un solo ZIP.
// Puede filtrarse por mes con ?mes=YYYY-MM.

$mes = isset($_GET['mes']) ? preg_replace('/[^0-9-]/', '', $_GET['mes']) : '';
$params = [];
$where = '';
if ($mes !== '') {
    $where = "WHERE fecha_emision LIKE ?";
    $params[] = $mes . '%';
}

$stmt = $pdo->prepare("SELECT * FROM sat_cfdi $where ORDER BY fecha_emision DESC");
$stmt->execute($params);
$cfdis = $stmt->fetchAll();

$archivos = [];
foreach ($cfdis as $c) {
    foreach (['xml' => 'archivo', 'pdf' => 'archivo_pdf'] as $tipo => $col) {
        $ruta = $c[$col] ?? '';
        if ($ruta !== '' && file_exists(__DIR__ . '/' . $ruta)) {
            $archivos[$tipo][$c['uuid']] = __DIR__ . '/' . $ruta;
        }
    }
}

if (empty($archivos['xml']) && empty($archivos['pdf'])) {
    alert('danger', 'No hay archivos disponibles para descargar');
    redirect('facturas_sat.php');
}

// Crear el ZIP en un archivo temporal
$zip_tmp = tempnam(sys_get_temp_dir(), 'cfdi_') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zip_tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    alert('danger', 'No se pudo crear el ZIP');
    redirect('facturas_sat.php');
}

foreach ($archivos as $tipo => $lista) {
    foreach ($lista as $uuid => $ruta) {
        $zip->addFile($ruta, $uuid . '.' . $tipo);
    }
}
$zip->close();

$nombre = 'cfdi_sat_' . ($mes !== '' ? $mes : date('Y-m')) . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($zip_tmp));
readfile($zip_tmp);
@unlink($zip_tmp);
exit;
