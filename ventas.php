<?php
$titulo = 'Ventas';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(50) UNIQUE,
    cliente_id INT NOT NULL,
    almacen_id INT NOT NULL,
    fecha_venta DATE DEFAULT (CURRENT_DATE),
    fecha_entrega DATE,
    estatus ENUM('pendiente','surtido','entregado','cancelado') DEFAULT 'pendiente',
    forma_pago VARCHAR(50),
    observaciones TEXT,
    usuario_id INT NOT NULL,
    asignado_id INT,
    subtotal DECIMAL(12,2) DEFAULT 0,
    descuento DECIMAL(12,2) DEFAULT 0,
    impuesto DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (almacen_id) REFERENCES almacenes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS venta_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad DECIMAL(12,2) NOT NULL,
    precio_unitario DECIMAL(12,4) NOT NULL,
    descuento DECIMAL(12,2) DEFAULT 0,
    subtotal DECIMAL(12,2) NOT NULL,
    cantidad_surtida DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
require_once 'includes/surtir_common.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    try {
        $pdo->beginTransaction();
        $folio = 'VEN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $cliente_id = (int)$_POST['cliente_id'];
        $almacen_id = (int)$_POST['almacen_id'];
        $fecha_entrega = !empty($_POST['fecha_entrega']) ? $_POST['fecha_entrega'] : null;
        $forma_pago = trim($_POST['forma_pago'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $asignado_id = !empty($_POST['asignado_id']) ? (int)$_POST['asignado_id'] : null;
        $subtotal = 0;

        $stmt = $pdo->prepare("INSERT INTO ventas (folio,cliente_id,almacen_id,fecha_entrega,forma_pago,observaciones,usuario_id,asignado_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$folio,$cliente_id,$almacen_id,$fecha_entrega,$forma_pago?:null,$observaciones?:null,$_SESSION['usuario_id'],$asignado_id]);
        $venta_id = $pdo->lastInsertId();

        $productos = $_POST['producto_id'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];
        $precios = $_POST['precio_unitario'] ?? [];
        $descuentos = $_POST['descuento'] ?? [];

        for ($i = 0; $i < count($productos); $i++) {
            if (empty($productos[$i]) || empty($cantidades[$i])) continue;
            $cant = (float)str_replace(',','', $cantidades[$i]);
            $prec = (float)str_replace(',','', $precios[$i] ?? 0);
            $desc = (float)str_replace(',','', $descuentos[$i] ?? 0);
            $line_sub = $cant * $prec - $desc;
            $subtotal += $line_sub;
            $pdo->prepare("INSERT INTO venta_detalle (venta_id,producto_id,cantidad,precio_unitario,descuento,subtotal) VALUES (?,?,?,?,?,?)")
                ->execute([$venta_id, (int)$productos[$i], $cant, $prec, $desc, $line_sub]);
        }

        $descuento_global = (float)str_replace(',','', $_POST['descuento_global'] ?? 0);
        $impuesto = (float)str_replace(',','', $_POST['impuesto'] ?? 0);
        $total = $subtotal - $descuento_global + $impuesto;

        $pdo->prepare("UPDATE ventas SET subtotal=?,descuento=?,impuesto=?,total=? WHERE id=?")->execute([$subtotal,$descuento_global,$impuesto,$total,$venta_id]);
        $pdo->commit();

        // Notificar a empleados responsables de ubicaciones
        $prodsNotif = [];
        for ($i = 0; $i < count($productos); $i++) {
            if (empty($productos[$i]) || empty($cantidades[$i])) continue;
            $pdi = $pdo->prepare("SELECT codigo, nombre FROM productos WHERE id=?");
            $pdi->execute([(int)$productos[$i]]); $pd = $pdi->fetch();
            if ($pd) $prodsNotif[] = ['producto_id'=>(int)$productos[$i], 'codigo'=>$pd['codigo'], 'nombre'=>$pd['nombre'], 'cantidad'=>(float)str_replace(',','',$cantidades[$i])];
        }
        notificarResponsablesUbicacion('venta', $venta_id, $folio, $prodsNotif, $pdo);

        alert('success', "Venta creada. Folio: $folio");
        redirect("ventas.php?action=detalle&id=$venta_id");
    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if ($action === 'surtir' && $id) {
    $venta = $pdo->prepare("SELECT v.*, a.nombre as almacen_nombre, c.nombre as cliente_nombre FROM ventas v JOIN almacenes a ON a.id=v.almacen_id JOIN clientes c ON c.id=v.cliente_id WHERE v.id=?");
    $venta->execute([$id]); $venta = $venta->fetch();
    if (!$venta) { alert('danger', 'No encontrada'); redirect('ventas.php'); }

    $detalles = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, um.codigo as umedida FROM venta_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id WHERE d.venta_id=?");
    $detalles->execute([$id]); $detalles = $detalles->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $pdo->beginTransaction();
            foreach ($_POST['detalle'] as $detalle_id => $data) {
                $d = $pdo->prepare("SELECT * FROM venta_detalle WHERE id=?");
                $d->execute([$detalle_id]); $d = $d->fetch();
                if (!$d) continue;
                $pendiente = $d['cantidad'] - $d['cantidad_surtida'];
                if ($pendiente <= 0) continue;

                $total_asignado = 0;
                foreach ($data['almacen'] as $almacen_id => $cantidad) {
                    $cantidad = abs((float)str_replace(',', '', $cantidad));
                    if ($cantidad <= 0) continue;
                    $total_asignado += $cantidad;

                    $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='SALIDA'")->fetch();
                    $folio_mov = 'VEN-' . $detalle_id . '-' . $almacen_id . '-' . date('Ymd');
                    $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id,folio,almacen_origen_id,producto_id,cantidad,costo_unitario,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$tm['id'],$folio_mov,$almacen_id,$d['producto_id'],$cantidad,$d['precio_unitario'],$venta['folio'],$_SESSION['usuario_id']]);

                    $rows = $pdo->prepare("SELECT id, cantidad FROM inventario WHERE almacen_id=? AND producto_id=? AND cantidad>0 ORDER BY lote IS NULL, lote, fecha_caducidad IS NULL, fecha_caducidad");
                    $rows->execute([$almacen_id, $d['producto_id']]);
                    $restante = $cantidad;
                    while ($r = $rows->fetch()) {
                        if ($restante <= 0) break;
                        $tomar = min($r['cantidad'], $restante);
                        $pdo->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=?")->execute([$tomar, $r['id']]);
                        $restante -= $tomar;
                    }
                    if ($restante > 0) throw new Exception("Stock insuficiente en almacen para {$d['producto_id']}");
                }
                if (abs($total_asignado - $pendiente) > 0.001) throw new Exception("Cantidad asignada ($total_asignado) no coincide con pendiente ($pendiente) para {$d['producto']}");
                $pdo->prepare("UPDATE venta_detalle SET cantidad_surtida = cantidad_surtida + ? WHERE id=?")->execute([$pendiente, $detalle_id]);
            }
            $pdo->prepare("UPDATE ventas SET estatus='surtido' WHERE id=?")->execute([$id]);
            $pdo->commit();
            alert('success', 'Venta surtida, inventario descontado');
            redirect('ventas.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            alert('danger', 'Error al surtir: ' . $e->getMessage());
        }
    }

    $auto_surtir = true;
    $sin_stock = [];
    foreach ($detalles as $d) {
        $pendiente = $d['cantidad'] - $d['cantidad_surtida'];
        if ($pendiente <= 0) continue;
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM inventario WHERE almacen_id=? AND producto_id=? AND cantidad>0");
        $stmt->execute([$venta['almacen_id'], $d['producto_id']]);
        $disp = (float)$stmt->fetchColumn();
        if ($disp < $pendiente) {
            $auto_surtir = false;
            $sin_stock[] = ['codigo'=>$d['codigo'],'producto'=>$d['producto'],'umedida'=>$d['umedida'],'requerido'=>$pendiente,'disponible'=>$disp,'detalle_id'=>$d['id'],'producto_id'=>$d['producto_id']];
        }
    }

    if ($auto_surtir) {
        try {
            $pdo->beginTransaction();
            foreach ($detalles as $d) {
                $pendiente = $d['cantidad'] - $d['cantidad_surtida'];
                if ($pendiente <= 0) continue;
                $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='SALIDA'")->fetch();
                $folio_mov = 'VEN-' . $d['id'] . '-' . date('Ymd');
                $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id,folio,almacen_origen_id,producto_id,cantidad,costo_unitario,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$tm['id'],$folio_mov,$venta['almacen_id'],$d['producto_id'],$pendiente,$d['precio_unitario'],$venta['folio'],$_SESSION['usuario_id']]);
                $rows = $pdo->prepare("SELECT id, cantidad FROM inventario WHERE almacen_id=? AND producto_id=? AND cantidad>0 ORDER BY lote IS NULL, lote, fecha_caducidad IS NULL, fecha_caducidad");
                $rows->execute([$venta['almacen_id'], $d['producto_id']]);
                $restante = $pendiente;
                while ($r = $rows->fetch()) {
                    if ($restante <= 0) break;
                    $tomar = min($r['cantidad'], $restante);
                    $pdo->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=?")->execute([$tomar, $r['id']]);
                    $restante -= $tomar;
                }
                $pdo->prepare("UPDATE venta_detalle SET cantidad_surtida = cantidad_surtida + ? WHERE id=?")->execute([$pendiente, $d['id']]);
            }
            $pdo->prepare("UPDATE ventas SET estatus='surtido' WHERE id=?")->execute([$id]);
            $pdo->commit();
            alert('success', 'Venta surtida, inventario descontado');
            redirect('ventas.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            alert('danger', 'Error al surtir: ' . $e->getMessage());
        }
    }
}

