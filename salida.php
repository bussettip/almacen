<?php
$titulo = 'Salida de Inventario';
require 'includes/auth.php';
verificarPermiso(basename(__FILE__, '.php'));
$producto_id_preselect = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $folio = 'SAL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $a_id = (int)$_POST['almacen_id'];
        $producto_id = (int)$_POST['producto_id'];
        $ubicacion_id = !empty($_POST['ubicacion_id']) ? (int)$_POST['ubicacion_id'] : null;
        $lote = trim($_POST['lote'] ?? '');
        $cantidad = str_replace(',','', $_POST['cantidad']);
        $referencia = trim($_POST['referencia'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');

        // Verificar stock suficiente
        $inv = $pdo->prepare("SELECT id, cantidad FROM inventario WHERE almacen_id=? AND producto_id=? AND ubicacion_id<=>? AND lote<=>?");
        $inv->execute([$a_id, $producto_id, $ubicacion_id, $lote ?: null]);
        $existente = $inv->fetch();

        if (!$existente || $existente['cantidad'] < $cantidad) {
            throw new Exception("Stock insuficiente. Disponible: " . ($existente['cantidad'] ?? 0));
        }

        // Obtener tipo_movimiento para salida
        $tm = $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo='SALIDA'")->fetch();

        // Registrar movimiento
        $stmt = $pdo->prepare("INSERT INTO movimientos (tipo_movimiento_id, folio, almacen_origen_id, producto_id, ubicacion_id, lote, cantidad, referencia, observaciones, usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$tm['id'], $folio, $a_id, $producto_id, $ubicacion_id, $lote ?: null, $cantidad, $referencia, $observaciones, $_SESSION['usuario_id']]);

        // Actualizar inventario
        $pdo->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=?")->execute([$cantidad, $existente['id']]);

        $pdo->commit();
        alert('success', "Salida registrada. Folio: $folio");
        redirect('movimientos.php');

    } catch (Exception $e) {
        $pdo->rollBack();
        alert('danger', 'Error: ' . $e->getMessage());
    }
}

$almacenes = $pdo->query("SELECT * FROM almacenes WHERE activo=1 ORDER BY nombre")->fetchAll();
$productos = $pdo->query("SELECT id, codigo, nombre FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll();

require 'includes/header.php';
?>
<div class="card" style="max-width: 700px;">
    <div class="card-header"><h2>📤 Registrar Salida</h2></div>
    <form method="post" onsubmit="return confirm('¿Confirmar salida de inventario?')">
        <div class="form-row">
            <div class="form-group">
                <label>Almacen *</label>
                <select name="almacen_id" required id="selAlmacen" onchange="cargarUbicaciones()">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($almacenes as $a): ?>
                    <option value="<?=$a['id']?>"><?=h($a['nombre'])?></option>
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
                <label>Ubicacion (opcional)</label>
                <select name="ubicacion_id" id="selUbicacion">
                    <option value="">-- Sin ubicacion --</option>
                </select>
            </div>
            <div class="form-group">
                <label>Cantidad *</label>
                <input type="number" step="0.01" name="cantidad" required>
            </div>
        </div>
        <div class="form-group">
            <label>Lote (opcional)</label>
            <input type="text" name="lote">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Referencia</label>
                <input type="text" name="referencia" placeholder="Cliente, orden, etc.">
            </div>
        </div>
        <div class="form-group">
            <label>Observaciones</label>
            <textarea name="observaciones" rows="2"></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">📤 Registrar Salida</button>
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
</script>

<?php require 'includes/footer.php'; ?>
