<?php
// Visor del registro de auditoria del flujo SAT (uploads/sat_log.txt).
require 'includes/auth.php';
verificarPermiso('facturas_sat');
$titulo = 'Log del flujo SAT';

$log = __DIR__ . '/uploads/sat_log.txt';
$lineas = is_file($log) ? array_slice(file($log), -100) : [];

require 'includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h2>Log del flujo SAT</h2>
        <div class="table-actions">
            <button type="button" class="btn btn-secondary btn-sm" onclick="location.href='sat_log.php?borrar=1'">Limpiar log</button>
        </div>
    </div>
    <div style="padding:16px;">
        <?php if (!is_file($log)): ?>
            <p style="color:#999;">El archivo <?=h($log)?> no existe todavia. Ejecuta una descarga en facturas_sat.php para generar entradas.</p>
        <?php elseif (($_GET['borrar'] ?? '') === '1'): ?>
            <?php @unlink($log); echo '<p style="color:#27ae60;">Log limpiado.</p>'; ?>
        <?php elseif (!$lineas): ?>
            <p style="color:#999;">El log esta vacio.</p>
        <?php else: ?>
            <pre style="background:#222;color:#7fdb7f;padding:12px;border-radius:8px;overflow:auto;font-size:.8rem;line-height:1.4;max-height:600px;"><?=h(implode('', array_reverse($lineas)))?></pre>
        <?php endif; ?>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
