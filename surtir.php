<?php
$titulo = 'Surtir Pedidos';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN surtidor_id INT NULL AFTER asignado_id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN surtir_estatus ENUM('pendiente','en_surtido','surtido','entregado') DEFAULT 'pendiente' AFTER estatus"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN fecha_surtido DATE NULL AFTER surtir_estatus"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ventas ADD COLUMN surtidor_id INT NULL AFTER asignado_id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ventas ADD COLUMN fecha_surtido DATE NULL AFTER fecha_entrega"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra_detalle ADD COLUMN cantidad_surtida DECIMAL(12,2) NULL DEFAULT NULL AFTER cantidad"); } catch (Exception $e) {}

require_once 'includes/mqtt.php';
require_once 'includes/surtir_common.php';

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = $_GET['tipo'] ?? ''; // 'tienda' or 'venta'

// Asignar empleado a orden
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'asignar' && $id && $tipo) {
    $empleado_id = (int)$_POST['empleado_id'];
    if ($tipo === 'tienda') {
        $pdo->prepare("UPDATE ordenes_compra SET surtidor_id=?, surtir_estatus=IF(surtir_estatus='pendiente','en_surtido',surtir_estatus) WHERE id=?")->execute([$empleado_id,$id]);
    } else {
        $pdo->prepare("UPDATE ventas SET surtidor_id=?, estatus=IF(estatus='pendiente','surtido',estatus) WHERE id=?")->execute([$empleado_id,$id]);
    }
    // MQTT: publicar asignacion
    $folio = getFolio($tipo, $id, $pdo);
    $empNom = getEmpleadoNombre($empleado_id, $pdo);
    $prods = getProductosSurtir($tipo, $id, $pdo);
    mqttPublishSurtir($tipo, $id, $folio, $empleado_id, $empNom, $prods, 'asignado');
    alert('success','Empleado asignado');
    redirect('surtir.php');
}

// Marcar como surtido
if ($action === 'surtir' && $id && $tipo) {
    if ($tipo === 'tienda') {
        $pdo->prepare("UPDATE ordenes_compra SET surtir_estatus='surtido', fecha_surtido=CURDATE() WHERE id=?")->execute([$id]);
        $pdo->prepare("UPDATE ordenes_compra_detalle SET cantidad_surtida=cantidad WHERE orden_compra_id=?")->execute([$id]);
    } else {
        $det = $pdo->prepare("SELECT id, cantidad FROM venta_detalle WHERE venta_id=?");
        $det->execute([$id]);
        foreach ($det as $d) {
            $pdo->prepare("UPDATE venta_detalle SET cantidad_surtida=cantidad WHERE id=?")->execute([$d['id']]);
        }
        $pdo->prepare("UPDATE ventas SET estatus='surtido', fecha_surtido=CURDATE() WHERE id=?")->execute([$id]);
    }
    // MQTT: publicar completado
    $eid = null; $folio = getFolio($tipo, $id, $pdo);
    if ($tipo === 'tienda') {
        $r = $pdo->prepare("SELECT surtidor_id FROM ordenes_compra WHERE id=?");
        $r->execute([$id]); $r = $r->fetch(); $eid = $r ? $r['surtidor_id'] : null;
    } else {
        $r = $pdo->prepare("SELECT surtidor_id FROM ventas WHERE id=?");
        $r->execute([$id]); $r = $r->fetch(); $eid = $r ? $r['surtidor_id'] : null;
    }
    $empNom = $eid ? getEmpleadoNombre($eid, $pdo) : '';
    $prods = getProductosSurtir($tipo, $id, $pdo);
    mqttPublishSurtir($tipo, $id, $folio, $eid, $empNom, $prods, 'surtido');
    alert('success','Pedido marcado como surtido');
    redirect('surtir.php');
}

// Marcar como entregado
if ($action === 'entregar' && $id && $tipo) {
    if ($tipo === 'tienda') {
        $pdo->prepare("UPDATE ordenes_compra SET surtir_estatus='entregado' WHERE id=?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE ventas SET estatus='entregado' WHERE id=?")->execute([$id]);
    }
    alert('success','Pedido marcado como entregado');
    redirect('surtir.php');
}

// Obtener empleados
$empleados = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre")->fetchAll();