if ($action === 'status' && $id && isset($_GET['estatus'])) {
    $estatus = $_GET['estatus'];
    $validos = ['pendiente','surtido','entregado','cancelado'];
    if (in_array($estatus, $validos)) {
        $pdo->prepare("UPDATE ventas SET estatus=? WHERE id=?")->execute([$estatus, $id]);
        alert('success', "Estatus actualizado a: $estatus");
    }
    redirect("ventas.php?action=detalle&id=$id");
}

$search = $_GET['search'] ?? '';
$sql = "SELECT v.*, c.nombre as cliente, a.nombre as almacen FROM ventas v JOIN clientes c ON c.id=v.cliente_id JOIN almacenes a ON a.id=v.almacen_id WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (v.folio LIKE ? OR c.nombre LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY v.created_at DESC";
$ventas = $pdo->prepare($sql);
$ventas->execute($params);

$clientes = $pdo->query("SELECT * FROM clientes WHERE activo=1 ORDER BY nombre")->fetchAll();
$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre, precio_venta FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();
$empleados = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre, apellido")->fetchAll();

require 'includes/header.php';

if ($action === 'create'):
?>
<div class="card" style="max-width: 800px;">
    <div class="card-header"><h2>Nueva Venta</h2></div>
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
                <label>Forma de pago</label>
                <select name="forma_pago">
                    <option value="">-- Seleccionar --</option>
                    <option value="contado">Contado</option>
                    <option value="credito">Credito</option>
                    <option value="tarjeta">Tarjeta</option>
                    <option value="transferencia">Transferencia</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Fecha de entrega</label>
                <input type="date" name="fecha_entrega">
            </div>
        </div>
        <div class="form-group">
            <label>Observaciones</label>
            <textarea name="observaciones" rows="2"></textarea>
        </div>

        <h3 style="margin:16px 0 8px;">Productos</h3>
        <div class="form-group" style="margin-bottom:12px;">
            <label>Escanear QR de producto <small style="color:#888">(coloca el cursor aqui y usa el lector)</small></label>
            <div style="display:flex;gap:8px;">
                <input type="text" id="qr_scan" placeholder="Escanea el codigo QR..." style="flex:1;font-size:1.1rem;letter-spacing:1px;" autofocus onkeydown="if(event.key==='Enter'){event.preventDefault();buscarQR();}">
                <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('qr_scan').value='';document.getElementById('qr_scan').focus()">Limpiar</button>
            </div>
            <div id="qr_status" style="font-size:.85rem;margin-top:4px;"></div>
        </div>
        <div id="productos-container">
            <div class="form-row" style="gap:8px;margin-bottom:8px;">
                <div style="flex:2"><label>Producto</label></div>
                <div style="flex:1"><label>Cantidad</label></div>
                <div style="flex:1"><label>Precio venta</label></div>
                <div style="flex:1"><label>Descto.</label></div>
                <div style="width:40px"></div>
            </div>
            <div class="form-row" style="gap:8px;margin-bottom:8px;">
                <div style="flex:2">
                    <select name="producto_id[]" onchange="ventaProducto(this)">
                        <option value="">-- Producto --</option>
                        <?php foreach ($productos as $p): ?>
                        <option value="<?=$p['id']?>"><?=h($p['codigo'])?> - <?=h($p['nombre'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1"><input type="number" step="0.01" name="cantidad[]" placeholder="0" oninput="ventaCalc()"></div>
                <div style="flex:1"><input type="number" step="0.01" name="precio_unitario[]" placeholder="0.00" oninput="ventaCalc()"></div>
                <div style="flex:1"><input type="number" step="0.01" name="descuento[]" placeholder="0" oninput="ventaCalc()"></div>
                <div style="width:40px"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove();ventaCalc()">X</button></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" onclick="ventaFila()">+ Agregar producto</button>

        <div class="form-row" style="margin-top:16px;">
            <div class="form-group">
                <label>Descuento global</label>
                <input type="number" step="0.01" name="descuento_global" id="descuento_global" value="0" oninput="ventaCalc()">
            </div>
            <div class="form-group">
                <label>IVA (16%)</label>
                <input type="number" step="0.01" name="impuesto" id="impuesto" value="0" oninput="ventaIvaEdit()">
                <small style="color:#888;">Se auto-calcula al 16%, puedes editarlo manualmente</small>
            </div>
        </div>
        <div class="form-row" style="gap:16px;margin-top:8px;">
            <div><strong>Subtotal:</strong> $<span id="preview_subtotal">0.00</span></div>
            <div><strong>Total:</strong> $<span id="preview_total">0.00</span></div>
        </div>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-primary">Crear Venta</button>
            <a href="ventas.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<script>
const ventaPrecios = <?=json_encode(array_combine(array_column($productos,'id'), array_column($productos,'precio_venta')))?>;
let ventaIvaManual = false;

function ventaProducto(sel) {
    const f = sel.closest('.form-row');
    const inputs = f.querySelectorAll('input');
    inputs[1].value = ventaPrecios[sel.value] || '';
    ventaCalc();
}

function ventaCalc() {
    const filas = document.querySelectorAll('#productos-container .form-row:not(:first-child)');
    let st = 0;
    for (let i = 0; i < filas.length; i++) {
        const inp = filas[i].querySelectorAll('input');
        const c = parseFloat(inp[0].value) || 0;
        const p = parseFloat(inp[1].value) || 0;
        const d = parseFloat(inp[2].value) || 0;
        st += c * p - d;
    }
    const dg = parseFloat(document.getElementById('descuento_global').value) || 0;
    const base = st - dg;
    if (!ventaIvaManual) {
        const iva = base > 0 ? base * 0.16 : 0;
        document.getElementById('impuesto').value = iva.toFixed(2);
    }
    const ivaF = parseFloat(document.getElementById('impuesto').value) || 0;
    document.getElementById('preview_subtotal').textContent = st.toFixed(2);
    document.getElementById('preview_total').textContent = (st - dg + ivaF).toFixed(2);
}

function ventaIvaEdit() {
    ventaIvaManual = true;
    ventaCalc();
}

function ventaFila() {
    const c = document.getElementById('productos-container');
    const div = document.createElement('div');
    div.className = 'form-row';
    div.style = 'gap:8px;margin-bottom:8px;';
    const opts = <?=json_encode(array_map(function($p){return ['id'=>$p['id'],'nombre'=>$p['codigo'].' - '.$p['nombre'],'precio'=>$p['precio_venta']];}, $productos))?>;
    div.innerHTML = `
        <div style="flex:2"><select name="producto_id[]" onchange="ventaProducto(this)"><option value="">-- Producto --${opts.map(p=>'<option value="'+p.id+'">'+p.nombre+'</option>').join('')}</select></div>
        <div style="flex:1"><input type="number" step="0.01" name="cantidad[]" placeholder="0" oninput="ventaCalc()"></div>
        <div style="flex:1"><input type="number" step="0.01" name="precio_unitario[]" placeholder="0.00" oninput="ventaCalc()"></div>
        <div style="flex:1"><input type="number" step="0.01" name="descuento[]" placeholder="0" oninput="ventaCalc()"></div>
        <div style="width:40px"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove();ventaCalc()">X</button></div>
    `;
    c.appendChild(div);
}

function buscarQR() {
    const inp = document.getElementById('qr_scan');
    const q = inp.value.trim();
    if (!q) return;
    const st = document.getElementById('qr_status');
    st.innerHTML = 'Buscando...';
    st.style.color = '#888';
    fetch('qr_lookup.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                st.innerHTML = 'No encontrado: ' + d.error;
                st.style.color = '#e74c3c';
                inp.value = '';
                inp.focus();
                return;
            }
            // Check if product already in list
            const selects = document.querySelectorAll('#productos-container select[name="producto_id[]"]');
            for (let s of selects) { if (parseInt(s.value) === d.id) { st.innerHTML = d.codigo + ' - ' + d.nombre + ' ya agregado'; st.style.color = '#f39c12'; inp.value = ''; inp.focus(); return; } }
            const opts = <?=json_encode(array_map(function($p){return ['id'=>$p['id'],'nombre'=>$p['codigo'].' - '.$p['nombre'],'precio'=>$p['precio_venta']];}, $productos))?>;
            // Create row with product pre-selected
            const c = document.getElementById('productos-container');
            const div = document.createElement('div');
            div.className = 'form-row';
            div.style = 'gap:8px;margin-bottom:8px;';
            div.innerHTML = `
                <div style="flex:2"><select name="producto_id[]" onchange="ventaProducto(this)"><option value="">-- Producto --${opts.map(p=>'<option value="'+p.id+'"'+(p.id===d.id?' selected':'')+'>'+p.nombre+'</option>').join('')}</select></div>
                <div style="flex:1"><input type="number" step="0.01" name="cantidad[]" placeholder="0" value="1" oninput="ventaCalc()"></div>
                <div style="flex:1"><input type="number" step="0.01" name="precio_unitario[]" placeholder="0.00" value="${d.precio_venta}" oninput="ventaCalc()"></div>
                <div style="flex:1"><input type="number" step="0.01" name="descuento[]" placeholder="0" oninput="ventaCalc()"></div>
                <div style="width:40px"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove();ventaCalc()">X</button></div>
            `;
            c.appendChild(div);
            ventaCalc();
            st.innerHTML = '<b style="color:#27ae60">OK</b> ' + d.codigo + ' - ' + d.nombre + ' ($' + d.precio_venta.toFixed(2) + ')';
            inp.value = '';
            inp.focus();
        })
        .catch(e => {
            st.innerHTML = 'Error: ' + e.message;
            st.style.color = '#e74c3c';
            inp.focus();
        });
}

