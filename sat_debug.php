<?php
// Herramienta de diagnostico temporal: ver archivos del SAT guardados y registros en BD.
require 'includes/auth.php';
verificarPermiso('facturas_sat');
$titulo = 'Diagnostico SAT';

$dir_base = __DIR__ . '/uploads/facturas_sat';
$contenido = [];
if (is_dir($dir_base)) {
    foreach (glob($dir_base . '/*', GLOB_ONLYDIR) as $mes) {
        foreach (glob($mes . '/*') as $arch) {
            $contenido[] = str_replace('\\', '/', str_replace(__DIR__ . '/', '', $arch));
        }
    }
}
sort($contenido);

// Existencia de la tabla y conteos
$tabla_existe = false;
$total_regs = 0;
$xml_existentes = 0;
$pdf_existentes = 0;
try {
    $tabla_existe = $pdo->query("SELECT COUNT(*) FROM sat_cfdi") !== false;
    $total_regs = (int)$pdo->query("SELECT COUNT(*) FROM sat_cfdi")->fetchColumn();
    $xml_existentes = (int)$pdo->query("SELECT COUNT(*) FROM sat_cfdi WHERE archivo IS NOT NULL AND archivo != '' AND archivo IS NOT NULL")->fetchColumn();
    $pdf_existentes = (int)$pdo->query("SELECT COUNT(*) FROM sat_cfdi WHERE archivo_pdf IS NOT NULL AND archivo_pdf != ''")->fetchColumn();
} catch (Throwable $e) {}

$regs = $pdo->query("SELECT id, uuid, rfc_emisor, nombre_emisor, fecha_emision, total, estado, archivo, archivo_pdf FROM sat_cfdi ORDER BY id DESC")->fetchAll();

require 'includes/header.php';
?>
<div class="card">
    <div class="card-header"><h2>Diagnostico de archivos SAT</h2></div>
    <div style="padding:16px;">
        <p><strong>Directorio:</strong> <code><?=h($dir_base)?></code></p>
        <p><strong>Existe directorio:</strong> <?=is_dir($dir_base) ? 'SI' : 'NO'?></p>
        <p><strong>Archivos encontrados en disco:</strong> <?=count($contenido)?></p>
        <?php if ($contenido): ?>
        <ul style="font-size:.8rem;max-height:200px;overflow:auto;">
            <?php foreach ($contenido as $c): ?><li><?=h($c)?></li><?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p style="color:#c0392b;">No hay archivos guardados. El SAT no se ha descargado exitosamente o bloqueo la IP.</p>
        <?php endif; ?>
    </div>
</div>
<div class="card">
    <div class="card-header">
        <h2>Facturas guardadas en BD (sat_cfdi) - <?=$total_regs?></h2>
        <div class="table-actions">
            <button type="button" class="btn btn-info btn-sm" onclick="exportarTablaExcel('#tabla-facturas-guardadas','sat_cfdi')"> Exportar Excel</button>
            <button type="button" class="btn btn-warning btn-sm" onclick="imprimirTablaPDF('#tabla-facturas-guardadas','Facturas SAT')"> Imprimir PDF</button>
        </div>
    </div>
    <div style="padding:16px;">
        <p><strong>Tabla sat_cfdi existe:</strong> <?=$tabla_existe ? 'SI' : 'NO'?></p>
        <p><strong>Con archivo XML registrado:</strong> <?=$xml_existentes?></p>
        <p><strong>Con archivo PDF registrado:</strong> <?=$pdf_existentes?></p>
    </div>
    <div class="table-wrapper">
        <table id="tabla-facturas-guardadas">
            <tr><th>ID</th><th>UUID</th><th>Emisor</th><th>RFC</th><th>Fecha</th><th>Total</th><th>Estado</th><th>XML</th><th>PDF</th><th>Descargar</th></tr>
            <?php foreach ($regs as $r): ?>
            <tr>
                <td><?=(int)$r['id']?></td>
                <td style="font-size:.7rem;"><?=h($r['uuid'])?></td>
                <td><?=h($r['nombre_emisor'] ?: '-')?></td>
                <td><?=h($r['rfc_emisor'] ?: '-')?></td>
                <td><?=h($r['fecha_emision'] ?? '-')?></td>
                <td><?=moneda($r['total'])?></td>
                <td><?=h($r['estado'] ?: '-')?></td>
                <td><?=$r['archivo'] && file_exists(__DIR__.'/'.$r['archivo']) ? 'SI' : 'NO'?></td>
                <td><?=$r['archivo_pdf'] && file_exists(__DIR__.'/'.$r['archivo_pdf']) ? 'SI' : 'NO'?></td>
                <td>
                    <?php if ($r['archivo'] && file_exists(__DIR__.'/'.$r['archivo'])): ?>
                        <a href="sat_descargar.php?id=<?=(int)$r['id']?>&tipo=xml" class="btn btn-sm btn-info">XML</a>
                    <?php endif; ?>
                    <?php if ($r['archivo_pdf'] && file_exists(__DIR__.'/'.$r['archivo_pdf'])): ?>
                        <a href="sat_descargar.php?id=<?=(int)$r['id']?>&tipo=pdf" class="btn btn-sm btn-danger">PDF</a>
                    <?php endif; ?>
                    <?php if (!$r['archivo'] || !file_exists(__DIR__.'/'.$r['archivo'])): ?>
                        <?php if (!$r['archivo_pdf'] || !file_exists(__DIR__.'/'.$r['archivo_pdf'])): ?>
                            <span style="color:#999;">Sin archivos</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$regs): ?><tr><td colspan="10">No hay registros en sat_cfdi</td></tr><?php endif; ?>
        </table>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
