<?php
$titulo = 'Tienda - Comprar Productos';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$action = $_GET['action'] ?? 'catalogo';
$categoria_id = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : 0;
$search = trim($_GET['search'] ?? '');
require_once 'includes/surtir_common.php';

if ($action === 'agregar' && isset($_GET['producto_id'])) {
    $pid = (int)$_GET['producto_id'];
    $p = $pdo->prepare("SELECT id,codigo,nombre,precio_venta,stock_minimo FROM productos WHERE id=? AND activo=1");
    $p->execute([$pid]); $p = $p->fetch();
    if ($p) {
        if (!isset($_SESSION['carrito'])) $_SESSION['carrito'] = [];
        $found = false;
        foreach ($_SESSION['carrito'] as &$item) { if ($item['producto_id']==$pid) { $item['cantidad']++; $found=true; break; } } unset($item);
        if (!$found) $_SESSION['carrito'][] = ['producto_id'=>$p['id'],'codigo'=>$p['codigo'],'nombre'=>$p['nombre'],'precio'=>(float)$p['precio_venta'],'cantidad'=>1];
        alert('success',"{$p['nombre']} agregado");
    } else alert('danger','No encontrado');
    redirect('tienda.php'.($categoria_id?"?categoria_id=$categoria_id":''));
}

if ($action === 'quitar' && isset($_GET['idx'])) {
    $idx=(int)$_GET['idx']; if (isset($_SESSION['carrito'][$idx])) { unset($_SESSION['carrito'][$idx]); $_SESSION['carrito']=array_values($_SESSION['carrito']); alert('info','Eliminado'); }
    redirect('tienda.php?action=carrito');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='actualizar') {
    foreach ($_POST['cantidad']??[] as $idx=>$cant) { $cant=(int)$cant; if (isset($_SESSION['carrito'][$idx])) { if ($cant<=0) unset($_SESSION['carrito'][$idx]); else $_SESSION['carrito'][$idx]['cantidad']=$cant; } }
    $_SESSION['carrito']=array_values($_SESSION['carrito']); alert('success','Actualizado'); redirect('tienda.php?action=carrito');
}

