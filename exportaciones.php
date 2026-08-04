<?php
$titulo = 'Exportaciones';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS exportaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(50) UNIQUE,
    cliente_id INT NOT NULL,
    almacen_id INT NOT NULL,
    fecha_pedido DATE DEFAULT (CURRENT_DATE),
    fecha_estimada DATE,
    fecha_embarque DATE,
    fecha_entrega DATE,
    estatus ENUM('cotizacion','pedido','produccion','embarcado','entregado','facturado','cancelado') DEFAULT 'cotizacion',
    puerto_salida VARCHAR(100),
    puerto_llegada VARCHAR(100),
    contenedor VARCHAR(50),
    naviera VARCHAR(100),
    conocimiento_embarque VARCHAR(100),
    pedimento VARCHAR(50),
    destino_pais VARCHAR(100) DEFAULT 'Mexico',
    destino_direccion TEXT,
    destino_ciudad VARCHAR(100),
    incoterm VARCHAR(10),
    observaciones TEXT,
    usuario_id INT NOT NULL,
    asignado_id INT,
    total_mercancia DECIMAL(12,2) DEFAULT 0,
    total_gastos DECIMAL(12,2) DEFAULT 0,
    moneda VARCHAR(3) DEFAULT 'USD',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (almacen_id) REFERENCES almacenes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS exportacion_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exportacion_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad DECIMAL(12,2) NOT NULL,
    precio_unitario DECIMAL(12,4) NOT NULL,
    precio_unitario_mxn DECIMAL(12,4) NOT NULL,
    subtotal_mxn DECIMAL(12,2) NOT NULL,
    cantidad_embarcada DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (exportacion_id) REFERENCES exportaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS exportacion_gastos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exportacion_id INT NOT NULL,
    tipo_gasto ENUM('flete','seguro','agente_aduanal','manejo_portuario','transporte_interno','certificacion','empaque','otros') NOT NULL,
    descripcion VARCHAR(255),
    monto DECIMAL(12,2) NOT NULL,
    moneda VARCHAR(3) DEFAULT 'USD',
    monto_mxn DECIMAL(12,2) NOT NULL,
    proveedor_id INT,
    FOREIGN KEY (exportacion_id) REFERENCES exportaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    try {
        $pdo->beginTransaction();

        $folio = 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $cliente_id = (int)$_POST['cliente_id'];
        $almacen_id = (int)$_POST['almacen_id'];
        $fecha_estimada = !empty($_POST['fecha_estimada']) ? $_POST['fecha_estimada'] : null;
        $puerto_salida = trim($_POST['puerto_salida'] ?? '');
        $puerto_llegada = trim($_POST['puerto_llegada'] ?? '');
        $contenedor = trim($_POST['contenedor'] ?? '');
        $naviera = trim($_POST['naviera'] ?? '');
        $conocimiento_embarque = trim($_POST['conocimiento_embarque'] ?? '');
        $destino_pais = trim($_POST['destino_pais'] ?? '');
        $destino_direccion = trim($_POST['destino_direccion'] ?? '');
        $destino_ciudad = trim($_POST['destino_ciudad'] ?? '');
        $incoterm = trim($_POST['incoterm'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $moneda = trim($_POST['moneda'] ?? 'USD');
        $asignado_id = !empty($_POST['asignado_id']) ? (int)$_POST['asignado_id'] : null;

        $stmt = $pdo->prepare("INSERT INTO exportaciones (folio,cliente_id,almacen_id,fecha_estimada,puerto_salida,puerto_llegada,contenedor,naviera,conocimiento_embarque,destino_pais,destino_direccion,destino_ciudad,incoterm,observaciones,usuario_id,asignado_id,moneda) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$folio,$cliente_id,$almacen_id,$fecha_estimada,$puerto_salida?:null,$puerto_llegada?:null,$contenedor?:null,$naviera?:null,$conocimiento_embarque?:null,$destino_pais?:null,$destino_direccion?:null,$destino_ciudad?:null,$incoterm?:null,$observaciones?:null,$_SESSION['usuario_id'],$asignado_id,$moneda]);
        $exp_id = $pdo->lastInsertId();

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

            $pdo->prepare("INSERT INTO exportacion_detalle (exportacion_id,producto_id,cantidad,precio_unitario,precio_unitario_mxn,subtotal_mxn) VALUES (?,?,?,?,?,?)")
                ->execute([$exp_id, (int)$productos[$i], $cant, $prec, $prec_mxn, $subtotal_mxn]);
        }

        $pdo->prepare("UPDATE exportaciones SET total_mercancia=? WHERE id=?")->execute([$total_mercancia, $exp_id]);
        $pdo->commit();
        alert('success', "Exportacion creada. Folio: $folio");
        redirect("exportaciones.php?action=detalle&id=$exp_id");

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

    $pdo->prepare("INSERT INTO exportacion_gastos (exportacion_id,tipo_gasto,descripcion,monto,moneda,monto_mxn,proveedor_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$id,$tipo_gasto,$descripcion?:null,$monto,$moneda,$monto_mxn,$proveedor_id]);

    $total = $pdo->prepare("SELECT SUM(monto_mxn) FROM exportacion_gastos WHERE exportacion_id=?");
    $total->execute([$id]);
    $pdo->prepare("UPDATE exportaciones SET total_gastos=? WHERE id=?")->execute([$total->fetchColumn(), $id]);

    alert('success', 'Gasto agregado');
    redirect("exportaciones.php?action=detalle&id=$id");
}

if ($action === 'embarcar' && $id) {
    try {
        $pdo->beginTransaction();
        $exp = $pdo->prepare("SELECT * FROM exportaciones WHERE id=?");
        $exp->execute([$id]); $exp = $exp->fetch();
        if (!$exp) throw new Exception("Exportacion no encontrada");

        $detalles = $pdo->prepare("SELECT * FROM exportacion_detalle WHERE exportacion_id=?");
        $detalles->execute([$id]);

        foreach ($detalles as $d) {
            $pendiente = $d['cantidad'] - $d['cantidad_embarcada'];
            if ($pendiente <= 0) continue;

            $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='SALIDA'")->fetch();
            $folio_mov = 'EXP-' . $d['id'] . '-' . date('Ymd');

            $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id,folio,almacen_origen_id,producto_id,cantidad,costo_unitario,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$tm['id'],$folio_mov,$exp['almacen_id'],$d['producto_id'],$pendiente,$d['precio_unitario_mxn'],$exp['folio'],$_SESSION['usuario_id']]);

            $inv = $pdo->prepare("SELECT id, cantidad FROM inventario WHERE almacen_id=? AND producto_id=?");
            $inv->execute([$exp['almacen_id'], $d['producto_id']]);
            $ex = $inv->fetch();
            if ($ex) {
                if ($ex['cantidad'] < $pendiente) throw new Exception("Stock insuficiente de producto ID {$d['producto_id']}: disponible {$ex['cantidad']}, requerido $pendiente");
                $pdo->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=?")->execute([$pendiente, $ex['id']]);
            } else {
                throw new Exception("Producto ID {$d['producto_id']} sin inventario en almacen");
            }

            $pdo->prepare("UPDATE exportacion_detalle SET cantidad_embarcada = cantidad_embarcada + ? WHERE id=?")->execute([$pendiente, $d['id']]);
        }

        $pdo->prepare("UPDATE exportaciones SET estatus='embarcado', fecha_embarque=CURDATE() WHERE id=?")->execute([$id]);
        $pdo->commit();
        alert('success', 'Exportacion embarcada, inventario descontado');
        redirect('exportaciones.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error al embarcar: ' . $e->getMessage());
    }
}

if ($action === 'status' && $id && isset($_GET['estatus'])) {
    $estatus = $_GET['estatus'];
    $validos = ['cotizacion','pedido','produccion','embarcado','entregado','facturado','cancelado'];
    if (in_array($estatus, $validos)) {
        $pdo->prepare("UPDATE exportaciones SET estatus=? WHERE id=?")->execute([$estatus, $id]);
        alert('success', "Estatus actualizado a: $estatus");
    }
    redirect("exportaciones.php?action=detalle&id=$id");
}

$search = $_GET['search'] ?? '';
$sql = "SELECT e.*, c.nombre as cliente, a.nombre as almacen FROM exportaciones e JOIN clientes c ON c.id=e.cliente_id JOIN almacenes a ON a.id=e.almacen_id WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (e.folio LIKE ? OR c.nombre LIKE ? OR e.contenedor LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY e.created_at DESC";
$exportaciones = $pdo->prepare($sql);
$exportaciones->execute($params);

$clientes = $pdo->query("SELECT * FROM clientes WHERE activo=1 ORDER BY nombre")->fetchAll();
$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();
$empleados = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre, apellido")->fetchAll();
$proveedores = $pdo->query("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();

require 'includes/header.php';

if ($action === 'create'):
?>
<div class="card" style="max-width: 800px;">
    <div class="card-header"><h2>Nueva Exportacion</h2></div>
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label>Cliente *</label>
                <select name="cliente_id" required>
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($clientes as $c): ?>
                    <option value="<?=$c['id']?>"><?=h($c['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Almacen origen *</label>
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
                    <option value="MXN">MXN</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Puerto salida</label>
                <input type="text" name="puerto_salida" placeholder="Ej: Manzanillo">
            </div>
            <div class="form-group">
                <label>Puerto llegada</label>
                <input type="text" name="puerto_llegada" placeholder="Ej: Los Angeles">
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
                <label>Incoterm</label>
                <select name="incoterm">
                    <option value="">-- Seleccionar --</option>
                    <option value="EXW">EXW</option>
                    <option value="FOB">FOB</option>
                    <option value="CIF">CIF</option>
                    <option value="DDP">DDP</option>
                    <option value="FCA">FCA</option>
                    <option value="CPT">CPT</option>
                    <option value="CIP">CIP</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group" style="flex:1;">
                <label>Pais destino</label>
                <input type="text" name="destino_pais" placeholder="Ej: Estados Unidos">
            </div>
            <div class="form-group" style="flex:1;">
                <label>Ciudad destino</label>
                <input type="text" name="destino_ciudad">
            </div>
            <div class="form-group">
                <label>Fecha est. embarque</label>
                <input type="date" name="fecha_estimada">
            </div>
        </div>
        <div class="form-group">
            <label>Direccion destino</label>
            <textarea name="destino_direccion" rows="2"></textarea>
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
            <button type="submit" class="btn btn-primary">Crear Exportacion</button>
            <a href="exportaciones.php" class="btn btn-secondary">Cancelar</a>
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
    $exp = $pdo->prepare("SELECT e.*, c.nombre as cliente, a.nombre as almacen, emp.nombre as asignado_nombre, emp.apellido as asignado_ape FROM exportaciones e JOIN clientes c ON c.id=e.cliente_id JOIN almacenes a ON a.id=e.almacen_id LEFT JOIN empleados emp ON emp.id=e.asignado_id WHERE e.id=?");
    $exp->execute([$id]); $exp = $exp->fetch();
    if (!$exp) { alert('danger', 'No encontrada'); redirect('exportaciones.php'); }

    $detalles = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, um.codigo as umedida FROM exportacion_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id WHERE d.exportacion_id=?");
    $detalles->execute([$id]);

    $gastos = $pdo->prepare("SELECT g.*, p.nombre as proveedor_nombre FROM exportacion_gastos g LEFT JOIN proveedores p ON p.id=g.proveedor_id WHERE g.exportacion_id=?");
    $gastos->execute([$id]);

    $estatuses = ['cotizacion'=>'secondary','pedido'=>'warning','produccion'=>'info','embarcado'=>'primary','entregado'=>'success','facturado'=>'success','cancelado'=>'danger'];
    $siguientes = ['cotizacion'=>['pedido'=>' Pedido'],'pedido'=>['produccion'=>' Produccion'],'produccion'=>['embarcado'=>' Embarcar'],'embarcado'=>['entregado'=>'[OK] Entregado'],'entregado'=>['facturado'=>' Facturado']];
?>
<div class="card">
    <div class="card-header">
        <h2>Exportacion: <?=h($exp['folio'])?></h2>
        <span class="badge badge-<?=$estatuses[$exp['estatus']]?>"><?=h($exp['estatus'])?></span>
    </div>
    <table>
        <tr><td><strong>Cliente:</strong> <?=h($exp['cliente'])?></td>
            <td><strong>Almacen origen:</strong> <?=h($exp['almacen'])?></td></tr>
        <tr><td><strong>Puerto salida:</strong> <?=h($exp['puerto_salida'] ?? '-')?></td>
            <td><strong>Puerto llegada:</strong> <?=h($exp['puerto_llegada'] ?? '-')?></td></tr>
        <tr><td><strong>Contenedor:</strong> <?=h($exp['contenedor'] ?? '-')?></td>
            <td><strong>Naviera:</strong> <?=h($exp['naviera'] ?? '-')?></td></tr>
        <tr><td><strong>BL:</strong> <?=h($exp['conocimiento_embarque'] ?? '-')?></td>
            <td><strong>Pedimento:</strong> <?=h($exp['pedimento'] ?? 'Pendiente')?></td></tr>
        <tr><td><strong>Destino:</strong> <?=h($exp['destino_pais'] ?? '-')?></td>
            <td><strong>Ciudad:</strong> <?=h($exp['destino_ciudad'] ?? '-')?></td></tr>
        <tr><td><strong>Incoterm:</strong> <?=h($exp['incoterm'] ?? '-')?></td>
            <td><strong>Fecha est. embarque:</strong> <?=$exp['fecha_estimada'] ?? '-'?></td></tr>
        <?php if ($exp['fecha_embarque']): ?>
        <tr><td><strong>Fecha embarque:</strong> <?=$exp['fecha_embarque']?></td>
            <td><strong>Fecha entrega:</strong> <?=$exp['fecha_entrega'] ?? '-'?></td></tr>
        <?php endif; ?>
        <?php if ($exp['asignado_nombre']): ?>
        <tr><td colspan="2"><strong>Asignado a:</strong> <?=h($exp['asignado_nombre'] . ' ' . $exp['asignado_ape'])?></td></tr>
        <?php endif; ?>
        <tr><td><strong>Total mercancia (MXN):</strong> <?=moneda($exp['total_mercancia'])?></td>
            <td><strong>Total gastos (MXN):</strong> <?=moneda($exp['total_gastos'])?></td></tr>
        <tr><td colspan="2"><strong>Costo total:</strong> <?=moneda($exp['total_mercancia'] + $exp['total_gastos'])?></td></tr>
    </table>

    <?php if ($exp['destino_direccion']): ?>
    <p><strong>Direccion destino:</strong> <?=h($exp['destino_direccion'])?></p>
    <?php endif; ?>
    <?php if ($exp['observaciones']): ?>
    <p><strong>Observaciones:</strong> <?=h($exp['observaciones'])?></p>
    <?php endif; ?>

    <?php if ($exp['estatus'] !== 'entregado' && $exp['estatus'] !== 'facturado' && $exp['estatus'] !== 'cancelado'): ?>
    <div class="form-row" style="gap:8px;margin:12px 0;">
        <?php foreach ($siguientes[$exp['estatus']] ?? [] as $est => $label): ?>
        <a href="exportaciones.php?action=status&id=<?=$exp['id']?>&estatus=<?=$est?>" class="btn btn-sm btn-<?=$estatuses[$est]?>" onclick="return confirm('¿Cambiar estatus a «<?=$est?>»?')"><?=$label?></a>
        <?php endforeach; ?>
        <?php if ($exp['estatus'] === 'produccion'): ?>
        <a href="exportaciones.php?embarcar=1&id=<?=$exp['id']?>" class="btn btn-primary" onclick="return confirm('¿Embarcar y descontar inventario?')"> Embarcar y descontar</a>
        <?php endif; ?>
        <a href="exportaciones.php?action=status&id=<?=$exp['id']?>&estatus=cancelado" class="btn btn-sm btn-danger" onclick="return confirm('¿Cancelar exportacion?')">Cancelar</a>
    </div>
    <?php endif; ?>

    <h3 style="margin:16px 0 8px;">Productos</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Codigo</th><th>Producto</th><th>Cantidad</th><th>Precio USD</th><th>Precio MXN</th><th>Subtotal MXN</th><th>Embarcado</th></tr>
            <?php foreach ($detalles as $d): ?>
            <tr>
                <td><?=h($d['codigo'])?></td>
                <td><?=h($d['producto'])?></td>
                <td><?=h($d['cantidad'])?> <?=h($d['umedida'])?></td>
                <td><?=number_format($d['precio_unitario'],4)?></td>
                <td><?=number_format($d['precio_unitario_mxn'],4)?></td>
                <td><?=moneda($d['subtotal_mxn'])?></td>
                <td><?=h($d['cantidad_embarcada'])?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <h3 style="margin:16px 0 8px;">Gastos de exportacion</h3>
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

    <?php if ($exp['estatus'] !== 'entregado' && $exp['estatus'] !== 'facturado' && $exp['estatus'] !== 'cancelado'): ?>
    <details style="margin-top:12px;">
        <summary class="btn btn-sm btn-secondary">+ Agregar gasto</summary>
        <form method="post" action="exportaciones.php?action=agregar_gasto&id=<?=$exp['id']?>" style="margin-top:8px;padding:12px;background:#f9f9f9;border-radius:6px;">
            <div class="form-row">
                <div class="form-group">
                    <label>Tipo gasto</label>
                    <select name="tipo_gasto">
                        <option value="flete">Flete</option>
                        <option value="seguro">Seguro</option>
                        <option value="agente_aduanal">Agente aduanal</option>
                        <option value="manejo_portuario">Manejo portuario</option>
                        <option value="transporte_interno">Transporte interno</option>
                        <option value="certificacion">Certificacion</option>
                        <option value="empaque">Empaque</option>
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
        <a href="exportaciones.php" class="btn btn-secondary"><- Volver</a>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Exportaciones</h2>
        <a href="exportaciones.php?action=create" class="btn btn-primary">+ Nueva</a>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar folio, cliente o contenedor..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Cliente</th><th>Almacen</th><th>Contenedor</th><th>Naviera</th><th>Destino</th><th>Estatus</th><th>Total MXN</th><th>Fecha</th><th>Acciones</th></tr>
            <?php foreach ($exportaciones as $e): $estCol = ['cotizacion'=>'secondary','pedido'=>'warning','produccion'=>'info','embarcado'=>'primary','entregado'=>'success','facturado'=>'success','cancelado'=>'danger']; ?>
            <tr>
                <td><a href="exportaciones.php?action=detalle&id=<?=$e['id']?>"><?=h($e['folio'])?></a></td>
                <td><?=h($e['cliente'])?></td>
                <td><?=h($e['almacen'])?></td>
                <td><?=h($e['contenedor'] ?? '-')?></td>
                <td><?=h($e['naviera'] ?? '-')?></td>
                <td><?=h($e['destino_pais'] ?? '-')?></td>
                <td><span class="badge badge-<?=$estCol[$e['estatus']]?>"><?=h($e['estatus'])?></span></td>
                <td><?=moneda($e['total_mercancia']+$e['total_gastos'])?></td>
                <td><?=$e['created_at']?></td>
                <td><a href="exportaciones.php?action=detalle&id=<?=$e['id']?>" class="btn btn-sm btn-info">Detalle</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
