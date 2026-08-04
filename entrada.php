<?php
$titulo = 'Entrada de Inventario';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));

$almacen_id = isset($_GET['almacen_id']) ? (int)$_GET['almacen_id'] : 0;
$producto_id_preselect = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $folio = 'ENT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $a_id = (int)$_POST['almacen_id'];
        $producto_id = (int)$_POST['producto_id'];
        $ubicacion_id = !empty($_POST['ubicacion_id']) ? (int)$_POST['ubicacion_id'] : null;
        $lote = trim($_POST['lote'] ?? '');
        $fecha_caducidad = !empty($_POST['fecha_caducidad']) ? $_POST['fecha_caducidad'] : null;
        $cantidad = str_replace(',','', $_POST['cantidad']);
        $costo_unitario = !empty($_POST['costo_unitario']) ? str_replace(',','', $_POST['costo_unitario']) : null;
        $referencia = trim($_POST['referencia'] ?? '');
        $proveedor_id = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;
        $observaciones = trim($_POST['observaciones'] ?? '');

        // Obtener tipo_movimiento para entrada
        $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='ENTRADA'")->fetch();

        // Registrar movimiento
        $stmt = $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id, folio, almacen_destino_id, producto_id, ubicacion_id, lote, cantidad, costo_unitario, referencia, proveedor_id, observaciones, usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$tm['id'], $folio, $a_id, $producto_id, $ubicacion_id, $lote ?: null, $cantidad, $costo_unitario, $referencia, $proveedor_id, $observaciones, $_SESSION['usuario_id']]);

        // Actualizar / insertar inventario
        $inv = $pdo->prepare("SELECT id, cantidad FROM inventario WHERE almacen_id=? AND producto_id=? AND ubicacion_id<=>? AND lote<=>?");
        $inv->execute([$a_id, $producto_id, $ubicacion_id, $lote ?: null]);
        $existente = $inv->fetch();

        if ($existente) {
            $pdo->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?")->execute([$cantidad, $existente['id']]);
            if ($fecha_caducidad) $pdo->prepare("UPDATE inventario SET fecha_caducidad=? WHERE id=?")->execute([$fecha_caducidad, $existente['id']]);
        } else {
            $pdo->prepare("INSERT INTO inventario (almacen_id, ubicacion_id, producto_id, lote, fecha_caducidad, cantidad) VALUES (?,?,?,?,?,?)")
                 ->execute([$a_id, $ubicacion_id, $producto_id, $lote ?: null, $fecha_caducidad, $cantidad]);
        }

        $pdo->commit();
        alert('success', "Entrada registrada. Folio: $folio");
        redirect('movimientos.php');

    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error al registrar entrada: ' . $e->getMessage());
    }
}

$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();
$proveedores = $pdo->query("SELECT id, nombre, rfc FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();

// Para carga dinamica de ubicaciones y productos se podria usar AJAX,
// pero lo simplificamos cargando todo en selects.

require 'includes/header.php';
?>
<div class="card" style="max-width: 700px;">
    <div class="card-header"><h2> Registrar Entrada</h2></div>
    <form method="post" onsubmit="return confirm('¿Confirmar entrada de inventario?')">
        <div class="form-row">
            <div class="form-group">
                <label>Almacen *</label>
                <select name="almacen_id" required id="selAlmacen" onchange="cargarUbicaciones()">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($almacenes as $a): ?>
                    <option value="<?=$a['id']?>" <?=$almacen_id==$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Producto *</label>
                <select name="producto_id" required id="selProducto">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($productos as $p): ?>
                    <option value="<?=$p['id']?>" <?=$producto_id_preselect==$p['id']?'selected':''?>><?=h($p['codigo'])?> - <?=h($p['nombre'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Ubicacion</label>
                <select name="ubicacion_id" id="selUbicacion">
                    <option value="">-- Sin ubicacion --</option>
                </select>
            </div>
            <div class="form-group">
                <label>Cantidad *</label>
                <input type="number" step="0.01" name="cantidad" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Lote</label>
                <input type="text" name="lote" placeholder="Opcional">
            </div>
            <div class="form-group">
                <label>Fecha de caducidad</label>
                <input type="date" name="fecha_caducidad">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Costo unitario</label>
                <input type="number" step="0.01" name="costo_unitario" placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Referencia (factura/orden)</label>
                <input type="text" name="referencia" placeholder="Opcional">
            </div>
            <div class="form-group">
                <label>Proveedor</label>
                <select name="proveedor_id">
                    <option value="">-- Sin proveedor --</option>
                    <?php foreach ($proveedores as $pr): ?>
                    <option value="<?=$pr['id']?>"><?=h($pr['nombre'])?> <?=$pr['rfc']?'('.h($pr['rfc']).')':''?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Observaciones</label>
            <textarea name="observaciones" rows="2"></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-success">[OK] Registrar Entrada</button>
            <a href="movimientos.php" class="btn btn-secondary">Ver movimientos</a>
        </div>
    </form>
</div>

<script>
const ubicaciones = <?=json_encode($pdo->query("SELECT u.id, u.codigo, u.almacen_id FROM ubicaciones u WHERE u.activo=1 ORDER BY u.codigo")->fetchAll())?>;

function cargarUbicaciones() {
    const almacenId = document.getElementById('selAlmacen').value;
    const sel = document.getElementById('selUbicacion');
    sel.innerHTML = '<option value="">-- Sin ubicacion --</option>';
    ubicaciones.filter(u => u.almacen_id == almacenId).forEach(u => {
        sel.innerHTML += `<option value="${u.id}">${u.codigo}</option>`;
    });
}
<?php if ($almacen_id): ?>document.addEventListener('DOMContentLoaded', cargarUbicaciones);<?php endif; ?>
</script>

<?php require 'includes/footer.php'; ?>