// Agregar columnas IVA y envio si no existen
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN iva DECIMAL(12,2) DEFAULT 0 AFTER total"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN costo_envio DECIMAL(12,2) DEFAULT 0 AFTER iva"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN paqueteria_id INT DEFAULT NULL AFTER costo_envio"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN zona_envio_id INT DEFAULT NULL AFTER paqueteria_id"); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='checkout') {
    if (empty($_SESSION['carrito'])) { alert('danger','Carrito vacio'); redirect('tienda.php?action=carrito'); }
    try {
        $pdo->beginTransaction();

        $folio = 'TDA-'.date('Ymd').'-'.strtoupper(substr(uniqid(),-6));
        $asignado_id = !empty($_POST['asignado_id']) ? (int)$_POST['asignado_id'] : null;
        $observaciones = trim($_POST['observaciones'] ?? '');
        $paqueteria_id = !empty($_POST['paqueteria_id']) ? (int)$_POST['paqueteria_id'] : null;
        $zona_envio_id = !empty($_POST['zona_envio_id']) ? (int)$_POST['zona_envio_id'] : null;
        $costo_envio = 0;
        if ($paqueteria_id && $zona_envio_id) {
            $ce = $pdo->prepare("SELECT costo FROM costos_paqueteria WHERE paqueteria_id=? AND zona_id=?");
            $ce->execute([$paqueteria_id, $zona_envio_id]);
            $costo_envio = (float)($ce->fetchColumn() ?: 0);
        }

        // 1. Verificar stock global y asignar almacen por producto
        $items_procesar = [];
        foreach ($_SESSION['carrito'] as $item) {
            $inv = $pdo->prepare("SELECT i.almacen_id, a.nombre as almacen_nom, SUM(i.cantidad) as disp FROM inventario i JOIN almacenes a ON a.id=i.almacen_id WHERE i.producto_id=? AND i.cantidad>0 GROUP BY i.almacen_id ORDER BY disp DESC");
            $inv->execute([$item['producto_id']]);
            $almacenes_disp = $inv->fetchAll();
            $total_disp = array_sum(array_column($almacenes_disp, 'disp'));
            if ($total_disp < $item['cantidad']) {
                throw new Exception("Stock insuficiente de {$item['nombre']}: disponible $total_disp en total, solicitado {$item['cantidad']}");
            }
            $items_procesar[] = [
                'item' => $item,
                'almacenes' => $almacenes_disp,
            ];
        }

        // 2. Crear orden (usamos el almacen del primer producto)
        $primer_almacen = $items_procesar[0]['almacenes'][0]['almacen_id'] ?? 1;
        $subtotal = 0;
        foreach ($_SESSION['carrito'] as $item) $subtotal += $item['cantidad'] * $item['precio'];
        $iva = round($subtotal * 0.16, 2);
        $pdo->prepare("INSERT INTO ordenes_compra (folio,proveedor_id,almacen_id,fecha_orden,fecha_entrega,observaciones,usuario_id,asignado_id,estatus,total,iva,costo_envio,paqueteria_id,zona_envio_id) VALUES (?,1,?,DATE_ADD(CURDATE(),INTERVAL 2 DAY),?,?,?,?,'pendiente',?,?,?,?,?)")
            ->execute([$folio,$primer_almacen,date('Y-m-d',strtotime('+2 days')),"Pedido Tienda - $observaciones",$_SESSION['usuario_id'],$asignado_id,$subtotal,$iva,$costo_envio,$paqueteria_id,$zona_envio_id]);
        $orden_id = $pdo->lastInsertId();

        // 3. Obtener tipo SALIDA
        $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='SALIDA'")->fetch();

        // 4. Procesar cada producto contra su almacen
        $suma_productos = 0;
        foreach ($items_procesar as $ip) {
            $item = $ip['item'];
            $sub = $item['cantidad'] * $item['precio']; $suma_productos += $sub;

            $pendiente = $item['cantidad'];
            foreach ($ip['almacenes'] as $alm) {
                if ($pendiente <= 0) break;
                $almacen_id = $alm['almacen_id'];
                $tomar = min($pendiente, (float)$alm['disp']);

                $fm = 'SAL-'.date('Ymd').'-'.strtoupper(substr(uniqid(),-6));
                $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id,folio,almacen_origen_id,producto_id,cantidad,costo_unitario,referencia,observaciones,usuario_id) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$tm['id'],$fm,$almacen_id,$item['producto_id'],$tomar,$item['precio'],"Pedido: $folio","Almacen: {$alm['almacen_nom']} - Asignado a: #$asignado_id",$_SESSION['usuario_id']]);

                // Descontar inventario por lote
                $lotes = $pdo->prepare("SELECT id,cantidad FROM inventario WHERE almacen_id=? AND producto_id=? AND cantidad>0 ORDER BY updated_at ASC");
                $lotes->execute([$almacen_id,$item['producto_id']]);
                $por_descontar = $tomar;
                foreach ($lotes as $l) { if ($por_descontar<=0) break; $d = min($por_descontar,$l['cantidad']); $pdo->prepare("UPDATE inventario SET cantidad=cantidad-? WHERE id=?")->execute([$d,$l['id']]); $por_descontar -= $d; }
                $pendiente -= $tomar;
            }

            $pdo->prepare("INSERT INTO ordenes_compra_detalle (orden_compra_id,producto_id,cantidad,precio_unitario) VALUES (?,?,?,?)")
                ->execute([$orden_id,$item['producto_id'],$item['cantidad'],$item['precio']]);
        }

        $pdo->prepare("UPDATE ordenes_compra SET total=? WHERE id=?")->execute([$suma_productos,$orden_id]);
        $pdo->commit(); unset($_SESSION['carrito']);

        // Notificar a empleados responsables de ubicaciones
        $prodsNotif = [];
        foreach ($items_procesar as $ip) {
            $prodsNotif[] = ['producto_id'=>$ip['item']['producto_id'], 'codigo'=>$ip['item']['codigo'], 'nombre'=>$ip['item']['nombre'], 'cantidad'=>$ip['item']['cantidad']];
        }
        notificarResponsablesUbicacion('tienda', $orden_id, $folio, $prodsNotif, $pdo);

        alert('success',"Pedido $folio realizado. Salida generada.");
        redirect("ordenes.php?action=detalle&id=$orden_id");

    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger','Error: '.$e->getMessage());
    }
}

// AJAX: calcular costo envio
if ($action === 'calc_envio' && isset($_GET['paqueteria_id']) && isset($_GET['zona_id'])) {
    $ce = $pdo->prepare("SELECT costo FROM costos_paqueteria WHERE paqueteria_id=? AND zona_id=?");
    $ce->execute([(int)$_GET['paqueteria_id'], (int)$_GET['zona_id']]);
    $costo = (float)($ce->fetchColumn() ?: 0);
    header('Content-Type: application/json');
    echo json_encode(['costo' => $costo]);
    exit;
}

$categorias = $pdo->query("SELECT * FROM categorias WHERE activo=1 ORDER BY nombre")->fetchAll();

