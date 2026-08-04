<?php
$titulo = 'Ordenes de Compra';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS ordenes_compra (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(50) NOT NULL UNIQUE,
    proveedor_id INT NOT NULL,
    almacen_id INT NOT NULL,
    fecha_entrega DATE NULL,
    observaciones TEXT,
    usuario_id INT NOT NULL,
    asignado_id INT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    estatus ENUM('pendiente','recibida','cancelada') DEFAULT 'pendiente',
    fecha_orden TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
    FOREIGN KEY (almacen_id) REFERENCES almacenes(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (asignado_id) REFERENCES empleados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS ordenes_compra_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    orden_compra_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad DECIMAL(12,2) NOT NULL DEFAULT 0,
    precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
    cantidad_recibida DECIMAL(12,2) NOT NULL DEFAULT 0,
    ubicacion_id INT NULL,
    FOREIGN KEY (orden_compra_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (ubicacion_id) REFERENCES ubicaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try { $pdo->exec("ALTER TABLE ordenes_compra_detalle ADD COLUMN ubicacion_id INT NULL AFTER cantidad_recibida"); } catch (Exception $e) {}

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    try {
        $pdo->beginTransaction();

        $folio = 'OC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $proveedor_id = (int)$_POST['proveedor_id'];
        $almacen_id = (int)$_POST['almacen_id'];
        $fecha_entrega = !empty($_POST['fecha_entrega']) ? $_POST['fecha_entrega'] : null;
        $observaciones = trim($_POST['observaciones'] ?? '');
        $total = 0;

        $stmt = $pdo->prepare("INSERT INTO ordenes_compra (folio, proveedor_id, almacen_id, fecha_entrega, observaciones, usuario_id, asignado_id) VALUES (?,?,?,?,?,?,?)");
        $asignado_id = !empty($_POST['asignado_id']) ? (int)$_POST['asignado_id'] : null;
        $stmt->execute([$folio, $proveedor_id, $almacen_id, $fecha_entrega, $observaciones, $_SESSION['usuario_id'], $asignado_id]);
        $orden_id = $pdo->lastInsertId();

        $productos = $_POST['producto_id'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];
        $precios = $_POST['precio_unitario'] ?? [];
        $ubicaciones = $_POST['ubicacion_id'] ?? [];

        for ($i = 0; $i < count($productos); $i++) {
            if (empty($productos[$i]) || empty($cantidades[$i])) continue;
            $cant = str_replace(',','', $cantidades[$i]);
            $prec = str_replace(',','', $precios[$i] ?? 0);
            $ubic_id = !empty($ubicaciones[$i]) ? (int)$ubicaciones[$i] : null;
            $subtotal = $cant * $prec;
            $total += $subtotal;

            $pdo->prepare("INSERT INTO ordenes_compra_detalle (orden_compra_id, producto_id, cantidad, precio_unitario, ubicacion_id) VALUES (?,?,?,?,?)")
                ->execute([$orden_id, (int)$productos[$i], $cant, $prec, $ubic_id]);
        }

        $pdo->prepare("UPDATE ordenes_compra SET total=? WHERE id=?")->execute([$total, $orden_id]);
        $pdo->commit();
        alert('success', "Orden de compra creada. Folio: $folio");
        redirect("ordenes.php?action=detalle&id=$orden_id");

    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'asignar_empleado' && $id) {
    $asignado_id = !empty($_POST['asignado_id']) ? (int)$_POST['asignado_id'] : null;
    $pdo->prepare("UPDATE ordenes_compra SET asignado_id=? WHERE id=? AND estatus NOT IN ('recibida','cancelada')")
        ->execute([$asignado_id, $id]);
    alert('success', 'Empleado asignado correctamente');
    redirect("ordenes.php?action=detalle&id=$id");
}

if (isset($_GET['recibir']) && $id) {
    try {
        $pdo->beginTransaction();
        $orden = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id=?");
        $orden->execute([$id]); $orden = $orden->fetch();
        if (!$orden) throw new Exception("Orden no encontrada");

        $detalles = $pdo->prepare("SELECT * FROM ordenes_compra_detalle WHERE orden_compra_id=?");
        $detalles->execute([$id]);

        foreach ($detalles as $d) {
            $pendiente = $d['cantidad'] - $d['cantidad_recibida'];
            if ($pendiente <= 0) continue;

            // Entrada de inventario
            $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='ENTRADA'")->fetch();
            $folio = 'OC-' . $d['id'] . '-' . date('Ymd');

            $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id, folio, almacen_destino_id, producto_id, cantidad, costo_unitario, referencia, usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$tm['id'], $folio, $orden['almacen_id'], $d['producto_id'], $pendiente, $d['precio_unitario'], $orden['folio'], $_SESSION['usuario_id']]);

            // Actualizar inventario
            $inv = $pdo->prepare("SELECT id FROM inventario WHERE almacen_id=? AND producto_id=?");
            $inv->execute([$orden['almacen_id'], $d['producto_id']]);
            $ex = $inv->fetch();
            if ($ex) {
                $pdo->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?")->execute([$pendiente, $ex['id']]);
            } else {
                $pdo->prepare("INSERT INTO inventario (almacen_id, producto_id, cantidad) VALUES (?,?,?)")->execute([$orden['almacen_id'], $d['producto_id'], $pendiente]);
            }

            $pdo->prepare("UPDATE ordenes_compra_detalle SET cantidad_recibida = cantidad_recibida + ? WHERE id=?")->execute([$pendiente, $d['id']]);
        }

        $pdo->prepare("UPDATE ordenes_compra SET estatus='recibida' WHERE id=?")->execute([$id]);
        $pdo->commit();
        alert('success', 'Orden recibida completamente');
        redirect('ordenes.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error al recibir: ' . $e->getMessage());
    }
}

$search = $_GET['search'] ?? '';
$sql = "SELECT oc.*, p.nombre as proveedor, a.nombre as almacen, e.nombre as empleado_nom, e.apellido as empleado_ape FROM ordenes_compra oc JOIN proveedores p ON p.id=oc.proveedor_id JOIN almacenes a ON a.id=oc.almacen_id LEFT JOIN empleados e ON e.id=oc.asignado_id WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (oc.folio LIKE ? OR p.nombre LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY oc.created_at DESC";
$ordenes = $pdo->prepare($sql);
$ordenes->execute($params);

$proveedores = $pdo->query("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();
$empleados = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre, apellido")->fetchAll();
$ubicaciones = $pdo->query("SELECT u.id, u.codigo, a.nombre as almacen FROM ubicaciones u JOIN almacenes a ON a.id=u.almacen_id WHERE u.activo=1 ORDER BY a.nombre, u.codigo")->fetchAll();

require 'includes/header.php';

if ($action === 'create'):
?>
<div class="card" style="max-width: 800px;">
    <div class="card-header"><h2>Nueva Orden de Compra</h2></div>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>Proveedor *</label>
                <select name="proveedor_id" required>
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($proveedores as $p): ?>
                    <option value="<?=$p['id']?>"><?=h($p['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Almacen destino *</label>
                <select name="almacen_id" required>
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($almacenes as $a): ?>
                    <option value="<?=$a['id']?>"><?=h($a['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Empleado asignado</label>
                <select name="asignado_id">
                    <option value="">-- Sin asignar --</option>
                    <?php foreach ($empleados as $e): ?>
                    <option value="<?=$e['id']?>"><?=h($e['nombre'])?> <?=h($e['apellido'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Fecha de entrega estimada</label>
            <input type="date" name="fecha_entrega">
        </div>
        <div class="form-group">
            <label>Observaciones</label>
            <textarea name="observaciones" rows="2"></textarea>
        </div>

        <h3 style="margin:16px 0 8px;">Productos</h3>
        <div id="productos-container">
            <div class="form-row" style="gap:8px;margin-bottom:8px;">
                <div style="flex:2"><label>Producto</label></div>
                <div style="flex:1"><label>Cantidad</label></div>
                <div style="flex:1"><label>Precio unit.</label></div>
                <div style="flex:1"><label>Ubicacion</label></div>
                <div style="width:40px"></div>
            </div>
            <div class="form-row" style="gap:8px;margin-bottom:8px;" id="fila-0">
                <div style="flex:2">
                    <select name="producto_id[]">
                        <option value="">-- Producto --</option>
                        <?php foreach ($productos as $p): ?>
                        <option value="<?=$p['id']?>"><?=h($p['codigo'])?> - <?=h($p['nombre'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1"><input type="number" step="0.01" name="cantidad[]" placeholder="0"></div>
                <div style="flex:1"><input type="number" step="0.01" name="precio_unitario[]" placeholder="0.00"></div>
                <div style="flex:1">
                    <select name="ubicacion_id[]">
                        <option value="">-- Ubicacion --</option>
                        <?php foreach ($ubicaciones as $u): ?>
                        <option value="<?=$u['id']?>"><?=h($u['codigo'])?> (<?=h($u['almacen'])?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="width:40px"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">X</button></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" onclick="agregarFila()">+ Agregar producto</button>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-primary">Crear Orden</button>
            <a href="ordenes.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<script>
const ubicaciones = <?=json_encode(array_map(function($u){return ['id'=>$u['id'],'nombre'=>$u['codigo'].' ('.$u['almacen'].')'];}, $ubicaciones))?>;
let filaIdx = 1;
function agregarFila() {
    const c = document.getElementById('productos-container');
    const div = document.createElement('div');
    div.className = 'form-row';
    div.style = 'gap:8px;margin-bottom:8px;';
    const ubiOpts = ubicaciones.map(u => '<option value="'+u.id+'">'+u.nombre+'</option>').join('');
    div.innerHTML = `
        <div style="flex:2"><select name="producto_id[]"><option value="">-- Producto --${<?=json_encode(array_map(function($p){return ['id'=>$p['id'],'nombre'=>$p['codigo'].' - '.$p['nombre']];}, $productos))?>.map(p=>'<option value="'+p.id+'">'+p.nombre+'</option>').join('')}</select></div>
        <div style="flex:1"><input type="number" step="0.01" name="cantidad[]" placeholder="0"></div>
        <div style="flex:1"><input type="number" step="0.01" name="precio_unitario[]" placeholder="0.00"></div>
        <div style="flex:1"><select name="ubicacion_id[]"><option value="">-- Ubicacion --</option>${ubiOpts}</select></div>
        <div style="width:40px"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">X</button></div>
    `;
    c.appendChild(div);
}
</script>

<?php elseif ($action === 'detalle' && $id):
    $orden = $pdo->prepare("SELECT oc.*, p.nombre as proveedor, a.nombre as almacen, e.nombre as asignado_nombre, e.apellido as asignado_ape FROM ordenes_compra oc JOIN proveedores p ON p.id=oc.proveedor_id JOIN almacenes a ON a.id=oc.almacen_id LEFT JOIN empleados e ON e.id=oc.asignado_id WHERE oc.id=?");
    $empleados = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre, apellido")->fetchAll();
    $orden->execute([$id]); $orden = $orden->fetch();
    if (!$orden) { alert('danger', 'No encontrada'); redirect('ordenes.php'); }

    $detalles = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, um.codigo as umedida, u.codigo as ubicacion FROM ordenes_compra_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id LEFT JOIN ubicaciones u ON u.id=d.ubicacion_id WHERE d.orden_compra_id=?");
    $detalles->execute([$id]);
?>
<div class="card">
    <div class="card-header"><h2>Orden: <?=h($orden['folio'])?></h2>
        <span class="badge badge-<?=$orden['estatus']==='recibida'?'success':($orden['estatus']==='cancelada'?'danger':'warning')?>"><?=h($orden['estatus'])?></span>
    </div>
    <table>
        <tr><td><strong>Proveedor:</strong> <?=h($orden['proveedor'])?></td>
            <td><strong>Almacen:</strong> <?=h($orden['almacen'])?></td></tr>
        <tr><td><strong>Fecha:</strong> <?=$orden['fecha_orden']?></td>
            <td><strong>Entrega:</strong> <?=$orden['fecha_entrega'] ?? 'Pendiente'?></td></tr>
        <tr><td colspan="2"><strong>Total:</strong> <?=moneda($orden['total'])?></td></tr>
        <?php if ($orden['estatus'] !== 'recibida' && $orden['estatus'] !== 'cancelada'): ?>
        <tr>
            <td colspan="2">
                <form method="post" action="ordenes.php?action=asignar_empleado&id=<?=$orden['id']?>" style="display:flex;align-items:center;gap:8px;">
                    <strong>Asignado a:</strong>
                    <select name="asignado_id" style="flex:1;">
                        <option value="">-- Sin asignar --</option>
                        <?php foreach ($empleados as $e): ?>
                        <option value="<?=$e['id']?>"<?=$e['id']==$orden['asignado_id']?' selected':''?>><?=h($e['nombre'])?> <?=h($e['apellido'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Asignar</button>
                </form>
            </td>
        </tr>
        <?php elseif ($orden['asignado_nombre']): ?>
        <tr><td colspan="2"><strong>Asignado a:</strong> <?=h($orden['asignado_nombre'] . ' ' . $orden['asignado_ape'])?></td></tr>
        <?php endif; ?>
    </table>
    <h3 style="margin:16px 0 8px;">Detalle</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Codigo</th><th>Producto</th><th>Ubicacion</th><th>Cantidad</th><th>Recibido</th><th>Precio</th><th>Subtotal</th></tr>
            <?php foreach ($detalles as $d): ?>
            <tr>
                <td><?=h($d['codigo'])?></td>
                <td><?=h($d['producto'])?></td>
                <td><?=h($d['ubicacion'] ?? '--')?></td>
                <td><?=h($d['cantidad'])?> <?=h($d['umedida'])?></td>
                <td><?=h($d['cantidad_recibida'])?></td>
                <td><?=moneda($d['precio_unitario'])?></td>
                <td><?=moneda($d['cantidad']*$d['precio_unitario'])?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php if ($orden['estatus'] !== 'recibida' && $orden['estatus'] !== 'cancelada'): ?>
    <a href="ordenes.php?recibir=1&id=<?=$orden['id']?>" class="btn btn-success" onclick="return confirm('¿Recibir todos los productos en inventario?')"> Recibir todo</a>
    <?php endif; ?>
    <a href="ordenes.php" class="btn btn-secondary"><- Volver</a>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Ordenes de Compra</h2>
        <a href="ordenes.php?action=create" class="btn btn-primary">+ Nueva</a>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar folio o proveedor..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Proveedor</th><th>Almacen</th><th>Empleado</th><th>Total</th><th>Estatus</th><th>Fecha</th><th>Acciones</th></tr>
            <?php foreach ($ordenes as $o): ?>
            <tr>
                <td><a href="ordenes.php?action=detalle&id=<?=$o['id']?>"><?=h($o['folio'])?></a></td>
                <td><?=h($o['proveedor'])?></td>
                <td><?=h($o['almacen'])?></td>
                <td><?=h($o['empleado_nom'] ? $o['empleado_nom'].' '.$o['empleado_ape'] : '--')?></td>
                <td><?=moneda($o['total'])?></td>
                <td><span class="badge badge-<?=$o['estatus']==='recibida'?'success':($o['estatus']==='cancelada'?'danger':'warning')?>"><?=h($o['estatus'])?></span></td>
                <td><?=$o['created_at']?></td>
                <td><a href="ordenes.php?action=detalle&id=<?=$o['id']?>" class="btn btn-sm btn-info">Detalle</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