ventaCalc();
</script>

<?php elseif ($action === 'surtir' && $id):
    $almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
    $total_ok = true;
    foreach ($detalles as $d) {
        $pendiente = $d['cantidad'] - $d['cantidad_surtida'];
        if ($pendiente <= 0) continue;
        $total_ok = false;
    }
    if ($total_ok): redirect("ventas.php?action=detalle&id=$id"); endif;
?>
<div class="card" style="max-width:900px">
    <div class="card-header"><h2>Surtir Venta: <?=h($venta['folio'])?></h2></div>
    <p><strong>Cliente:</strong> <?=h($venta['cliente_nombre'])?> &nbsp; <strong>Almacen origen:</strong> <?=h($venta['almacen_nombre'])?></p>
    <?php if ($sin_stock): ?>
    <div class="alert alert-warning">Stock insuficiente en <?=h($venta['almacen_nombre'])?> para los siguientes productos. Asigna desde otros almacenes.</div>
    <?php endif; ?>
    <form method="post">
        <?php foreach ($detalles as $d):
            $pendiente = $d['cantidad'] - $d['cantidad_surtida'];
            if ($pendiente <= 0) continue;
            $stock_por_almacen = $pdo->prepare("SELECT a.id, a.nombre, COALESCE(SUM(i.cantidad),0) as total FROM almacenes a LEFT JOIN inventario i ON i.almacen_id=a.id AND i.producto_id=? AND i.cantidad>0 WHERE a.activo=1 GROUP BY a.id, a.nombre ORDER BY a.nombre");
            $stock_por_almacen->execute([$d['producto_id']]);
            $disponible_venta = 0;
            foreach ($stock_por_almacen as $sa) {
                if ((int)$sa['id'] === (int)$venta['almacen_id']) $disponible_venta = (float)$sa['total'];
            }
        ?>
        <div id="surtido-box-<?=$d['id']?>" class="card" style="margin:12px 0;padding:12px;background:#f9f9f9">
            <h4><?=h($d['codigo'])?> - <?=h($d['producto'])?> (<?=h($d['umedida'])?>)</h4>
            <p>Requerido: <strong><?=$pendiente?></strong> | En <?=h($venta['almacen_nombre'])?>: <strong><?=$disponible_venta?></strong></p>
            <table>
                <tr><th>Almacen</th><th>Disponible</th><th>A tomar</th></tr>
                <?php foreach ($stock_por_almacen as $sa):
                    $max = (float)$sa['total'];
                    $es_origen = (int)$sa['id'] === (int)$venta['almacen_id'];
                    $style = $es_origen ? 'style="background:#e8f5e9"' : '';
                ?>
                <tr <?=$style?>>
                    <td><?=h($sa['nombre'])?><?=$es_origen?' (origen)':''?></td>
                    <td><?=$max?></td>
                    <td><input type="number" step="0.01" min="0" max="<?=$max?>" name="detalle[<?=$d['id']?>][almacen][<?=$sa['id']?>]" value="<?=$es_origen?min($max,$pendiente):0?>" placeholder="0" oninput="sumarSurtido(<?=$d['id']?>,<?=$pendiente?>)"></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div id="total-<?=$d['id']?>" style="margin-top:4px;font-weight:bold">Asignado: 0 / <?=$pendiente?></div>
        </div>
        <?php endforeach; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Confirmar Surtido</button>
            <a href="ventas.php?action=detalle&id=<?=$id?>" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<script>
