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

$regs = $pdo->query("SELECT id, uuid, fecha_emision, archivo, archivo_pdf FROM sat_cfdi ORDER BY id DESC LIMIT 20")->fetchAll();

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
    <div class="card-header"><h2>Registros en BD (sat_cfdi)</h2></div>
    <div class="table-wrapper">
        <table>
            <tr><th>ID</th><th>UUID</th><th>Fecha</th><th>archivo</th><th>archivo_pdf</th><th>Existe XML</th><th>Existe PDF</th></tr>
            <?php foreach ($regs as $r): ?>
            <tr>
                <td><?=(int)$r['id']?></td>
                <td style="font-size:.7rem;"><?=h($r['uuid'])?></td>
                <td><?=h($r['fecha_emision'] ?? '-')?></td>
                <td style="font-size:.7rem;"><?=h($r['archivo'] ?: '-')?></td>
                <td style="font-size:.7rem;"><?=h($r['archivo_pdf'] ?: '-')?></td>
                <td><?=$r['archivo'] && file_exists(__DIR__.'/'.$r['archivo']) ? 'SI' : 'NO'?></td>
                <td><?=$r['archivo_pdf'] && file_exists(__DIR__.'/'.$r['archivo_pdf']) ? 'SI' : 'NO'?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$regs): ?><tr><td colspan="7">No hay registros en sat_cfdi</td></tr><?php endif; ?>
        </table>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
