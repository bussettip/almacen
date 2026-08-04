<?php
$titulo = 'Rotacion de Inventario';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$almacen_id = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;
$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
if (!$almacen_id && $almacenes) $almacen_id = $almacenes[0]['id'];

$productos = [];
$resumen = ['total_stock' => 0, 'total_valor' => 0, 'total_productos' => 0, 'alto' => 0, 'medio' => 0, 'bajo' => 0, 'parado' => 0];
if ($almacen_id) {
    $sql = "SELECT p.id, p.codigo, p.nombre, p.precio_venta, p.stock_minimo,
        COALESCE(inv.stock_actual,0) as stock_actual,
        ent.ultima_entrada,
        DATEDIFF(NOW(),ent.ultima_entrada) as dias_ultima_entrada,
        COALESCE(sal.salidas_30d,0) as salidas_30d,
        COALESCE(sal90.salidas_90d,0) as salidas_90d,
        COALESCE(ent30.entradas_30d,0) as entradas_30d
        FROM productos p
        LEFT JOIN (SELECT producto_id,SUM(cantidad) as stock_actual FROM inventario WHERE almacen_id=? GROUP BY producto_id) inv ON inv.producto_id=p.id
        LEFT JOIN (SELECT m.producto_id,MAX(m.created_at) as ultima_entrada FROM movimientos m JOIN tipos_movimiento tm ON tm.id=m.tipo_movimiento_id WHERE m.almacen_destino_id=? AND tm.signo=1 GROUP BY m.producto_id) ent ON ent.producto_id=p.id
        LEFT JOIN (SELECT m.producto_id,SUM(m.cantidad) as salidas_30d FROM movimientos m JOIN tipos_movimiento tm ON tm.id=m.tipo_movimiento_id WHERE m.almacen_origen_id=? AND tm.signo=-1 AND m.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY m.producto_id) sal ON sal.producto_id=p.id
        LEFT JOIN (SELECT m.producto_id,SUM(m.cantidad) as salidas_90d FROM movimientos m JOIN tipos_movimiento tm ON tm.id=m.tipo_movimiento_id WHERE m.almacen_origen_id=? AND tm.signo=-1 AND m.created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY) GROUP BY m.producto_id) sal90 ON sal90.producto_id=p.id
        LEFT JOIN (SELECT m.producto_id,SUM(m.cantidad) as entradas_30d FROM movimientos m JOIN tipos_movimiento tm ON tm.id=m.tipo_movimiento_id WHERE m.almacen_destino_id=? AND tm.signo=1 AND m.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY m.producto_id) ent30 ON ent30.producto_id=p.id
        WHERE p.activo=1 AND (inv.stock_actual>0 OR ent.ultima_entrada IS NOT NULL)
        ORDER BY COALESCE(dias_ultima_entrada,9999) DESC, p.nombre";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$almacen_id,$almacen_id,$almacen_id,$almacen_id,$almacen_id]);
    $productos = $stmt->fetchAll();
    foreach ($productos as $p) {
        $resumen['total_stock'] += $p['stock_actual'];
        $resumen['total_valor'] += $p['stock_actual'] * $p['precio_venta'];
        $resumen['total_productos']++;
        $d = $p['dias_ultima_entrada'];
        if ($d === null) $resumen['parado']++;
        elseif ($d <= 30) $resumen['alto']++;
        elseif ($d <= 90) $resumen['medio']++;
        else $resumen['bajo']++;
    }
}

require 'includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h2>Rotacion de Inventario</h2>
        <form method="get">
            <select name="almacen_id" onchange="this.form.submit()">
                <?php foreach ($almacenes as $a): ?>
                <option value="<?=$a['id']?>" <?=$almacen_id==$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div style="display:flex;gap:20px;padding:12px 20px;flex-wrap:wrap;border-bottom:1px solid #eee;font-size:.82rem;">
        <span><strong>Productos:</strong> <?=$resumen['total_productos']?></span>
        <span><strong>Stock total:</strong> <?=number_format($resumen['total_stock'],2)?></span>
        <span><strong>Valor inventario:</strong> <?=moneda($resumen['total_valor'])?></span>
        <span><span class="badge badge-success">Alta: <?=$resumen['alto']?></span></span>
        <span><span class="badge badge-warning">Media: <?=$resumen['medio']?></span></span>
        <span><span class="badge badge-danger">Baja: <?=$resumen['bajo']?></span></span>
        <span><span class="badge badge-secondary">Parado: <?=$resumen['parado']?></span></span>
    </div>
    <div class="table-wrapper">
        <table>
            <tr>
                <th>Codigo</th>
                <th>Producto</th>
                <th>Stock</th>
                <th>Min</th>
                <th>Ultima Entrada</th>
                <th>Dias</th>
                <th>Entr 30d</th>
                <th>Sal 30d</th>
                <th>Rotacion</th>
                <th>Clasificacion</th>
                <th>Valor</th>
            </tr>
            <?php foreach ($productos as $p):
                $dias = $p['dias_ultima_entrada'];
                $rotacion = $p['stock_actual'] > 0 ? round($p['salidas_30d'] / max($p['stock_actual'], 0.001), 2) : 0;
                if ($dias === null) {
                    $clase = 'badge-secondary'; $label = 'Sin datos';
                } elseif ($dias <= 30) {
                    $clase = 'badge-success'; $label = 'Alta';
                } elseif ($dias <= 90) {
                    $clase = 'badge-warning'; $label = 'Media';
                } else {
                    $clase = 'badge-danger'; $label = 'Baja';
                }
                $bajo_stock = $p['stock_minimo'] > 0 && $p['stock_actual'] <= $p['stock_minimo'];
                ?>
            <tr<?=$bajo_stock?' style="background:#fff3cd;"':''?>>
                <td><?=h($p['codigo'])?></td>
                <td><?=h($p['nombre'])?></td>
                <td style="font-weight:600;"><?=number_format($p['stock_actual'],2)?></td>
                <td style="text-align:center;font-size:.8rem;"><?=$p['stock_minimo']>0?number_format($p['stock_minimo'],2):'-'?></td>
                <td style="font-size:.8rem;"><?=$p['ultima_entrada']?date('d/m/Y',strtotime($p['ultima_entrada'])):'-'?></td>
                <td style="text-align:center;font-weight:600;"><?=$dias!==null?$dias:'-'?></td>
                <td><?=number_format($p['entradas_30d'],2)?></td>
                <td><?=number_format($p['salidas_30d'],2)?></td>
                <td style="text-align:center;"><?=$rotacion?></td>
                <td><span class="badge <?=$clase?>"><?=$label?></span></td>
                <td style="text-align:right;font-size:.8rem;"><?=moneda($p['stock_actual']*$p['precio_venta'])?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$productos): ?>
            <tr><td colspan="11" style="text-align:center;color:#999;">No hay datos para este almacen</td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