function sumarSurtido(detalleId, requerido) {
    const box = document.getElementById('surtido-box-' + detalleId);
    if (!box) return;
    const inputs = box.querySelectorAll('input[type="number"]');
    let total = 0;
    inputs.forEach(function(inp) { total += parseFloat(inp.value) || 0; });
    document.getElementById('total-' + detalleId).textContent = 'Asignado: ' + total.toFixed(2) + ' / ' + requerido;
}
<?php foreach ($detalles as $d): $p = $d['cantidad'] - $d['cantidad_surtida']; if ($p <= 0) continue; ?>sumarSurtido(<?=$d['id']?>,<?=$p?>);
<?php endforeach; ?>
</script>

<?php elseif ($action === 'detalle' && $id):
    $venta = $pdo->prepare("SELECT v.*, c.nombre as cliente, a.nombre as almacen, e.nombre as asignado_nombre, e.apellido as asignado_ape FROM ventas v JOIN clientes c ON c.id=v.cliente_id JOIN almacenes a ON a.id=v.almacen_id LEFT JOIN empleados e ON e.id=v.asignado_id WHERE v.id=?");
    $venta->execute([$id]); $venta = $venta->fetch();
    if (!$venta) { alert('danger', 'No encontrada'); redirect('ventas.php'); }

    $detalles = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, p.precio_venta, um.codigo as umedida FROM venta_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id WHERE d.venta_id=?");
    $detalles->execute([$id]);

    $estatuses = ['pendiente'=>'warning','surtido'=>'info','entregado'=>'success','cancelado'=>'danger'];
    $siguientes = ['pendiente'=>['surtido'=>' Surtir'],'surtido'=>['entregado'=>'[OK] Entregar']];
