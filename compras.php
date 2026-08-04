<?php
$titulo = 'Compras';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$pdo->exec("CREATE TABLE IF NOT EXISTS compras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(50) UNIQUE,
    proveedor_id INT NOT NULL,
    almacen_id INT NOT NULL,
    fecha_compra DATE DEFAULT (CURRENT_DATE),
    fecha_entrega DATE,
    estatus ENUM('pendiente','recibida','cancelada') DEFAULT 'pendiente',
    forma_pago VARCHAR(50),
    observaciones TEXT,
    usuario_id INT NOT NULL,
    asignado_id INT,
    total DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
    FOREIGN KEY (almacen_id) REFERENCES almacenes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS compra_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compra_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad DECIMAL(12,2) NOT NULL,
    precio_unitario DECIMAL(12,4) NOT NULL,
    cantidad_recibida DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS facturas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compra_id INT NOT NULL,
    folio_factura VARCHAR(100),
    uuid VARCHAR(36),
    rfc_emisor VARCHAR(20),
    rfc_receptor VARCHAR(20),
    monto DECIMAL(12,2),
    fecha_factura DATE,
    archivo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    try {
        $pdo->beginTransaction();
        $folio = 'COM-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $proveedor_id = (int)$_POST['proveedor_id'];
        $almacen_id = (int)$_POST['almacen_id'];
        $fecha_entrega = !empty($_POST['fecha_entrega']) ? $_POST['fecha_entrega'] : null;
        $forma_pago = trim($_POST['forma_pago'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $asignado_id = !empty($_POST['asignado_id']) ? (int)$_POST['asignado_id'] : null;
        $total = 0;

        $stmt = $pdo->prepare("INSERT INTO compras (folio,proveedor_id,almacen_id,fecha_entrega,forma_pago,observaciones,usuario_id,asignado_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$folio,$proveedor_id,$almacen_id,$fecha_entrega,$forma_pago?:null,$observaciones?:null,$_SESSION['usuario_id'],$asignado_id]);
        $compra_id = $pdo->lastInsertId();

        $productos = $_POST['producto_id'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];
        $precios = $_POST['precio_unitario'] ?? [];

        for ($i = 0; $i < count($productos); $i++) {
            if (empty($productos[$i]) || empty($cantidades[$i])) continue;
            $cant = (float)str_replace(',','', $cantidades[$i]);
            $prec = (float)str_replace(',','', $precios[$i] ?? 0);
            $subtotal = $cant * $prec;
            $total += $subtotal;
            $pdo->prepare("INSERT INTO compra_detalle (compra_id,producto_id,cantidad,precio_unitario) VALUES (?,?,?,?)")
                ->execute([$compra_id, (int)$productos[$i], $cant, $prec]);
        }

        $pdo->prepare("UPDATE compras SET total=? WHERE id=?")->execute([$total, $compra_id]);
        $pdo->commit();
        alert('success', "Compra creada. Folio: $folio");
        redirect("compras.php?action=detalle&id=$compra_id");
    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

if (isset($_GET['recibir']) && $id) {
    try {
        $pdo->beginTransaction();
        $compra = $pdo->prepare("SELECT * FROM compras WHERE id=?");
        $compra->execute([$id]); $compra = $compra->fetch();
        if (!$compra) throw new Exception("Compra no encontrada");

        $detalles = $pdo->prepare("SELECT * FROM compra_detalle WHERE compra_id=?");
        $detalles->execute([$id]);

        foreach ($detalles as $d) {
            $pendiente = $d['cantidad'] - $d['cantidad_recibida'];
            if ($pendiente <= 0) continue;

            $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='ENTRADA'")->fetch();
            $folio_mov = 'COM-' . $d['id'] . '-' . date('Ymd');

            $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id,folio,almacen_destino_id,producto_id,cantidad,costo_unitario,referencia,usuario_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$tm['id'],$folio_mov,$compra['almacen_id'],$d['producto_id'],$pendiente,$d['precio_unitario'],$compra['folio'],$_SESSION['usuario_id']]);

            $inv = $pdo->prepare("SELECT id, cantidad FROM inventario WHERE almacen_id=? AND producto_id=?");
            $inv->execute([$compra['almacen_id'], $d['producto_id']]);
            $ex = $inv->fetch();
            if ($ex) {
                $pdo->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?")->execute([$pendiente, $ex['id']]);
            } else {
                $pdo->prepare("INSERT INTO inventario (almacen_id,producto_id,cantidad) VALUES (?,?,?)")->execute([$compra['almacen_id'], $d['producto_id'], $pendiente]);
            }

            $pdo->prepare("UPDATE compra_detalle SET cantidad_recibida = cantidad_recibida + ? WHERE id=?")->execute([$pendiente, $d['id']]);
        }

        $pdo->prepare("UPDATE compras SET estatus='recibida', fecha_entrega=CURDATE() WHERE id=?")->execute([$id]);
        $pdo->commit();
        alert('success', 'Compra recibida en inventario');
        redirect('compras.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error al recibir: ' . $e->getMessage());
    }
}

if ($action === 'cancelar' && $id) {
    $pdo->prepare("UPDATE compras SET estatus='cancelada' WHERE id=?")->execute([$id]);
    alert('success', 'Compra cancelada');
    redirect("compras.php?action=detalle&id=$id");
}

if ($action === 'importar_factura' && $id) {
    $compra = $pdo->prepare("SELECT * FROM compras WHERE id=?");
    $compra->execute([$id]); $compra = $compra->fetch();
    if (!$compra) { alert('danger', 'No encontrada'); redirect('compras.php'); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $folio_factura = trim($_POST['folio_factura'] ?? '');
        $uuid = trim($_POST['uuid'] ?? '');
        $rfc_emisor = trim($_POST['rfc_emisor'] ?? '');
        $rfc_receptor = trim($_POST['rfc_receptor'] ?? '');
        $monto = (float)str_replace(',', '', $_POST['monto'] ?? 0);
        $fecha_factura = !empty($_POST['fecha_factura']) ? $_POST['fecha_factura'] : null;
        $archivo = '';

        if (isset($_FILES['xml']) && $_FILES['xml']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['xml']['tmp_name'];
            $xml_content = file_get_contents($tmp);
            try {
                $xml = new SimpleXMLElement($xml_content);
                $ns = $xml->getNamespaces(true);
                $cfdi_ns = '';
                foreach (['http://www.sat.gob.mx/cfd/4','http://www.sat.gob.mx/cfd/3'] as $cns) {
                    if (in_array($cns, $ns)) { $cfdi_ns = $cns; break; }
                }
                if ($cfdi_ns) {
                    $cfdi = $xml->children($cfdi_ns);
                    if (empty($folio_factura)) $folio_factura = (string)$cfdi['Folio'];
                    if (empty($rfc_emisor)) $rfc_emisor = (string)$cfdi->Emisor['Rfc'];
                    if (empty($rfc_receptor)) $rfc_receptor = (string)$cfdi->Receptor['Rfc'];
                    if (empty($monto)) $monto = (float)$cfdi['Total'];
                    if (empty($fecha_factura)) $fecha_factura = substr((string)$cfdi['Fecha'], 0, 10);
                    $complemento = $cfdi->Complemento;
                    if ($complemento) {
                        $cns = $complemento->getNamespaces(true);
                        foreach ($cns as $prefix => $uri) {
                            if (strpos($uri, 'TimbreFiscalDigital') !== false) {
                                $tfd = $complemento->children($uri);
                                if ($tfd && empty($uuid)) $uuid = (string)$tfd->TimbreFiscalDigital['UUID'];
                            }
                        }
                    }
                }
            } catch (Exception $e) {}

            $dir = 'uploads/facturas/';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $archivo = $dir . $id . '_' . date('Ymd_His') . '.xml';
            move_uploaded_file($tmp, $archivo);
        }

        $pdo->prepare("INSERT INTO facturas (compra_id,folio_factura,uuid,rfc_emisor,rfc_receptor,monto,fecha_factura,archivo) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$id,$folio_factura,$uuid,$rfc_emisor,$rfc_receptor,$monto,$fecha_factura,$archivo]);
        alert('success', 'Factura importada');
        redirect("compras.php?action=detalle&id=$id");
    }
}

$search = $_GET['search'] ?? '';
$sql = "SELECT c.*, p.nombre as proveedor, a.nombre as almacen FROM compras c JOIN proveedores p ON p.id=c.proveedor_id JOIN almacenes a ON a.id=c.almacen_id WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (c.folio LIKE ? OR p.nombre LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY c.created_at DESC";
$compras = $pdo->prepare($sql);
$compras->execute($params);

$proveedores = $pdo->query("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();
$empleados = $pdo->query("SELECT id, nombre, apellido FROM empleados WHERE activo=1 ORDER BY nombre, apellido")->fetchAll();

require 'includes/header.php';

if ($action === 'create'):
?>
<div class="card" style="max-width: 800px;">
    <div class="card-header"><h2>Nueva Compra</h2></div>
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
                <label>Forma de pago</label>
                <select name="forma_pago">
                    <option value="">-- Seleccionar --</option>
                    <option value="contado">Contado</option>
                    <option value="credito">Credito</option>
                    <option value="cheque">Cheque</option>
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
            <button type="submit" class="btn btn-primary">Crear Compra</button>
            <a href="compras.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
<script>
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
            const selects = document.querySelectorAll('#productos-container select[name="producto_id[]"]');
            for (let s of selects) { if (parseInt(s.value) === d.id) { st.innerHTML = d.codigo + ' - ' + d.nombre + ' ya agregado'; st.style.color = '#f39c12'; inp.value = ''; inp.focus(); return; } }
            const opts = <?=json_encode(array_map(function($p){return ['id'=>$p['id'],'nombre'=>$p['codigo'].' - '.$p['nombre']];}, $productos))?>;
            const c = document.getElementById('productos-container');
            const div = document.createElement('div');
            div.className = 'form-row';
            div.style = 'gap:8px;margin-bottom:8px;';
            div.innerHTML = `
                <div style="flex:2"><select name="producto_id[]"><option value="">-- Producto --${opts.map(p=>'<option value="'+p.id+'"'+(p.id===d.id?' selected':'')+'>'+p.nombre+'</option>').join('')}</select></div>
                <div style="flex:1"><input type="number" step="0.01" name="cantidad[]" placeholder="0" value="1"></div>
                <div style="flex:1"><input type="number" step="0.01" name="precio_unitario[]" placeholder="0.00"></div>
                <div style="width:40px"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">X</button></div>
            `;
            c.appendChild(div);
            st.innerHTML = '<b style="color:#27ae60">OK</b> ' + d.codigo + ' - ' + d.nombre;
            inp.value = '';
            inp.focus();
        })
        .catch(e => {
            st.innerHTML = 'Error: ' + e.message;
            st.style.color = '#e74c3c';
            inp.focus();
        });
}

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

<?php elseif ($action === 'importar_factura' && $id):
    $compra = $pdo->prepare("SELECT c.*, p.nombre as proveedor FROM compras c JOIN proveedores p ON p.id=c.proveedor_id WHERE c.id=?");
    $compra->execute([$id]); $compra = $compra->fetch();
    if (!$compra) { alert('danger', 'No encontrada'); redirect('compras.php'); }
    $facturas = $pdo->prepare("SELECT * FROM facturas WHERE compra_id=? ORDER BY created_at DESC");
    $facturas->execute([$id]); $facturas = $facturas->fetchAll();
?>
<div class="card" style="max-width:800px">
    <div class="card-header"><h2>Importar Factura - <?=h($compra['folio'])?></h2></div>
    <p><strong>Proveedor:</strong> <?=h($compra['proveedor'])?></p>

    <?php if ($facturas): ?>
    <h3 style="margin:16px 0 8px;">Facturas asociadas</h3>
    <table>
        <tr><th>Folio</th><th>UUID</th><th>RFC Emisor</th><th>Monto</th><th>Fecha</th></tr>
        <?php foreach ($facturas as $f): ?>
        <tr>
            <td><?=h($f['folio_factura'] ?: '-')?></td>
            <td><?=h($f['uuid'] ?: '-')?></td>
            <td><?=h($f['rfc_emisor'] ?: '-')?></td>
            <td><?=moneda($f['monto'])?></td>
            <td><?=$f['fecha_factura'] ?: '-'?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <hr style="margin:16px 0">
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group">
                <label>Archivo XML (CFDI)</label>
                <input type="file" name="xml" accept=".xml">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Folio factura</label>
                <input type="text" name="folio_factura" placeholder="O se auto-llena del XML">
            </div>
            <div class="form-group">
                <label>UUID (Folio Fiscal)</label>
                <input type="text" name="uuid" placeholder="O se extrae del XML">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>RFC Emisor</label>
                <input type="text" name="rfc_emisor" placeholder="O se extrae del XML">
            </div>
            <div class="form-group">
                <label>RFC Receptor</label>
                <input type="text" name="rfc_receptor" placeholder="O se extrae del XML">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Monto total</label>
                <input type="text" name="monto" placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Fecha factura</label>
                <input type="date" name="fecha_factura">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Guardar Factura</button>
            <a href="compras.php?action=detalle&id=<?=$id?>" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
    <p class="text-muted" style="font-size:0.85rem;margin-top:8px;">Si subes un XML CFDI, los campos se llenaran automaticamente. Puedes editarlos manualmente.</p>
</div>

<?php elseif ($action === 'detalle' && $id):
    $compra = $pdo->prepare("SELECT c.*, p.nombre as proveedor, a.nombre as almacen, e.nombre as asignado_nombre, e.apellido as asignado_ape FROM compras c JOIN proveedores p ON p.id=c.proveedor_id JOIN almacenes a ON a.id=c.almacen_id LEFT JOIN empleados e ON e.id=c.asignado_id WHERE c.id=?");
    $compra->execute([$id]); $compra = $compra->fetch();
    if (!$compra) { alert('danger', 'No encontrada'); redirect('compras.php'); }

    $detalles = $pdo->prepare("SELECT d.*, p.codigo, p.nombre as producto, um.codigo as umedida FROM compra_detalle d JOIN productos p ON p.id=d.producto_id JOIN unidades_medida um ON um.id=p.unidad_medida_id WHERE d.compra_id=?");
    $detalles->execute([$id]);
    $detalles_arr = $detalles->fetchAll();
    // Map de ubicaciones por producto
    $ubicaciones_map = [];
    if (!empty($detalles_arr)) {
        $pids = array_column($detalles_arr, 'producto_id');
        $ubis = $pdo->query("SELECT i.producto_id, i.cantidad, u.codigo as ubicacion, u.id as ubicacion_id, a.nombre as almacen_nom FROM inventario i JOIN ubicaciones u ON u.id=i.ubicacion_id JOIN almacenes a ON a.id=i.almacen_id WHERE i.producto_id IN (".implode(',',$pids).") AND i.cantidad>0 ORDER BY i.producto_id, u.codigo");
        foreach ($ubis as $u) $ubicaciones_map[$u['producto_id']][] = $u;
    }
?>
<div class="card">
    <div class="card-header"><h2>Compra: <?=h($compra['folio'])?></h2>
        <span class="badge badge-<?=$compra['estatus']==='recibida'?'success':($compra['estatus']==='cancelada'?'danger':'warning')?>"><?=h($compra['estatus'])?></span>
    </div>
    <table>
        <tr><td><strong>Proveedor:</strong> <?=h($compra['proveedor'])?></td>
            <td><strong>Almacen:</strong> <?=h($compra['almacen'])?></td></tr>
        <tr><td><strong>Fecha:</strong> <?=$compra['fecha_compra']?></td>
            <td><strong>Entrega:</strong> <?=$compra['fecha_entrega'] ?? 'Pendiente'?></td></tr>
        <tr><td><strong>Forma pago:</strong> <?=h($compra['forma_pago'] ?? '-')?></td>
            <td><strong>Total:</strong> <?=moneda($compra['total'])?></td></tr>
        <?php if ($compra['asignado_nombre']): ?>
        <tr><td colspan="2"><strong>Asignado a:</strong> <?=h($compra['asignado_nombre'] . ' ' . $compra['asignado_ape'])?></td></tr>
        <?php endif; ?>
    </table>
    <?php if ($compra['observaciones']): ?>
    <p><strong>Observaciones:</strong> <?=h($compra['observaciones'])?></p>
    <?php endif; ?>
    <h3 style="margin:16px 0 8px;">Detalle</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Codigo</th><th>Producto</th><th>Cantidad</th><th>Recibido</th><th>Precio</th><th>Subtotal</th><th>Estanteria</th></tr>
            <?php foreach ($detalles_arr as $d):
            $ubis = $ubicaciones_map[$d['producto_id']] ?? [];
            ?>
            <tr>
                <td><?=h($d['codigo'])?></td>
                <td><?=h($d['producto'])?></td>
                <td><?=h($d['cantidad'])?> <?=h($d['umedida'])?></td>
                <td><?=h($d['cantidad_recibida'])?></td>
                <td><?=moneda($d['precio_unitario'])?></td>
                <td><?=moneda($d['cantidad']*$d['precio_unitario'])?></td>
                <td style="font-size:.8rem">
                    <?php if ($ubis): foreach (array_slice($ubis,0,3) as $u): ?>
                    <span style="display:inline-block;background:#eef2f7;padding:1px 6px;border-radius:3px;margin:1px"><?=h($u['ubicacion'])?> <small style="color:#888"><?=number_format($u['cantidad'])?></small></span>
                    <?php endforeach; if (count($ubis)>3): ?><span style="color:#888">+<?=(count($ubis)-3)?> mas</span><?php endif; else: ?>
                    <span style="color:#999">Sin ubicacion</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php $facturas = $pdo->prepare("SELECT * FROM facturas WHERE compra_id=? ORDER BY created_at DESC");
    $facturas->execute([$id]); $facturas = $facturas->fetchAll(); ?>
    <?php if ($facturas): ?>
    <h3 style="margin:16px 0 8px;">Facturas</h3>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>UUID</th><th>RFC</th><th>Monto</th><th>Fecha</th></tr>
            <?php foreach ($facturas as $f): ?>
            <tr>
                <td><?=h($f['folio_factura'] ?: '-')?></td>
                <td style="font-size:0.8rem"><?=h($f['uuid'] ?: '-')?></td>
                <td><?=h($f['rfc_emisor'] ?: '-')?></td>
                <td><?=moneda($f['monto'])?></td>
                <td><?=$f['fecha_factura'] ?: '-'?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="form-actions" style="margin-top:16px;">
        <?php if ($compra['estatus'] === 'pendiente'): ?>
        <a href="compras.php?recibir=1&id=<?=$compra['id']?>" class="btn btn-success" onclick="return confirm('¿Recibir todos los productos en inventario?')"> Recibir todo</a>
        <a href="compras.php?action=cancelar&id=<?=$compra['id']?>" class="btn btn-danger" onclick="return confirm('¿Cancelar compra?')">Cancelar</a>
        <?php endif; ?>
        <a href="compras.php?action=importar_factura&id=<?=$compra['id']?>" class="btn btn-primary"> Importar Factura</a>
        <a href="compras.php" class="btn btn-secondary"><- Volver</a>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2>Compras</h2>
        <a href="compras.php?action=create" class="btn btn-primary">+ Nueva</a>
    </div>
    <form class="search-bar" method="get">
        <input type="text" name="search" placeholder="Buscar folio o proveedor..." value="<?=h($search)?>">
        <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    </form>
    <div class="table-wrapper">
        <table>
            <tr><th>Folio</th><th>Proveedor</th><th>Almacen</th><th>Total</th><th>Forma pago</th><th>Estatus</th><th>Fecha</th><th>Acciones</th></tr>
            <?php foreach ($compras as $c): ?>
            <tr>
                <td><a href="compras.php?action=detalle&id=<?=$c['id']?>"><?=h($c['folio'])?></a></td>
                <td><?=h($c['proveedor'])?></td>
                <td><?=h($c['almacen'])?></td>
                <td><?=moneda($c['total'])?></td>
                <td><?=h($c['forma_pago'] ?? '-')?></td>
                <td><span class="badge badge-<?=$c['estatus']==='recibida'?'success':($c['estatus']==='cancelada'?'danger':'warning')?>"><?=h($c['estatus'])?></span></td>
                <td><?=$c['created_at']?></td>
                <td><a href="compras.php?action=detalle&id=<?=$c['id']?>" class="btn btn-sm btn-info">Detalle</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