$sql = "SELECT p.*,c.nombre as categoria,um.codigo as umedida,COALESCE(SUM(i.cantidad),0) as stock_total FROM productos p JOIN categorias c ON c.id=p.categoria_id JOIN unidades_medida um ON um.id=p.unidad_medida_id LEFT JOIN inventario i ON i.producto_id=p.id WHERE p.activo=1";
$params = [];
if ($categoria_id) { $sql .= " AND p.categoria_id=?"; $params[]=$categoria_id; }
if ($search) { $sql .= " AND (p.nombre LIKE ? OR p.codigo LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
$sql .= " GROUP BY p.id ORDER BY c.nombre, p.nombre";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $productos = $stmt->fetchAll();

// Stock por almacen para cada producto
$stock_almacenes = [];
if (!empty($productos)) {
    $ids = array_column($productos, 'id');
    $st = $pdo->query("SELECT i.producto_id, i.almacen_id, a.nombre as almacen, SUM(i.cantidad) as cant FROM inventario i JOIN almacenes a ON a.id=i.almacen_id WHERE i.producto_id IN (".implode(',',$ids).") AND i.cantidad>0 GROUP BY i.producto_id, i.almacen_id ORDER BY i.producto_id, cant DESC");
    foreach ($st as $s) { $stock_almacenes[$s['producto_id']][] = $s; }
}

$carrito = $_SESSION['carrito'] ?? []; $total_carrito=0; foreach($carrito as $it) $total_carrito += $it['cantidad']*$it['precio'];
$empleados = $pdo->query("SELECT u.id,u.nombre,r.nombre as rol FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.activo=1 ORDER BY u.nombre")->fetchAll();

require 'includes/header.php';
?>
<style>
.store-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.product-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;display:flex;flex-direction:column}
.product-card h3{margin:0 0 4px;font-size:1rem}.product-card .codigo{font-size:.8rem;color:#888}
.product-card .descripcion{font-size:.85rem;color:#666;flex:1}.product-card .precio{font-size:1.2rem;font-weight:700;color:#5b9bd5}
.product-card .stock{font-size:.8rem;margin-bottom:8px}.product-card .stock small{display:block;color:#888}
.cart-table{width:100%;border-collapse:collapse}
.cart-table th,.cart-table td{padding:8px;border-bottom:1px solid #eee;text-align:left}.cart-table th{background:#eef2f7}
.cart-total{text-align:right;font-size:1.3rem;font-weight:700}
</style>
<div style="display:flex;justify-content:space-between;align-items:center"><h2>Tienda</h2><a href="tienda.php?action=carrito" class="btn btn-info">Carrito (<?=count($carrito)?>) <?=!empty($carrito)?moneda($total_carrito):''?></a></div>
<?php if ($action==='carrito'):?>
<div class="card"><div class="card-header"><h2>Carrito</h2><a href="tienda.php" class="btn btn-secondary btn-sm">Volver</a></div>
<?php if (empty($carrito)):?><p style="text-align:center;padding:40px;color:#999">Vacio</p>
<?php else:?>
<form method="post" action="tienda.php?action=actualizar"><table class="cart-table"><tr><th>Producto</th><th>Precio</th><th>Cant</th><th>Subtotal</th><th></th></tr>
<?php foreach($carrito as $idx=>$it):?><tr><td><?=h($it['nombre'])?></td><td><?=moneda($it['precio'])?></td><td><input type="number" name="cantidad[<?=$idx?>]" value="<?=$it['cantidad']?>" min="0" style="width:60px"></td><td><?=moneda($it['cantidad']*$it['precio'])?></td><td><a href="tienda.php?action=quitar&idx=<?=$idx?>" class="btn btn-sm btn-danger">X</a></td></tr>
<?php endforeach;?></table><div class="cart-total">Total: <?=moneda($total_carrito)?></div><button type="submit" class="btn btn-secondary">Actualizar</button></form>
<hr><h3>Finalizar pedido</h3>
<form method="post" action="tienda.php?action=checkout" style="max-width:500px" id="checkoutForm">
<div class="form-group"><label>Asignar a empleado *</label><select name="asignado_id" required><option value="">--</option><?php foreach($empleados as $e):?><option value="<?=$e['id']?>"><?=h($e['nombre'])?> (<?=$e['rol']?>)</option><?php endforeach;?></select></div>
<div class="form-group"><label>Paqueteria</label>
    <select name="paqueteria_id" id="paqueteria_id" onchange="calcEnvio()"><option value="">Sin envio</option>
    <?php $paqs = $pdo->query("SELECT * FROM paqueterias WHERE activo=1 ORDER BY nombre")->fetchAll(); foreach($paqs as $pq):?>
    <option value="<?=$pq['id']?>"><?=h($pq['nombre'])?></option>
    <?php endforeach;?></select></div>
<div class="form-group"><label>Zona de envio</label>
    <select name="zona_envio_id" id="zona_envio_id" onchange="calcEnvio()"><option value="">--</option>
    <?php $zonas_env = $pdo->query("SELECT * FROM zonas_paqueteria WHERE activo=1 ORDER BY nombre")->fetchAll(); foreach($zonas_env as $z):?>
    <option value="<?=$z['id']?>"><?=h($z['nombre'])?></option>
    <?php endforeach;?></select></div>
<div class="form-group"><label>Observaciones</label><textarea name="observaciones" rows="2" placeholder="Notas para el empleado..."></textarea></div>
<div style="background:#f9fafc;padding:12px;border-radius:6px;margin-bottom:12px;font-size:.9rem">
    <div style="display:flex;justify-content:space-between;padding:4px 0"><span>Subtotal</span><span id="res_subtotal"><?=moneda($total_carrito)?></span></div>
    <div style="display:flex;justify-content:space-between;padding:4px 0"><span>IVA (16%)</span><span id="res_iva"><?=moneda($total_carrito*0.16)?></span></div>
    <div style="display:flex;justify-content:space-between;padding:4px 0"><span>Envio</span><span id="res_envio"><?=moneda(0)?></span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0;font-weight:700;font-size:1.1rem;border-top:2px solid #ddd;margin-top:4px"><span>Total</span><span id="res_total"><?=moneda($total_carrito*1.16)?></span></div>
</div>
<p style="font-size:.85rem;color:#666">Los productos se tomaran automaticamente del almacen que tenga stock disponible.</p>
<button type="submit" class="btn btn-success" onclick="return confirm('¿Confirmar pedido? Se descontara del inventario.')">Confirmar pedido</button>
</form>
<script>
<?php $iva_rate = 0.16; ?>
var subtotal = <?=json_encode($total_carrito)?>;
function calcEnvio() {
    var paq = document.getElementById('paqueteria_id').value;
    var zona = document.getElementById('zona_envio_id').value;
    var envio = 0;
    if (paq && zona) {
        var x = new XMLHttpRequest();
        x.open('GET', 'tienda.php?action=calc_envio&paqueteria_id='+paq+'&zona_id='+zona, false);
        x.send();
        if (x.status === 200) { var r = JSON.parse(x.responseText); envio = parseFloat(r.costo) || 0; }
    }
    var iva = Math.round(subtotal * <?=$iva_rate?> * 100) / 100;
    var total = subtotal + iva + envio;
    document.getElementById('res_iva').textContent = '$'+iva.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    document.getElementById('res_envio').textContent = '$'+envio.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    document.getElementById('res_total').textContent = '$'+total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
}
</script>
<?php endif;?></div>
<?php else:?>
<div class="card" style="margin-bottom:16px"><form class="search-bar" method="get" style="margin:0">
<select name="categoria_id" onchange="this.form.submit()"><option value="">Todas</option><?php foreach($categorias as $c):?><option value="<?=$c['id']?>"<?=$categoria_id==$c['id']?' selected':''?>><?=h($c['nombre'])?></option><?php endforeach;?></select>
<input type="text" name="search" placeholder="Buscar..." value="<?=h($search)?>"><button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
<?php if($categoria_id||$search):?><a href="tienda.php" class="btn btn-sm btn-danger">Limpiar</a><?php endif;?></form></div>
<?php if(count($productos)===0):?><p style="padding:40px;text-align:center;color:#999">Sin productos</p>
<?php else:?><div class="store-grid">
<?php foreach($productos as $p):
$sc=$p['stock_total']<=$p['stock_minimo']?'color:#e74c3c':'color:#27ae60';
$s_alm = $stock_almacenes[$p['id']] ?? [];
?>
<div class="product-card">
<div style="text-align:center;margin-bottom:8px"><img src="<?=($p['imagen'] && file_exists('uploads/productos/'.$p['imagen']))?'uploads/productos/'.h($p['imagen']):'placeholder_img.php?codigo='.urlencode($p['codigo']).'&size=150'?>" alt="<?=h($p['nombre'])?>" style="max-width:100%;height:140px;object-fit:contain;border-radius:6px;"></div>
<h3><?=h($p['nombre'])?></h3>
<div class="codigo"><?=h($p['codigo'])?> | <?=h($p['categoria'])?></div>
<div class="descripcion"><?=h(mb_substr($p['descripcion']??'',0,120))?></div>
<div class="precio"><?=moneda($p['precio_venta'])?></div>
<div class="stock" style="<?=$sc?>"><?=number_format($p['stock_total'])?> <?=h($p['umedida'])?><?=$p['stock_total']<=$p['stock_minimo']?' (min: '.$p['stock_minimo'].')':''?>
<?php foreach($s_alm as $sa):?><small><?=h($sa['almacen'])?>: <?=number_format($sa['cant'])?></small><?php endforeach;?>
</div>
<a href="tienda.php?action=agregar&producto_id=<?=$p['id']?><?=$categoria_id?"&categoria_id=$categoria_id":''?>" class="btn btn-primary">+ Agregar</a></div>
<?php endforeach;?></div><?php endif;?>
<?php endif;?>
<?php require 'includes/footer.php'; ?>
