<?php
$titulo = 'Importaciones';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS importaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(50) UNIQUE,
    proveedor_id INT NOT NULL,
    almacen_id INT NOT NULL,
    fecha_pedido DATE DEFAULT (CURRENT_DATE),
    fecha_estimada DATE,
    fecha_llegada DATE,
    estatus ENUM('pedido','embarcado','en_aduanas','liberado','recibido','cancelado') DEFAULT 'pedido',
    puerto_origen VARCHAR(100),
    puerto_destino VARCHAR(100),
    contenedor VARCHAR(50),
    naviera VARCHAR(100),
    conocimiento_embarque VARCHAR(100),
    pedimento VARCHAR(50),
    observaciones TEXT,
    usuario_id INT NOT NULL,
    asignado_id INT,
    total_mercancia DECIMAL(12,2) DEFAULT 0,
    total_gastos DECIMAL(12,2) DEFAULT 0,
    moneda VARCHAR(3) DEFAULT 'USD',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
    FOREIGN KEY (almacen_id) REFERENCES almacenes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS importacion_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    importacion_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad DECIMAL(12,2) NOT NULL,
    precio_unitario DECIMAL(12,4) NOT NULL,
    precio_unitario_mxn DECIMAL(12,4) NOT NULL,
    subtotal_mxn DECIMAL(12,2) NOT NULL,
    cantidad_recibida DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (importacion_id) REFERENCES importaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS importacion_gastos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    importacion_id INT NOT NULL,
    tipo_gasto ENUM('flete','seguro','arancel','agente_aduanal','manejo_portuario','transporte_interno','otros') NOT NULL,
    descripcion VARCHAR(255),
    monto DECIMAL(12,2) NOT NULL,
    moneda VARCHAR(3) DEFAULT 'USD',
    monto_mxn DECIMAL(12,2) NOT NULL,
    proveedor_id INT,
    FOREIGN KEY (importacion_id) REFERENCES importaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    try {
        $pdo->beginTransaction();

        $folio = 'IMP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $proveedor_id = (int)$_POST['proveedor_id'];
        $almacen_id = (int)$_POST['almacen_id'];
        $fecha_estimada = !empty($_POST['fecha_estimada']) ? $_POST['fecha_estimada'] : null;
        $puerto_origen = trim($_POST['puerto_origen'] ?? '');
        $puerto_destino = trim($_POST['puerto_destino'] ?? '');
        $contenedor = trim($_POST['contenedor'] ?? '');
        $naviera = trim($_POST['naviera'] ?? '');
        $conocimiento_embarque = trim($_POST['conocimiento_embarque'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $moneda = trim($_POST['moneda'] ?? 'USD');
        $asignado_id = !empty($_POST['asignado_id']) ? (int)$_POST['asignado_id'] : null;

        $stmt = $pdo->prepare("INSERT INTO importaciones (folio,proveedor_id,almacen_id,fecha_estimada,puerto_origen,puerto_destino,contenedor,naviera,conocimiento_embarque,observaciones,usuario_id,asignado_id,moneda) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$folio,$proveedor_id,$almacen_id,$fecha_estimada,$puerto_origen?:null,$puerto_destino?:null,$contenedor?:null,$naviera?:null,$conocimiento_embarque?:null,$observaciones?:null,$_SESSION['usuario_id'],$asignado_id,$moneda]);
        $imp_id = $pdo->lastInsertId();

        $productos = $_POST['producto_id'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];
        $precios = $_POST['precio_unitario'] ?? [];
        $tipo_cambio = (float)str_replace(',','', $_POST['tipo_cambio'] ?? 1);
        $total_mercancia = 0;

        for ($i = 0; $i < count($productos); $i++) {
            if (empty($productos[$i]) || empty($cantidades[$i])) continue;
            $cant = (float)str_replace(',','', $cantidades[$i]);
            $prec = (float)str_replace(',','', $precios[$i] ?? 0);
            $prec_mxn = $prec * $tipo_cambio;
            $subtotal_mxn = $cant * $prec_mxn;
            $total_mercancia += $subtotal_mxn;

            $pdo->prepare("INSERT INTO importacion_detalle (importacion_id,producto_id,cantidad,precio_unitario,precio_unitario_mxn,subtotal_mxn) VALUES (?,?,?,?,?,?)")
                ->execute([$imp_id, (int)$productos[$i], $cant, $prec, $prec_mxn, $subtotal_mxn]);
        }

        $pdo->prepare("UPDATE importaciones SET total_mercancia=? WHERE id=?")->execute([$total_mercancia, $imp_id]);
        $pdo->commit();
        alert('success', "Importacion creada. Folio: $folio");
        redirect("importaciones.php?action=detalle&id=$imp_id");

    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'agregar_gasto' && $id) {
    $tipo_gasto = $_POST['tipo_gasto'];
    $descripcion = trim($_POST['descripcion'] ?? '');
    $monto = (float)str_replace(',','', $_POST['monto'] ?? 0);
    $moneda = trim($_POST['moneda'] ?? 'USD');
    $tipo_cambio = (float)str_replace(',','', $_POST['tipo_cambio'] ?? 1);
    $monto_mxn = $monto * $tipo_cambio;
    $proveedor_id = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;

    $pdo->prepare("INSERT INTO importacion_gastos (importacion_id,tipo_gasto,descripcion,monto,moneda,monto_mxn,proveedor_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$id,$tipo_gasto,$descripcion?:null,$monto,$moneda,$monto_mxn,$proveedor_id]);

    $total = $pdo->prepare("SELECT SUM(monto_mxn) FROM importacion_gastos WHERE importacion_id=?");
    $total->execute([$id]);
    $pdo->prepare("UPDATE importaciones SET total_gastos=? WHERE id=?")->execute([$total->fetchColumn(), $id]);

    alert('success', 'Gasto agregado');
    redirect("importaciones.php?action=detalle&id=$id");
}

if ($action === 'recibir' && $id) {
    try {
        $pdo->beginTransaction();
        $imp = $pdo->prepare("SELECT * FROM importaciones WHERE id=?");
        $imp->execute([$id]); $imp = $imp->fetch();
        if (!$imp) throw new Exception("Importacion no encontrada");

        $detalles = $pdo->prepare("SELECT * FROM importacion_detalle WHERE importacion_id=?");
        $detalles->execute([$id]);

        foreach ($detalles as $d) {
            $pendiente = $d['cantidad'] - $d['cantidad_recibida'];
            if ($pendiente <= 0) continue;

            $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='ENTRADA'")->fetch();
            $folio_mov = 'IMP-' . $d['id'] . '-' . date('Ymd');

            $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id,folio,almacen_destino_id,producto_id,cantidad,costo_unitario,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$tm['id'],$folio_mov,$imp['almacen_id'],$d['producto_id'],$pendiente,$d['precio_unitario_mxn'],$imp['folio'],$_SESSION['usuario_id']]);

            $inv = $pdo->prepare("SELECT id FROM inventario WHERE almacen_id=? AND producto_id=?");
            $inv->execute([$imp['almacen_id'], $d['producto_id']]);
            $ex = $inv->fetch();
            if ($ex) {
                $pdo->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?")->execute([$pendiente, $ex['id']]);
            } else {
                $pdo->prepare("INSERT INTO inventario (almacen_id,producto_id,cantidad) VALUES (?,?,?)")->execute([$imp['almacen_id'], $d['producto_id'], $pendiente]);
            }

            $pdo->prepare("UPDATE importacion_detalle SET cantidad_recibida = cantidad_recibida + ? WHERE id=?")->execute([$pendiente, $d['id']]);
        }

        $pdo->prepare("UPDATE importaciones SET estatus='recibido', fecha_llegada=CURDATE() WHERE id=?")->execute([$id]);
        $pdo->commit();
        alert('success', 'Importacion recibida en inventario');
        redirect('importaciones.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error al recibir: ' . $e->getMessage());
    }
}

if ($action === 'status' && $id && isset($_GET['estatus'])) {
    $estatus = $_GET['estatus'];
    $validos = ['pedido','embarcado','en_aduanas','liberado','recibido','cancelado'];
    if (in_array($estatus, $validos)) {
        $pdo->prepare("UPDATE importaciones SET estatus=? WHERE id=?")->execute([$estatus, $id]);
        alert('success', "Estatus actualizado a: $estatus");
    }
    redirect("importaciones.php?action=detalle&id=$id");
}

$search = $_GET['search'] ?? '';
$sql = "SELECT i.*, p.nombre as proveedor, a.nombre as almacen FROM importaciones i JOIN proveedores p ON p.id=i.proveedor_id JOIN almacenes a ON a.id=i.almacen_id WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (i.folio LIKE ? OR p.nombre LIKE ? OR i.contenedor LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY i.created_at DESC";
$importaciones = $pdo->prepare($sql);
$importaciones->execute($params);

$proveedores = $pdo->query("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();
$empleados = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre, apellido")->fetchAll();

require 'includes/header.php';

if ($action === 'create'):
?>
<div class="card" style="max-width: 800px;">
    <div class="card-header"><h2>Nueva Importacion</h2></div>
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
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Empleado asignado</label>
                <select name="asignado_id">
                    <option value="">-- Sin asignar --</option>
                    <?php foreach ($empleados as $e): ?>
                    <option value="<?=$e['id']?>"><?=h($e['nombre'])?> <?=h($e['apellido'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Moneda</label>
                <select name="moneda">
                    <option value="USD">USD</option>
                    <option value="EUR">EUR</option>
                    <option value="CNY">CNY</option>
                    <option value="MXN">MXN</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Puerto origen</label>
                <input type="text" name="puerto_origen" placeholder="Ej: Shanghai">
            </div>
            <div class="form-group">
                <label>Puerto destino</label>
                <input type="text" name="puerto_destino" placeholder="Ej: Manzanillo">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Contenedor</label>
                <input type="text" name="contenedor" placeholder="Ej: MSCU1234567">
            </div>
            <div class="form-group">
                <label>Naviera</label>
                <input type="text" name="naviera" placeholder="Ej: Maersk">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Conocimiento embarque (BL)</label>
                <input type="text" name="conocimiento_embarque">
            </div>
            <div class="form-group">
                <label>Fecha estimada</label>
                <input type="date" name="fecha_estimada">
            </div>
        </div>
        <div class="form-group">
            <label>Observaciones</label>
            <textarea name="observaciones" rows="2"></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Tipo de cambio (1 USD = ? MXN)</label>
                <input type="number" step="0.01" name="tipo_cambio" value="20.00">
            </div>
        </div>

        <h3 style="margin:16px 0 8px;">Productos</h3>
        <div id="productos-container">
            <div class="form-row" style="gap:8px;margin-bottom:8px;">
                <div style="flex:2"><label>Producto</label></div>
                <div style="flex:1"><label>Cantidad</label></div>
                <div style="flex:1"><label>Precio unit.</label></div>
                <div style="width:40px"></div>
            </div>
            <div class="form-row" style="gap:8px;margin-bottom:8px;">
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
                <div style="width:40px"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">X</button></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" onclick="agregarFila()">+ Agregar producto</button>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-primary">Crear Importacion</button>
            <a href="importaciones.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<script>
function agregarFila() {
    const c = document.getElementById('productos-container');
    const div = document.createElement('div');
    div.className = 'form-row';
    div.style = 'gap:8px;margin-bottom:8px;';
    div.innerHTML = `
        <div style="flex:2"><select name="producto_id[]"><option value="">-- Producto --${<?=json_encode(array_map(function($p){return ['id'=>$p['id'],'nombre'=>$p['codigo'].' - '.$p['nombre']];}, $productos))?>.map(p=>'<option value="'+p.id+'">'+p.nombre+'</option>').join('')}</select></div>
        <div style="flex:1"><input type="number" step="0.01" name="cantidad[]" placeholder="0"></div>
        <div style="flex:1"><input type="number" step="0.01" name="precio_unitario[]" placeholder="0.00"></div>
        <div style="width:40px"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">X</button></div>
    `;
    c.appendChild(div);
}
</script>

<?php elseif ($action === 'detalle' && $id):
    $imp = $pdo->prepare("SELECT i.*, p.nombre as proveedor, a.nombre as almacen, e.nombre as asignado_nombre, e.apellido as asignado_ape FROM importaciones i JOIN proveedores p ON p.id=i.proveedor_id JOIN almacenes a ON a.id=i.almacen_id LEFT JOIN empleados e ON e.id=i.asignado_id WHERE i.id=?");
    $imp->execute([$id]); $imp = $imp->fetch();
    if (!$imp) { alert('danger', 'No encontrada'); redirect('importaciones.php'); }

    $detalles = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, um.codigo as umedida FROM importacion_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id WHERE d.importacion_id=?");
    $detalles->execute([$id]);

    $gastos = $pdo->prepare("SELECT g.*, p.nombre as proveedor_nombre FROM importacion_gastos g LEFT JOIN proveedores p ON p.id=g.proveedor_id WHERE g.importacion_id=?");
    $gastos->execute([$id]);

    $estatuses = ['pedido'=>'warning','embarcado'=>'info','en_aduanas'=>'secondary','liberado'=>'primary','recibido'=>'success','cancelado'=>'danger'];
    $siguientes = ['pedido'=>['embarcado'=>' Embarcar'],'embarcado'=>['en_aduanas'=>'🏛 En aduanas'],'en_aduanas'=>['liberado'=>'[OK] Liberar'],'liberado'=>['recibido'=>' Recibir']];
?>
<div class="card">
    <div class="card-header">
        <h2>Importacion: <?=h($imp['folio'])?></h2>
        <span class="badge badge-<?=$estatuses[$imp['estatus']]?>"><?=h($imp['estatus'])?></span>
    </div>
    <table>
        <tr><td><strong>Proveedor:</strong> <?=h($imp['proveedor'])?></td>
            <td><strong>Almacen:</strong> <?=h($imp['almacen'])?></td></tr>
        <tr><td><strong>Puerto origen:</strong> <?=h($imp['puerto_origen'] ?? '-')?></td>
            <td><strong>Puerto destino:</strong> <?=h($imp['puerto_destino'] ?? '-')?></td></tr>
        <tr><td><strong>Contenedor:</strong> <?=h($imp['contenedor'] ?? '-')?></td>
            <td><strong>Naviera:</strong> <?=h($imp['naviera'] ?? '-')?></td></tr>
        <tr><td><strong>BL:</strong> <?=h($imp['conocimiento_embarque'] ?? '-')?></td>
            <td><strong>Pedimento:</strong> <?=h($imp['pedimento'] ?? 'Pendiente')?></td></tr>
        <tr><td><strong>Fecha pedido:</strong> <?=$imp['fecha_pedido']?></td>
            <td><strong>Fecha est. llegada:</strong> <?=$imp['fecha_estimada'] ?? '-'?></td></tr>
        <?php if ($imp['asignado_nombre']): ?>
        <tr><td colspan="2"><strong>Asignado a:</strong> <?=h($imp['asignado_nombre'] . ' ' . $imp['asignado_ape'])?></td></tr>
        <?php endif; ?>
        <tr><td><strong>Total mercancia (MXN):</strong> <?=moneda($imp['total_mercancia'])?></td>
            <td><strong>Total gastos (MXN):</strong> <?=moneda($imp['total_gastos'])?></td></tr>
        <tr><td colspan="2"><strong>Costo total:</strong> <?=moneda($imp['total_mercancia'] + $imp['total_gastos'])?></td></tr>
    </table>

    <?php if ($imp['observaciones']): ?>
    <p><strong>Observaciones:</strong> <?=h($imp['observaciones'])?></p>
    <?php endif; ?>

    <?php if ($imp['estatus'] !== 'recibido' && $imp['estatus'] !== 'cancelado'): ?>
    <div class="form-row" style="gap:8px;margin:12px 0;">
        <?php foreach ($siguientes[$imp['estatus']] ?? [] as $est => $label): ?>
        <a href="importaciones.php?action=status&id=<?=$imp['id']?>&estatus=<?=$est?>" class="btn btn-sm btn-<?=$estatuses[$est]?>" onclick="return confirm('¿Cambiar estatus a «<?=$est?>»?')"><?=$label?></a>
        <?php endforeach; ?>
        <?php if ($imp['estatus'] === 'liberado'): ?>
        <a href="importaciones.php?recibir=1&id=<?=$imp['id']?>" class="btn btn-success" onclick="return confirm('¿Recibir todo en inventario?')"> Recibir todo</a>
        <?php endif; ?>
        <a href="importaciones.php?action=status&id=<?=$imp['id']?>&estatus=cancelado" class="btn btn-sm btn-danger" onclick="return confirm('¿Cancelar importacion?')">Cancelar</a>
    </div>
    <?php endif; ?>

    <h3 style="margin:16px 0 8px;">Productos</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Codigo</th><th>Producto</th><th>Cantidad</th><th>Precio USD</th><th>Precio MXN</th><th>Subtotal MXN</th><th>Recibido</th></tr>
            <?php foreach ($detalles as $d): ?>
            <tr>
                <td><?=h($d['codigo'])?></td>
                <td><?=h($d['producto'])?></td>
                <td><?=h($d['cantidad'])?> <?=h($d['umedida'])?></td>
                <td><?=number_format($d['precio_unitario'],4)?></td>
                <td><?=number_format($d['precio_unitario_mxn'],4)?></td>
                <td><?=moneda($d['subtotal_mxn'])?></td>
                <td><?=h($d['cantidad_recibida'])?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <h3 style="margin:16px 0 8px;">Gastos de importacion</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Tipo</th><th>Descripcion</th><th>Monto</th><th>Moneda</th><th>Monto MXN</th><th>Proveedor</th></tr>
            <?php foreach ($gastos as $g): ?>
            <tr>
                <td><?=h($g['tipo_gasto'])?></td>
                <td><?=h($g['descripcion'] ?? '-')?></td>
                <td><?=number_format($g['monto'],2)?></td>
                <td><?=h($g['moneda'])?></td>
                <td><?=moneda($g['monto_mxn'])?></td>
                <td><?=h($g['proveedor_nombre'] ?? '-')?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($imp['estatus'] !== 'recibido' && $imp['estatus'] !== 'cancelado'): ?>
    <details style="margin-top:12px;">
        <summary class="btn btn-sm btn-secondary">+ Agregar gasto</summary>
        <form method="post" action="importaciones.php?action=agregar_gasto&id=<?=$imp['id']?>" style="margin-top:8px;padding:12px;background:#f9f9f9;border-radius:6px;">
            <div class="form-row">
                <div class="form-group">
                    <label>Tipo gasto</label>
                    <select name="tipo_gasto">
                        <option value="flete">Flete</option>
                        <option value="seguro">Seguro</option>
                        <option value="arancel">Arancel</option>
                        <option value="agente_aduanal">Agente aduanal</option>
                        <option value="manejo_portuario">Manejo portuario</option>
                        <option value="transporte_interno">Transporte interno</option>
                        <option value="otros">Otros</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Monto</label>
                    <input type="number" step="0.01" name="monto" required>
                </div>
                <div class="form-group">
                    <label>Moneda</label>
                    <select name="moneda">
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                        <option value="MXN">MXN</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>T.C. (1 USD/EUR = ? MXN)</label>
                    <input type="number" step="0.01" name="tipo_cambio" value="20.00">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label>Descripcion</label>
                    <input type="text" name="descripcion" placeholder="Descripcion del gasto">
                </div>
                <div class="form-group">
                    <label>Proveedor</label>
                    <select name="proveedor_id">
                        <option value="">-- Ninguno --</option>
                        <?php foreach ($proveedores as $p): ?>
                        <option value="<?=$p['id']?>"><?=h($p['nombre'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Agregar gasto</button>
        </form>
    </details>
    <?php endif; ?>

    <div class="form-actions" style="margin-top:16px;">
        <a href="importaciones.php" class="btn btn-secondary"><- Volver</a>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Importaciones</h2>
        <a href="importaciones.php?action=create" class="btn btn-primary">+ Nueva</a>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar folio, proveedor o contenedor..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Proveedor</th><th>Almacen</th><th>Contenedor</th><th>Naviera</th><th>Estatus</th><th>Total MXN</th><th>Fecha</th><th>Acciones</th></tr>
            <?php foreach ($importaciones as $i): $estCol = ['pedido'=>'warning','embarcado'=>'info','en_aduanas'=>'secondary','liberado'=>'primary','recibido'=>'success','cancelado'=>'danger']; ?>
            <tr>
                <td><a href="importaciones.php?action=detalle&id=<?=$i['id']?>"><?=h($i['folio'])?></a></td>
                <td><?=h($i['proveedor'])?></td>
                <td><?=h($i['almacen'])?></td>
                <td><?=h($i['contenedor'] ?? '-')?></td>
                <td><?=h($i['naviera'] ?? '-')?></td>
                <td><span class="badge badge-<?=$estCol[$i['estatus']]?>"><?=h($i['estatus'])?></span></td>
                <td><?=moneda($i['total_mercancia']+$i['total_gastos'])?></td>
                <td><?=$i['created_at']?></td>
                <td><a href="importaciones.php?action=detalle&id=<?=$i['id']?>" class="btn btn-sm btn-info">Detalle</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