// ---- Ordenes Tienda (pendientes/surtidas) ----
$ordenes_tienda = $pdo->prepare("
    SELECT o.*, a.nombre as almacen, u.nombre as usuario_nom, e.nombre as asignado_nom, e.apellido as asignado_ape,
           s.nombre as surtidor_nom, s.apellido as surtidor_ape
    FROM ordenes_compra o
    JOIN almacenes a ON a.id=o.almacen_id
    JOIN usuarios u ON u.id=o.usuario_id
    LEFT JOIN empleados e ON e.id=o.asignado_id
    LEFT JOIN empleados s ON s.id=o.surtidor_id
    WHERE o.folio LIKE 'TDA-%'
    ORDER BY FIELD(o.surtir_estatus,'pendiente','en_surtido','surtido','entregado'), o.created_at DESC
");
$ordenes_tienda->execute();
$ordenes_tienda = $ordenes_tienda->fetchAll();

// ---- Ventas publico (pendientes/surtidas) ----
$ventas = $pdo->prepare("
    SELECT v.*, cl.nombre as cliente, a.nombre as almacen, u.nombre as usuario_nom,
           e.nombre as asignado_nom, e.apellido as asignado_ape,
           s.nombre as surtidor_nom, s.apellido as surtidor_ape
    FROM ventas v
    JOIN clientes cl ON cl.id=v.cliente_id
    JOIN almacenes a ON a.id=v.almacen_id
    JOIN usuarios u ON u.id=v.usuario_id
    LEFT JOIN empleados e ON e.id=v.asignado_id
    LEFT JOIN empleados s ON s.id=v.surtidor_id
    WHERE v.estatus IN ('pendiente','surtido')
    ORDER BY FIELD(v.estatus,'pendiente','surtido'), v.created_at DESC
");
$ventas->execute();
$ventas = $ventas->fetchAll();

require 'includes/header.php';
?>
<style>
.status-badge{display:inline-block;padding:3px 10px;border-radius:10px;font-size:.75rem;font-weight:600}
.status-pendiente{background:#fff3cd;color:#856404}
.status-en_surtido{background:#cce5ff;color:#004085}
.status-surtido{background:#d4edda;color:#155724}
.status-entregado{background:#d1ecf1;color:#0c5460}
.orden-card{border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:12px;background:#fff}
.orden-card .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.orden-card .folio{font-weight:700;font-size:1rem}
.orden-card .meta{font-size:.82rem;color:#666;margin-bottom:8px}
.orden-card .items{font-size:.85rem;margin:8px 0;padding:10px;background:#f8f9fa;border-radius:4px}
.orden-card .items table{width:100%;border-collapse:collapse;border:1px solid #ddd}
.orden-card .items th{border-bottom:1px solid #ccc;border-right:1px solid #ddd;padding:5px 8px;font-size:.75rem;color:#555;background:#eee;text-transform:uppercase}
.orden-card .items th:last-child{border-right:none}
.orden-card .items td{padding:5px 8px;border-bottom:1px solid #eee;border-right:1px solid #eee;font-size:.82rem}
.orden-card .items td:last-child{border-right:none}
.orden-card .items tr:last-child td{border-bottom:none}
.orden-card .actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
</style>

<div style="display:flex;justify-content:space-between;align-items:center">
    <h2>Surtir Pedidos</h2>
    <button id="mqtt-btn" style="display:none;background:#4fc3f7;color:#000;border:none;border-radius:6px;padding:6px 14px;font-size:.8rem;font-weight:600;cursor:pointer" onclick="location.reload()">Novedad - Recargar</button>
</div>

<div class="tabs" style="display:flex;gap:4px;margin-bottom:16px">
    <a href="surtir.php" class="btn btn-sm <?=!isset($_GET['tipo_filtro'])||$_GET['tipo_filtro']===''?'btn-primary':'btn-secondary'?>">Todos</a>
    <a href="surtir.php?tipo_filtro=tienda" class="btn btn-sm <?=($_GET['tipo_filtro']??'')==='tienda'?'btn-primary':'btn-secondary'?>">Tienda</a>
    <a href="surtir.php?tipo_filtro=venta" class="btn btn-sm <?=($_GET['tipo_filtro']??'')==='venta'?'btn-primary':'btn-secondary'?>">Venta publico</a>
</div>

<?php $tipo_filtro = $_GET['tipo_filtro'] ?? ''; ?>

<?php if (!$tipo_filtro || $tipo_filtro === 'tienda'): ?>
<h3 style="margin:0 0 10px;font-size:1rem;color:#555">Pedidos Tienda</h3>
<?php if (empty($ordenes_tienda)): ?><p style="color:#999;padding:20px;text-align:center">Sin pedidos pendientes</p>
<?php else: foreach ($ordenes_tienda as $o):
    $det = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, um.codigo as umedida, d.precio_unitario, COALESCE(d.descuento,0) as descuento FROM ordenes_compra_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id WHERE d.orden_compra_id=?");
    $det->execute([$o['id']]); $detalles = $det->fetchAll();
    $status_class = 'status-'.str_replace('_','_',$o['surtir_estatus']?:'pendiente');
    $status_label = ucfirst(str_replace('_',' ',$o['surtir_estatus']?:'pendiente'));
?>
<div class="orden-card">
    <div class="header">
        <span class="folio"><?=h($o['folio'])?></span>
        <span class="status-badge <?=$status_class?>"><?=$status_label?></span>
    </div>
    <div class="meta">
        <?=h($o['almacen'])?> | Creado por: <?=h($o['usuario_nom'])?> | <?=$o['created_at']?>
        <?php if ($o['asignado_nom']): ?> | Asignado: <?=h($o['asignado_nom'].' '.$o['asignado_ape'])?><?php endif; ?>
        <?php if ($o['surtidor_nom']): ?> | Surtidor: <strong><?=h($o['surtidor_nom'].' '.$o['surtidor_ape'])?></strong><?php endif; ?>
        <?php if ($o['fecha_surtido']): ?> | Surtido: <?=$o['fecha_surtido']?><?php endif; ?>
    </div>
    <div class="items">
        <table><tr><th>Producto</th><th style="text-align:center">Cant</th><th style="text-align:right">Precio</th><th style="text-align:right">Descto.</th></tr>
        <?php foreach ($detalles as $d): ?>
        <tr><td><?=h($d['producto'])?></td><td style="text-align:center"><?=h($d['cantidad'])?> <?=h($d['umedida'])?></td><td style="text-align:right">$<?=number_format($d['precio_unitario'],2)?></td><td style="text-align:right"><?=$d['descuento']?>%</td></tr>
        <?php endforeach; ?>
        </table>
    </div>
    <div class="actions">
        <?php if ($o['surtir_estatus'] === 'pendiente' || !$o['surtir_estatus']): ?>
        <form method="post" action="surtir.php?action=asignar&tipo=tienda&id=<?=$o['id']?>" style="display:flex;gap:6px;align-items:center">
            <select name="empleado_id" required style="font-size:.8rem;padding:4px 8px">
                <option value="">Asignar empleado...</option>
                <?php foreach ($empleados as $e): ?>
                <option value="<?=$e['id']?>"><?=h($e['nombre'].' '.$e['apellido'])?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Asignar</button>
        </form>
        <?php endif; ?>
        <?php if ($o['surtir_estatus'] === 'en_surtido'): ?>
        <a href="surtir.php?action=surtir&tipo=tienda&id=<?=$o['id']?>" class="btn btn-success btn-sm" onclick="return confirm('¿Marcar como surtido?')">Completar surtido</a>
        <?php endif; ?>
        <?php if ($o['surtir_estatus'] === 'surtido'): ?>
        <a href="surtir.php?action=entregar&tipo=tienda&id=<?=$o['id']?>" class="btn btn-info btn-sm" onclick="return confirm('¿Marcar como entregado?')">Entregar</a>
        <?php endif; ?>
        <a href="ordenes.php?action=detalle&id=<?=$o['id']?>" class="btn btn-secondary btn-sm">Ver detalle</a>
    </div>
</div>
<?php endforeach; endif; ?>
<?php endif; ?>

<?php if (!$tipo_filtro || $tipo_filtro === 'venta'): ?>
<h3 style="margin:20px 0 10px;font-size:1rem;color:#555">Ventas al Publico</h3>
<?php if (empty($ventas)): ?><p style="color:#999;padding:20px;text-align:center">Sin ventas pendientes</p>
<?php else: foreach ($ventas as $v):
    $det = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, um.codigo as umedida, d.precio_unitario, COALESCE(d.descuento,0) as descuento FROM venta_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id WHERE d.venta_id=?");
    $det->execute([$v['id']]); $detalles = $det->fetchAll();
    $status_class = 'status-'.$v['estatus'];
    $status_label = ucfirst($v['estatus']);
    $all_surtido = true;
    foreach ($detalles as $d) { if ((float)$d['cantidad_surtida'] < (float)$d['cantidad']) { $all_surtido = false; break; } }
?>
<div class="orden-card">
    <div class="header">
        <span class="folio"><?=h($v['folio'])?> <small style="font-weight:400;font-size:.75rem;color:#888"><?=h($v['cliente'])?></small></span>
        <span class="status-badge <?=$status_class?>"><?=$status_label?></span>
    </div>
    <div class="meta">
        <?=h($v['almacen'])?> | Creado por: <?=h($v['usuario_nom'])?> | <?=$v['created_at']?>
        <?php if ($v['surtidor_nom']): ?> | Surtidor: <strong><?=h($v['surtidor_nom'].' '.$v['surtidor_ape'])?></strong><?php endif; ?>
        <?php if ($v['fecha_surtido']): ?> | Surtido: <?=$v['fecha_surtido']?><?php endif; ?>
    </div>
    <div class="items">
        <table><tr><th>Producto</th><th style="text-align:center">Cant</th><th style="text-align:right">Precio</th><th style="text-align:right">Descto.</th><th style="text-align:center">Surt</th></tr>
        <?php foreach ($detalles as $d): ?>
        <tr><td><?=h($d['producto'])?></td><td style="text-align:center"><?=h($d['cantidad'])?> <?=h($d['umedida'])?></td><td style="text-align:right">$<?=number_format($d['precio_unitario'],2)?></td><td style="text-align:right"><?=h($d['descuento']?:'0')?>%</td>
            <td style="text-align:center"><span style="color:<?=(float)$d['cantidad_surtida']>=(float)$d['cantidad']?'#27ae60':'#e74c3c'?>"><?=h($d['cantidad_surtida']?:'0')?></span></td></tr>
        <?php endforeach; ?>
        </table>
    </div>
    <div class="actions">
        <?php if ($v['estatus'] === 'pendiente'): ?>
        <form method="post" action="surtir.php?action=asignar&tipo=venta&id=<?=$v['id']?>" style="display:flex;gap:6px;align-items:center">
            <select name="empleado_id" required style="font-size:.8rem;padding:4px 8px">
                <option value="">Asignar empleado...</option>
                <?php foreach ($empleados as $e): ?>
                <option value="<?=$e['id']?>"><?=h($e['nombre'].' '.$e['apellido'])?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Asignar</button>
        </form>
        <?php endif; ?>
        <?php if ($v['estatus'] === 'pendiente' && $v['surtidor_id']): ?>
        <a href="surtir.php?action=surtir&tipo=venta&id=<?=$v['id']?>" class="btn btn-success btn-sm" onclick="return confirm('¿Marcar todo como surtido?')">Completar surtido</a>
        <?php endif; ?>
        <?php if ($v['estatus'] === 'surtido' || $all_surtido): ?>
        <a href="surtir.php?action=entregar&tipo=venta&id=<?=$v['id']?>" class="btn btn-info btn-sm" onclick="return confirm('¿Marcar como entregado?')">Entregar</a>
        <?php endif; ?>
        <a href="ventas.php?action=detalle&id=<?=$v['id']?>" class="btn btn-secondary btn-sm">Ver detalle</a>
    </div>
</div>
<?php endforeach; endif; ?>
<?php endif; ?>

<?php if ($ultimo_json = @file_get_contents(__DIR__ . '/surtir_ultimo.json')): $ultimo = json_decode($ultimo_json, true); ?>
<hr style="margin:24px 0">
<h3 style="margin:0 0 10px;font-size:1rem;color:#555">Ultimo payload MQTT publicado</h3>
<div class="card" style="background:#1e1e2e;color:#cdd6f4;font-family:monospace;font-size:.8rem;overflow:auto;max-height:400px">
<pre style="margin:0;padding:12px;white-space:pre-wrap"><?=h(json_encode($ultimo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))?></pre>
</div>
<?php endif; ?>

<script src="https://unpkg.com/mqtt@5/dist/mqtt.min.js"></script>
<script>
// Boton de novedad cuando llega MQTT
(function() {
    var proto = location.protocol === 'https:' ? 'wss://' : 'ws://';
    var client = mqtt.connect(proto + location.host + '/ws/mqtt', {
        clientId: 'surtir_web_' + Math.random().toString(16).substr(2,6),
        clean: true
    });
    client.on('connect', function() {
        client.subscribe('almacen/surtir/#');
    });
    client.on('message', function() {
        var btn = document.getElementById('mqtt-btn');
        if (btn) btn.style.display = 'inline-block';
    });
})();
</script>

<?php require 'includes/footer.php'; ?>