?>
<div class="card">
    <div class="card-header"><h2>Venta: <?=h($venta['folio'])?></h2>
        <span class="badge badge-<?=$estatuses[$venta['estatus']]?>"><?=h($venta['estatus'])?></span>
    </div>
    <table>
        <tr><td><strong>Cliente:</strong> <?=h($venta['cliente'])?></td>
            <td><strong>Almacen:</strong> <?=h($venta['almacen'])?></td></tr>
        <tr><td><strong>Fecha:</strong> <?=$venta['fecha_venta']?></td>
            <td><strong>Entrega:</strong> <?=$venta['fecha_entrega'] ?? 'Pendiente'?></td></tr>
        <tr><td><strong>Forma pago:</strong> <?=h($venta['forma_pago'] ?? '-')?></td>
            <td><strong>Asignado a:</strong> <?=h($venta['asignado_nombre'] ? $venta['asignado_nombre'].' '.$venta['asignado_ape'] : '-')?></td></tr>
    </table>
    <?php if ($venta['observaciones']): ?>
    <p><strong>Observaciones:</strong> <?=h($venta['observaciones'])?></p>
    <?php endif; ?>

    <?php if ($venta['estatus'] !== 'entregado' && $venta['estatus'] !== 'cancelado'): ?>
    <div class="form-row" style="gap:8px;margin:12px 0;">
        <?php foreach ($siguientes[$venta['estatus']] ?? [] as $est => $label): ?>
        <a href="ventas.php?action=status&id=<?=$venta['id']?>&estatus=<?=$est?>" class="btn btn-sm btn-<?=$estatuses[$est]?>" onclick="return confirm('¿Cambiar estatus a «<?=$est?>»?')"><?=$label?></a>
        <?php endforeach; ?>
        <?php if ($venta['estatus'] === 'pendiente'): ?>
        <a href="ventas.php?action=surtir&id=<?=$venta['id']?>" class="btn btn-primary" onclick="return confirm('¿Surtir y descontar inventario?')"> Surtir y descontar</a>
        <?php endif; ?>
        <a href="ventas.php?action=status&id=<?=$venta['id']?>&estatus=cancelado" class="btn btn-sm btn-danger" onclick="return confirm('¿Cancelar venta?')">Cancelar</a>
    </div>
    <?php endif; ?>

    <h3 style="margin:16px 0 8px;">Productos</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Codigo</th><th>Producto</th><th>Precio lista</th><th>Cantidad</th><th>Surtido</th><th>Precio venta</th><th>Descto.</th><th>Subtotal</th></tr>
            <?php foreach ($detalles as $d): ?>
            <tr>
                <td><?=h($d['codigo'])?></td>
                <td><?=h($d['producto'])?></td>
                <td><?=moneda($d['precio_venta'] ?? 0)?></td>
                <td><?=h($d['cantidad'])?> <?=h($d['umedida'])?></td>
                <td><?=h($d['cantidad_surtida'])?></td>
                <td><?=moneda($d['precio_unitario'])?></td>
                <td><?=moneda($d['descuento'])?></td>
                <td><?=moneda($d['subtotal'])?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <table style="margin-top:8px;">
        <tr><td><strong>Subtotal:</strong></td><td><?=moneda($venta['subtotal'])?></td></tr>
        <tr><td><strong>Descuento:</strong></td><td><?=moneda($venta['descuento'])?></td></tr>
        <tr><td><strong>Impuesto:</strong></td><td><?=moneda($venta['impuesto'])?></td></tr>
        <tr><td><strong>Total:</strong></td><td><?=moneda($venta['total'])?></td></tr>
    </table>

    <div class="form-actions" style="margin-top:16px;">
        <a href="prefactura.php?venta_id=<?=$venta['id']?>" class="btn btn-primary" target="_blank"> Prefactura PDF</a>
        <a href="ventas.php" class="btn btn-secondary"><- Volver</a>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Ventas</h2>
        <a href="ventas.php?action=create" class="btn btn-primary">+ Nueva</a>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar folio o cliente..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Cliente</th><th>Almacen</th><th>Total</th><th>Forma pago</th><th>Estatus</th><th>Fecha</th><th>Acciones</th></tr>
            <?php foreach ($ventas as $v): ?>
            <tr>
                <td><a href="ventas.php?action=detalle&id=<?=$v['id']?>"><?=h($v['folio'])?></a></td>
                <td><?=h($v['cliente'])?></td>
                <td><?=h($v['almacen'])?></td>
                <td><?=moneda($v['total'])?></td>
                <td><?=h($v['forma_pago'] ?? '-')?></td>
                <td><span class="badge badge-<?=$v['estatus']==='entregado'?'success':($v['estatus']==='cancelado'?'danger':($v['estatus']==='surtido'?'info':'warning'))?>"><?=h($v['estatus'])?></span></td>
                <td><?=$v['created_at']?></td>
                <td><a href="ventas.php?action=detalle&id=<?=$v['id']?>" class="btn btn-sm btn-info">Detalle</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
